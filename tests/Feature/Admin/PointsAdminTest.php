<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Points\Models\PointEntry;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面のポイント（ADR 0068 の 6・7）。お客さんの 1 人の画面の残高と帳簿は閲覧から、手で足す・引くはお金の段階で直前の再確認の後。
 * 設定 › ポイントは読むのは閲覧、変えるのは運用から。まだ使われていないポイントの合計（店の負債）を設定と売上に出す
 */
final class PointsAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('pta');
        $this->tenant('ptb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_an_owner_adds_and_takes_points_after_reconfirming_and_it_is_audited(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $member = $this->member('pta', 'hanako@example.com');
        $url = "/api/tenants/pta/customers/{$member}/points";

        // 再確認が先
        $this->send('POST', $url, ['points' => 500, 'reason' => 'お詫び'])->assertStatus(423);
        $this->reconfirm($operator, $authenticator);
        $this->send('POST', $url, ['points' => 500, 'reason' => '山田さんへのお詫び'])->assertCreated()->assertJsonPath('balance', 500);
        // 残高より多くは引けない（422）、0・理由なし・長すぎる理由も断る
        $this->send('POST', $url, ['points' => -501, 'reason' => '直し'])->assertStatus(422);
        $this->send('POST', $url, ['points' => 0, 'reason' => '直し'])->assertStatus(422)->assertJsonValidationErrors('points');
        $this->send('POST', $url, ['points' => 10])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->send('POST', $url, ['points' => 10, 'reason' => str_repeat('あ', 201)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->send('POST', $url, ['points' => -200, 'reason' => '入力の誤り'])->assertCreated()->assertJsonPath('balance', 300);

        $show = $this->send('GET', $url)->assertOk();
        $show->assertJsonPath('points.balance', 300)->assertJsonPath('points.can_adjust', true)->assertJsonCount(2, 'points.history');
        $show->assertJsonPath('points.history.0.kind', 'adjust')->assertJsonPath('points.history.0.points', -200)->assertJsonPath('points.history.1.reason', '山田さんへのお詫び');

        // 中央の監査ログには数だけ（理由は残さない）
        $audit = AuditEvent::query()->where('type', 'operator.points_adjusted')->orderBy('sequence')->get();
        $this->assertSame([500, -200], $audit->map(static fn (AuditEvent $e): int => $e->data['points'])->all());
        $this->assertStringNotContainsString('お詫び', (string) json_encode($audit->pluck('data')->all(), JSON_UNESCAPED_UNICODE));
        $this->inside('pta', function (): void {
            $this->assertSame(2, DomainEvent::query()->where('type', 'points.adjusted')->count());
            $this->assertStringStartsWith('operator:', (string) PointEntry::query()->value('actor'));
        });

        // ほかの店からは見えない・足せない
        $this->send('GET', "/api/tenants/ptb/customers/{$member}/points")->assertNotFound();
        $this->send('POST', "/api/tenants/ptb/customers/{$member}/points", ['points' => 10, 'reason' => 'x'])->assertNotFound();
    }

    public function test_read_and_operate_staff_see_the_balance_but_cannot_adjust(): void
    {
        $member = $this->member('pta', 'hanako@example.com');
        $this->inside('pta', fn () => app(PointService::class)->adjust(CustomerAccount::query()->findOrFail($member), 100, '山田さんへのお詫び', 'test'));
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $url = "/api/tenants/pta/customers/{$member}/points";

        // 閲覧: 数は見えるが、店の人の理由（自由な文）は見えない。足せない
        $access->grant($staff, 'pta', 'read', 'test');
        $this->send('GET', $url)->assertOk()->assertJsonPath('points.balance', 100)->assertJsonPath('points.history.0.reason', null)->assertJsonPath('points.can_adjust', false);
        $this->send('POST', $url, ['points' => 10, 'reason' => 'x'])->assertForbidden();

        // 運用: 理由は見えるが、足せない（再確認をしても）
        $access->grant($staff, 'pta', 'operate', 'test');
        $this->reconfirm($staff, $authenticator);
        $this->send('GET', $url)->assertOk()->assertJsonPath('points.history.0.reason', '山田さんへのお詫び');
        $this->send('POST', $url, ['points' => 10, 'reason' => 'x'])->assertForbidden();

        $this->inside('pta', fn () => $this->assertSame(100, app(PointService::class)->balance($member)));
    }

    public function test_guests_points_cannot_be_adjusted(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $guest = $this->inside('pta', fn (): string => app(CustomerAccounts::class)->forEmail('guest@example.com', 'test')->id);
        $this->reconfirm($operator, $authenticator);

        $this->send('POST', "/api/tenants/pta/customers/{$guest}/points", ['points' => 10, 'reason' => 'x'])->assertStatus(422);
        $this->send('GET', "/api/tenants/pta/customers/{$guest}/points")->assertOk()->assertJsonPath('points.member', false);
    }

    public function test_the_settings_are_read_by_everyone_changed_from_operate_and_show_the_outstanding_points(): void
    {
        $this->enrolledOperator();
        $member = $this->member('pta', 'hanako@example.com');
        $this->inside('pta', fn () => app(PointService::class)->adjust(CustomerAccount::query()->findOrFail($member), 1234, 'テスト', 'test'));

        $this->send('GET', '/api/tenants/pta/shop-settings/points')->assertOk()
            ->assertJsonPath('points.enabled', true)->assertJsonPath('points.rate_bp', 100)
            ->assertJsonPath('points.outstanding', 1234)->assertJsonPath('points.holders', 1);
        $this->send('PUT', '/api/tenants/pta/shop-settings/points', ['enabled' => true, 'rate_bp' => 250])->assertOk()->assertJsonPath('points.rate_bp', 250);
        // 20% を超える・0.1% きざみでない率は断る
        $this->send('PUT', '/api/tenants/pta/shop-settings/points', ['enabled' => true, 'rate_bp' => 2010])->assertStatus(422)->assertJsonValidationErrors('rate_bp');
        $this->send('PUT', '/api/tenants/pta/shop-settings/points', ['enabled' => true, 'rate_bp' => 105])->assertStatus(422)->assertJsonValidationErrors('rate_bp');
        $this->assertSame(['enabled' => true, 'rate_bp' => 250], AuditEvent::query()->where('type', 'operator.points_settings_changed')->sole()->data);
        $this->inside('pta', fn () => $this->assertSame(250, app(PointSettings::class)->rateBp()));

        // 売上の画面にも、まだ使われていないポイント（今の数）
        $this->send('GET', '/api/tenants/pta/sales')->assertOk()->assertJsonPath('points.outstanding', 1234)->assertJsonPath('totals.points_used', 0);

        // 閲覧の人は読めるが変えられない（ログインし直す）
        $this->send('POST', '/auth/logout')->assertNoContent();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'pta', 'read', 'test');
        $this->send('GET', '/api/tenants/pta/shop-settings/points')->assertOk()->assertJsonPath('points.rate_bp', 250)->assertJsonPath('points.outstanding', null)->assertJsonPath('points.holders', null);
        $this->send('PUT', '/api/tenants/pta/shop-settings/points', ['enabled' => false, 'rate_bp' => 100])->assertForbidden();
        $this->inside('pta', fn () => $this->assertTrue(app(PointSettings::class)->enabled()));
    }

    private function member(string $shop, string $email): string
    {
        return $this->inside($shop, function () use ($email): string {
            $account = app(CustomerAccounts::class)->forEmail($email, 'test');
            CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);

            return $account->id;
        });
    }
}
