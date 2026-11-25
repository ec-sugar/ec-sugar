<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as Router;
use LogicException;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 運営の役割と店ごとの権限（ADR 0024）。スタッフは与えた店と段階だけ、与えていない店は 404、段階が足りなければ 403。
 * お金の操作の経路には money の印があることを機械的に確かめる。最後の有効なオーナーは外せない。
 */
final class OperatorAccessTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    /** お金が動く・お金の設定を変える経路（money の印が要る） */
    private const array MONEY_ROUTES = [
        'PUT api/tenants/{tenant}/payment-settings/stripe',
        'DELETE api/tenants/{tenant}/payment-settings/stripe',
        // 公開の前に試すテスト用の鍵（ADR 0096）
        'PUT api/tenants/{tenant}/payment-settings/stripe-test',
        'DELETE api/tenants/{tenant}/payment-settings/stripe-test',
        // 使う支払いの手段（ADR 0058）
        'PUT api/tenants/{tenant}/payment-settings/methods',
        // 売上の集計と CSV（ADR 0060。お金の数を見るのはお金の段階だけ）
        'GET api/tenants/{tenant}/sales',
        'GET api/tenants/{tenant}/sales/csv',
        'POST api/tenants/{tenant}/payment-settings/stripe-connect',
        'DELETE api/tenants/{tenant}/payment-settings/stripe-connect',
        'POST api/tenants/{tenant}/payment-settings/stripe-connect/refresh',
        'POST api/tenants/{tenant}/transactions/{transactionId}/cancel',
        'POST api/tenants/{tenant}/transactions/{transactionId}/refunds/{refundId}/resolve',
        // 断られた贈り物の送料の差額の返金を頼み直す（ADR 0064）
        'POST api/tenants/{tenant}/transactions/{transactionId}/shipping-refund/retry',
        'POST api/tenants/{tenant}/transactions/{transactionId}/stray-payments/{strayId}/refund',
        // 銀行振込・代金引換（ADR 0074）: 振込先の設定・入金の確認・受取拒否・外で返した返金の記録
        'PUT api/tenants/{tenant}/payment-settings/direct',
        'POST api/tenants/{tenant}/transactions/{transactionId}/direct-payment/confirm',
        'POST api/tenants/{tenant}/transactions/{transactionId}/cod/refuse',
        'POST api/tenants/{tenant}/transactions/{transactionId}/direct-refunds',
        // 返品・一部返金の確かめと実行、断られた返金のやり直し（ADR 0048）
        'POST api/tenants/{tenant}/transactions/{transactionId}/returns/preview',
        'POST api/tenants/{tenant}/transactions/{transactionId}/returns',
        'POST api/tenants/{tenant}/transactions/{transactionId}/refunds/{refundId}/retry',
        // 店の人の手でポイントを足す・引く（ADR 0068）
        'POST api/tenants/{tenant}/customers/{customerId}/points',
        // 店の人の手でギフトカードの残高を足す・引く（ADR 0076）
        'POST api/tenants/{tenant}/gift-cards/{cardId}/adjust',
        // 使ったギフトカードの額を店の人がカードへ戻す（ADR 0076）
        'POST api/tenants/{tenant}/transactions/{transactionId}/gift-card/release',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('oaa');
        $this->tenant('oab');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_staff_reach_only_the_shops_and_levels_they_were_given(): void
    {
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $fake = '01aaaaaaaaaaaaaaaaaaaaaaaa';

        // 何も与えていない: 店の一覧は空、店には入れない（あるかどうかも見せない）
        $this->send('GET', '/api/tenants')->assertOk()->assertJsonPath('tenants', []);
        $this->send('GET', '/api/tenants/oaa/overview')->assertNotFound();
        $this->assertSame('not_granted', AuditEvent::query()->where('type', 'operator.tenant_denied')->where('operator_id', $staff->id)->latest('sequence')->firstOrFail()->data['reason']);

        // 閲覧: 読めるが、変えられない・個人情報は見られない
        $access->grant($staff, 'oaa', 'read', 'test');
        $this->send('GET', '/api/tenants')->assertJsonPath('tenants.0.id', 'oaa')->assertJsonPath('tenants.0.level', 'read')->assertJsonCount(1, 'tenants');
        $this->send('GET', '/api/tenants/oaa/listings')->assertOk();
        $this->send('POST', '/api/tenants/oaa/listings', ['title' => 'x'])->assertForbidden();
        $this->send('GET', "/api/tenants/oaa/transactions/{$fake}/personal")->assertForbidden();
        // 送料の表（ADR 0064）は閲覧で読めるが変えられない
        $method = $this->send('GET', '/api/tenants/oaa/shipping-methods')->assertOk()->json('methods.0');
        $table = ['methods' => [['id' => $method['id'], 'name' => '通常便', 'free_over' => null, 'rates' => array_map(static fn (): int => 550, $method['rates'])]]];
        $this->send('PUT', '/api/tenants/oaa/shipping-methods', $table)->assertForbidden();
        $denied = AuditEvent::query()->where('type', 'operator.tenant_denied')->where('operator_id', $staff->id)->latest('sequence')->firstOrFail();
        $this->assertSame(['insufficient_level', 'operate'], [$denied->data['reason'], $denied->data['required']]);
        // 別の店には入れない
        $this->send('GET', '/api/tenants/oab/listings')->assertNotFound();

        // 運用: 変えられるが、お金の操作はできない
        $access->grant($staff, 'oaa', 'operate', 'test');
        $this->send('PUT', '/api/tenants/oaa/shipping-methods', $table)->assertOk();
        $this->send('GET', "/api/tenants/oaa/transactions/{$fake}/personal")->assertNotFound();
        $this->send('POST', "/api/tenants/oaa/transactions/{$fake}/cancel")->assertForbidden();
        $this->send('PUT', '/api/tenants/oaa/payment-settings/stripe', [])->assertForbidden();
        $this->send('POST', '/api/tenants/oaa/payment-settings/stripe-connect/refresh')->assertForbidden();

        // お金: お金の操作に届く（その先は直前の再確認）
        $access->grant($staff, 'oaa', 'money', 'test');
        $this->send('POST', "/api/tenants/oaa/transactions/{$fake}/cancel")->assertStatus(423);
        $this->send('PUT', '/api/tenants/oaa/payment-settings/stripe', [])->assertStatus(423);

        // 外すと、また入れない。変えたことは監査ログに
        $access->grant($staff, 'oaa', null, 'test');
        $this->send('GET', '/api/tenants/oaa/overview')->assertNotFound();
        // （監査ログは追記だけでテストの間も残るので、この運営の分だけを見る）
        $changes = AuditEvent::query()->where('type', 'operator.grant_changed')->orderBy('sequence')->get()->filter(fn (AuditEvent $e): bool => $e->data['operator'] === $staff->id);
        $this->assertSame(['read', 'operate', 'money', null], $changes->map(fn (AuditEvent $e) => $e->data['to'])->values()->all());
    }

    public function test_owners_reach_every_shop_and_the_last_owner_stays(): void
    {
        [$owner] = $this->enrolledOperator('owner@example.com');
        $this->send('GET', '/api/tenants')->assertJsonCount(2, 'tenants')->assertJsonPath('tenants.0.level', 'money');
        $this->send('POST', '/api/tenants/oab/transactions/01aaaaaaaaaaaaaaaaaaaaaaaa/cancel')->assertStatus(423);

        // 最後の有効なオーナーは外せない・止められない（コマンドでも）
        $this->artisan('operator', ['action' => 'role', 'email' => 'owner@example.com', 'value' => 'staff'])->expectsOutputToContain('最後の有効なオーナー')->assertFailed();
        $this->artisan('operator', ['action' => 'disable', 'email' => 'owner@example.com'])->expectsOutputToContain('最後の有効なオーナー')->assertFailed();
        $this->assertSame('owner', $owner->fresh()?->role);

        // オーナーを残す確かめは、トランザクションの外から呼ぶと止まる（ロックがすぐ外れて 2 人同時に止めると 0 人になるため）
        try {
            app(OperatorAccess::class)->keepAnOwner($owner);
            $this->fail('トランザクションの外でオーナーを残す確認が通った');
        } catch (LogicException $e) {
            // 「最後のオーナー」の断り（DomainException も LogicException の仲間）ではなく、トランザクションの外の断りであること
            $this->assertStringContainsString('トランザクションの中で', $e->getMessage());
        }

        // もう 1 人オーナーを作れば外せる
        Operator::create(['name' => '二人目', 'email' => 'second@example.com']);
        $this->artisan('operator', ['action' => 'role', 'email' => 'second@example.com', 'value' => 'owner'])->assertSuccessful();
        $this->artisan('operator', ['action' => 'role', 'email' => 'owner@example.com', 'value' => 'staff'])->assertSuccessful();
        // テストの中ではアプリがログイン中の運営をメモリに覚えたままなので、忘れさせる（本番は毎回読み直す）
        $this->app['auth']->forgetGuards();
        $this->send('GET', '/api/tenants')->assertJsonPath('tenants', []);

        // 店の権限: 知らない店・知らない段階は断る
        $this->artisan('operator', ['action' => 'grant', 'email' => 'owner@example.com', 'value' => 'nope', 'level' => 'read'])->expectsOutputToContain('テナントが見つかりません')->assertFailed();
        $this->artisan('operator', ['action' => 'grant', 'email' => 'owner@example.com', 'value' => 'oab', 'level' => 'admin'])->assertFailed();
        $this->artisan('operator', ['action' => 'grant', 'email' => 'owner@example.com', 'value' => 'oab', 'level' => 'operate'])->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->send('GET', '/api/tenants')->assertJsonPath('tenants.0.id', 'oab')->assertJsonPath('tenants.0.level', 'operate');
        $this->assertSame(2, AuditEvent::query()->where('type', 'operator.role_changed')->get()->filter(fn (AuditEvent $e): bool => in_array($e->data['operator'], [$owner->id, Operator::query()->whereEmail('second@example.com')->value('id')], true))->count());

        // 新しく作った運営はスタッフ（どの店にも入れない）
        $this->assertSame('staff', Operator::create(['name' => '三人目', 'email' => 'third@example.com'])->fresh()?->role);
    }

    public function test_every_money_route_carries_the_money_mark(): void
    {
        $marked = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! in_array(EnterTenant::class, $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $name = "{$method} {$route->uri()}";
                $request = Request::create('/', $method);

                if (EnterTenant::requiredLevel($request, $route) === 'money') {
                    $marked[] = $name;
                }

                // お金の言葉が付いた変更の経路は、money の印が無ければ落とす（付け忘れ）
                if ($method !== 'GET' && preg_match('#/(payment-settings|cancel|refund)#', $route->uri()) === 1) {
                    $this->assertSame('money', EnterTenant::requiredLevel($request, $route), $name);
                }
            }
        }

        sort($marked);
        $expected = self::MONEY_ROUTES;
        sort($expected);
        $this->assertSame($expected, $marked);
    }
}
