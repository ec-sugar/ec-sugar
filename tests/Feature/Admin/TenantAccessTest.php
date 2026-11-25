<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Http\Middleware\EnsureOperatorIsActive;
use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\TenantActor;
use App\Admin\Models\AuditEvent;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Support\OperatorSession;
use Tests\TestCase;

/**
 * 運営がテナントに立ち入る経路（ADR 0014）。入口は /api/tenants/{tenant}/… の EnterTenant だけ。
 */
final class TenantAccessTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    /** @var list<Tenant> */
    private array $tenants = [];

    private int $auditBaseline = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->auditBaseline = (int) AuditEvent::query()->max('sequence');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->tenants as $tenant) {
            $tenant->delete();
        }

        parent::tearDown();
    }

    public function test_the_tenant_list_needs_a_signed_in_operator_and_caps_the_page_size(): void
    {
        $this->tenant('tla', 2);
        $this->tenant('tlb', 0);

        $this->send('GET', '/api/tenants')->assertStatus(401);

        $this->enrolledOperator();
        $list = $this->send('GET', '/api/tenants')->assertOk();
        // 新しい店から
        $this->assertSame(['tlb', 'tla'], $list->json('tenants.*.id'));
        $this->assertSame(['tla.localhost'], $list->json('tenants.1.domains'));

        $this->send('GET', '/api/tenants?per_page=1')->assertOk()->assertJsonCount(1, 'tenants')->assertJsonPath('meta.total', 2);
        $this->send('GET', '/api/tenants?per_page=101')->assertStatus(422);
        $this->send('GET', '/api/tenants?per_page=0')->assertStatus(422);
    }

    public function test_entering_a_tenant_shows_only_its_data_and_leaves_no_context_behind(): void
    {
        $this->tenant('tea', 2);
        $this->tenant('teb', 1);
        [$operator] = $this->enrolledOperator();

        $this->send('GET', '/api/tenants/tea/overview')->assertOk()
            ->assertJsonPath('tenant.id', 'tea')
            ->assertJsonPath('counts.listings', 2);
        $this->assertFalse(tenancy()->initialized);

        $this->send('GET', '/api/tenants/teb/overview')->assertOk()
            ->assertJsonPath('tenant.id', 'teb')
            ->assertJsonPath('counts.listings', 1);
        $this->assertFalse(tenancy()->initialized);

        $this->send('GET', '/api/tenants/tea/events/verify')->assertOk()->assertJsonPath('intact', true);

        // 立ち入りは 1 リクエストごとに中央の監査ログへ（誰が・どこで・何を・結果）
        $requests = AuditEvent::query()->where('sequence', '>', $this->auditBaseline)->where('type', 'operator.tenant_request')->orderBy('sequence')->get();
        $this->assertSame(['tea', 'teb', 'tea'], $requests->pluck('tenant_id')->all());
        $this->assertSame([$operator->id], $requests->pluck('operator_id')->unique()->values()->all());
        $this->assertSame(['GET', 200], [$requests[0]->data['method'] ?? null, $requests[0]->data['status'] ?? null]);
        $this->assertSame('api/tenants/{tenant}/overview', $requests[0]->data['route'] ?? null);
    }

    public function test_unknown_or_malformed_tenants_are_not_found_and_recorded(): void
    {
        $this->tenant('tna', 0);
        $this->enrolledOperator();

        foreach (['nope', 'TNA', str_repeat('a', 64), 'tna..', '-tna'] as $id) {
            $this->send('GET', '/api/tenants/'.rawurlencode($id).'/overview')->assertNotFound();
            $this->assertFalse(tenancy()->initialized);
        }

        $denied = AuditEvent::query()->where('sequence', '>', $this->auditBaseline)->where('type', 'operator.tenant_denied')->count();
        $this->assertSame(5, $denied);
        $this->assertSame(0, AuditEvent::query()->where('sequence', '>', $this->auditBaseline)->where('type', 'operator.tenant_request')->count());
    }

    public function test_signed_out_and_disabled_operators_cannot_enter(): void
    {
        $this->tenant('tsa', 1);

        $this->send('GET', '/api/tenants/tsa/overview')->assertStatus(401);
        $this->assertFalse(tenancy()->initialized);

        [$operator] = $this->enrolledOperator();
        $this->send('GET', '/api/tenants/tsa/overview')->assertOk();

        $operator->update(['disabled_at' => CarbonImmutable::now()]);
        $this->send('GET', '/api/tenants/tsa/overview')->assertStatus(401);
        $this->assertFalse(tenancy()->initialized);

        // 止められた後の立ち入りは記録されない（入口の手前で止まる）
        $this->assertSame(1, AuditEvent::query()->where('sequence', '>', $this->auditBaseline)->where('type', 'operator.tenant_request')->count());
    }

    public function test_changes_carry_the_operator_as_actor_and_errors_still_close_the_context(): void
    {
        $this->tenant('tca', 0);
        $this->tenant('tcb', 0);
        $this->registerTestRoutes();
        [$operator] = $this->enrolledOperator();

        $this->send('POST', '/api/tenants/tca/_test/touch')->assertOk();
        $this->assertFalse(tenancy()->initialized);

        $this->inside('tca', function () use ($operator): void {
            $event = DomainEvent::query()->where('type', 'test.touched')->firstOrFail();
            $this->assertSame('operator:'.$operator->id, $event->actor);
        });
        $this->inside('tcb', function (): void {
            $this->assertSame(0, DomainEvent::query()->where('type', 'test.touched')->count());
        });

        // controller が例外を投げても、テナント文脈は閉じ、監査ログには 500 で残る
        $this->send('GET', '/api/tenants/tca/_test/boom')->assertStatus(500);
        $this->assertFalse(tenancy()->initialized);
        $last = AuditEvent::query()->where('type', 'operator.tenant_request')->orderByDesc('sequence')->firstOrFail();
        $this->assertSame(500, $last->data['status'] ?? null);
        $this->assertSame('tca', $last->tenant_id);

        // controller は経路の引数からテナント ID を受け取れない（tenant() だけを見る）
        $this->send('GET', '/api/tenants/tcb/_test/params')->assertOk()->assertJsonPath('params', [])->assertJsonPath('tenant', 'tcb');
    }

    public function test_the_actor_is_only_available_inside_the_tenant_entry(): void
    {
        $this->expectException(LogicException::class);
        TenantActor::of(Request::create('/'));
    }

    /**
     * EnterTenant を通る、テスト専用の経路（本番の経路には無い）。
     */
    private function registerTestRoutes(): void
    {
        Route::domain('admin.localhost')
            ->middleware(['admin', 'auth:operator', EnsureOperatorIsActive::class, EnterTenant::class])
            ->prefix('api/tenants/{tenant}/_test')
            ->group(function (): void {
                Route::post('/touch', static fn (Request $request) => response()->json([
                    'sequence' => app(EventStore::class)->append('test.touched', 'tenant', strtolower((string) Str::ulid()), [], TenantActor::of($request))->sequence,
                ]));
                Route::get('/boom', static fn () => throw new RuntimeException('boom'));
                Route::get('/params', static fn (Request $request) => response()->json([
                    'params' => $request->route()?->parameters() ?? [],
                    'tenant' => tenant('id'),
                ]));
            });
    }

    private function tenant(string $id, int $listings): Tenant
    {
        $tenant = Tenant::create(['id' => $id, 'name' => "テナント {$id}"]);
        $tenant->domains()->create(['domain' => "{$id}.localhost"]);
        $this->tenants[] = $tenant;

        $this->inside($id, function () use ($listings): void {
            $seller = Seller::query()->firstOrFail();

            for ($i = 0; $i < $listings; $i++) {
                Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => "商品 {$i}", 'unit_amount' => 1000, 'status' => ListingStatus::Active]);
            }
        });

        return $tenant;
    }

    /**
     * @param  Closure(): void  $check
     */
    private function inside(string $id, Closure $check): void
    {
        tenancy()->initialize(Tenant::query()->findOrFail($id));

        try {
            $check();
        } finally {
            tenancy()->end();
        }
    }
}
