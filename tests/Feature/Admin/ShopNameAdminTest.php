<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Sample\Demo\DemoShop;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店の名前を管理画面で変える。オーナーだけ・直前のパスキーの再確認の後。
 * 名前は中央の店の行だけにあり、ショップ・管理画面の一覧に次の要求から出る。中央の監査ログと店の記録に「変えた」ことだけを残す（名前そのものは残さない）
 */
final class ShopNameAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string NEW_NAME = '新しい名前の店';

    /** 監査の記録は追記だけで空にできないので、この場面の前の最後の番号から後だけを見る */
    private int $auditFrom = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->auditFrom = (int) AuditEvent::query()->max('sequence');
        $this->tenant('snm', '前の名前の店');
        $this->tenant('sno', 'となりの店');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_owner_renames_the_shop_and_it_shows_at_once_and_is_recorded_without_the_name(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();

        // 直前の再確認が無ければ 423 で、名前は変わらない
        $this->send('PUT', '/api/tenants/snm/shop-settings/name', ['name' => self::NEW_NAME])->assertStatus(423);
        $this->assertSame('前の名前の店', Tenant::query()->findOrFail('snm')->name);

        $this->reconfirm($owner, $authenticator);
        // 前後の空白（全角も）は除く
        $this->send('PUT', '/api/tenants/snm/shop-settings/name', ['name' => "\u{3000}".self::NEW_NAME.' '])->assertOk()
            ->assertJsonPath('tenant', ['id' => 'snm', 'name' => self::NEW_NAME])->assertJsonPath('changed', true);
        // 同じ名前なら何も書かない
        $this->send('PUT', '/api/tenants/snm/shop-settings/name', ['name' => self::NEW_NAME])->assertOk()->assertJsonPath('changed', false);

        $this->assertSame(self::NEW_NAME, Tenant::query()->findOrFail('snm')->name);
        $this->assertSame('となりの店', Tenant::query()->findOrFail('sno')->name);

        // 管理画面の店の一覧とショップの画面に、次の要求から新しい名前が出る
        $tenants = $this->send('GET', '/api/tenants')->assertOk()->json('tenants');
        $this->assertIsArray($tenants);
        $names = array_column($tenants, 'name', 'id');
        $this->assertSame(self::NEW_NAME, $names['snm'] ?? null);
        $this->assertSame('となりの店', $names['sno'] ?? null);
        $page = (string) $this->get('http://snm.localhost/')->assertOk()->getContent();
        $this->assertStringContainsString(self::NEW_NAME, $page);
        $this->assertStringNotContainsString('前の名前の店', $page);
        $this->assertStringContainsString('となりの店', (string) $this->get('http://sno.localhost/')->assertOk()->getContent());

        // 記録: 中央の監査ログに 1 件（誰が・どの店）、店の記録に 1 件（運営の actor）。どちらにも名前は入れない
        $audits = $this->renames()->get();
        $this->assertCount(1, $audits);
        $this->assertSame('snm', $audits[0]->tenant_id);
        $this->assertSame($owner->id, $audits[0]->operator_id);
        $this->assertSame(['by' => 'operator:'.$owner->id], $audits[0]->data);
        $this->assertStringNotContainsString('名前の店', (string) json_encode($audits->toArray(), JSON_UNESCAPED_UNICODE));

        $this->inside('snm', function () use ($owner): void {
            $events = DomainEvent::query()->where('type', 'shop.renamed')->get();
            $this->assertCount(1, $events);
            $this->assertSame('operator:'.$owner->id, $events[0]->actor);
            $this->assertSame([], $events[0]->payload);
        });
        $this->inside('sno', fn () => $this->assertSame(0, DomainEvent::query()->where('type', 'shop.renamed')->count()));
    }

    public function test_the_name_is_escaped_on_the_shop(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);

        $this->send('PUT', '/api/tenants/snm/shop-settings/name', ['name' => '<b>強い</b>&店'])->assertOk();

        $page = (string) $this->get('http://snm.localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('<b>強い</b>', $page);
        $this->assertStringContainsString('&lt;b&gt;強い&lt;/b&gt;&amp;店', $page);
    }

    public function test_bad_names_are_refused(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);

        foreach ([[], ['name' => ''], ['name' => "\u{3000} "], ['name' => str_repeat('店', 101)], ['name' => "前の\n名前"], ['name' => "前の\u{2028}名前"], ['name' => "店\u{0007}"], ['name' => "店\u{200B}店"], ['name' => ['店']], ['name' => 123]] as $body) {
            $this->send('PUT', '/api/tenants/snm/shop-settings/name', $body)->assertStatus(422)->assertJsonValidationErrors('name');
        }

        // 100 文字ちょうどは通る
        $this->send('PUT', '/api/tenants/snm/shop-settings/name', ['name' => str_repeat('店', 100)])->assertOk();
        $this->send('PUT', '/api/tenants/snm/shop-settings/name', ['name' => '前の名前の店'])->assertOk();

        $this->assertSame('前の名前の店', Tenant::query()->findOrFail('snm')->name);
        $this->assertSame(2, $this->renames()->count());
    }

    public function test_only_the_owner_renames(): void
    {
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'snm', 'money', 'test');
        $this->reconfirm($staff, $authenticator);

        // お金の段階のスタッフでも 403、与えていない店は 404
        $this->send('PUT', '/api/tenants/snm/shop-settings/name', ['name' => self::NEW_NAME])->assertForbidden();
        $this->send('PUT', '/api/tenants/sno/shop-settings/name', ['name' => self::NEW_NAME])->assertNotFound();

        $this->assertSame('前の名前の店', Tenant::query()->findOrFail('snm')->name);
        $this->assertSame('となりの店', Tenant::query()->findOrFail('sno')->name);
        $this->assertSame(0, $this->renames()->count());
    }

    public function test_a_demo_shop_is_not_renamed(): void
    {
        $demo = $this->tenant('demosnm', 'デモの店');
        $demo->setAttribute(DemoShop::FLAG, true);
        $demo->save();
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);

        $this->send('PUT', '/api/tenants/demosnm/shop-settings/name', ['name' => self::NEW_NAME])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame('デモの店', Tenant::query()->findOrFail('demosnm')->name);
        $this->assertSame(0, $this->renames()->count());
    }

    /**
     * @return Builder<AuditEvent>
     */
    private function renames(): Builder
    {
        return AuditEvent::query()->where('type', 'tenant.renamed')->where('sequence', '>', $this->auditFrom);
    }
}
