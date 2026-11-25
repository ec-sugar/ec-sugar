<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorEnrollment;
use App\Admin\Models\OperatorPasskey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * 入れたばかりのサーバーの最初の運営（ADR 0031）。
 * 有効なオーナーがいなければ、CLI で作った運営はオーナーになる（スタッフのままだと何も見えない管理画面に入ることになる）。
 * まだ誰もパスキーを持っていない最初の登録の URL は、DNS の反映を待てるように長く使える。2 人目からは今のまま。
 */
final class OperatorFirstRunTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.enrollment_minutes' => 30, 'admin.first_enrollment_minutes' => 1440]);
        CarbonImmutable::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_first_operator_becomes_an_owner_with_a_long_enrollment(): void
    {
        $this->artisan('operator', ['action' => 'create', 'email' => 'first@example.com', '--name' => '最初'])
            ->expectsOutputToContain('オーナー')
            ->expectsOutputToContain('24 時間')
            ->assertSuccessful();

        $operator = Operator::query()->whereEmail('first@example.com')->sole();
        self::assertSame('owner', $operator->role);
        self::assertEquals(CarbonImmutable::now()->addMinutes(1440), $this->expiry($operator));
        self::assertSame('owner', AuditEvent::query()->where('type', 'operator.created')->where('operator_id', $operator->id)->sole()->data['role']);
    }

    public function test_later_operators_are_staff_with_the_normal_enrollment(): void
    {
        $this->artisan('operator', ['action' => 'create', 'email' => 'first@example.com', '--name' => '最初'])->assertSuccessful();
        $this->givePasskey(Operator::query()->whereEmail('first@example.com')->sole());

        $this->artisan('operator', ['action' => 'create', 'email' => 'second@example.com', '--name' => '二人目'])
            ->expectsOutputToContain('30 分')
            ->assertSuccessful();

        $second = Operator::query()->whereEmail('second@example.com')->sole();
        self::assertSame('staff', $second->role);
        self::assertEquals(CarbonImmutable::now()->addMinutes(30), $this->expiry($second));
    }

    public function test_an_owner_without_a_passkey_yet_still_keeps_the_next_operator_staff(): void
    {
        // 最初のオーナーがまだ登録していない間に 2 人目を作っても、2 人目はオーナーにならない
        $this->artisan('operator', ['action' => 'create', 'email' => 'first@example.com', '--name' => '最初'])->assertSuccessful();
        $this->artisan('operator', ['action' => 'create', 'email' => 'second@example.com', '--name' => '二人目'])->assertSuccessful();

        self::assertSame('staff', Operator::query()->whereEmail('second@example.com')->sole()->role);
    }

    public function test_reissuing_before_anyone_has_a_passkey_keeps_the_long_window(): void
    {
        $this->artisan('operator', ['action' => 'create', 'email' => 'first@example.com', '--name' => '最初'])->assertSuccessful();
        CarbonImmutable::setTestNow('2026-10-30 13:00:00');

        $this->artisan('operator', ['action' => 'enroll', 'email' => 'first@example.com'])->expectsOutputToContain('24 時間')->assertSuccessful();

        self::assertEquals(CarbonImmutable::now()->addMinutes(1440), $this->expiry(Operator::query()->whereEmail('first@example.com')->sole()));
    }

    public function test_a_disabled_owner_does_not_count_so_the_new_operator_can_recover_the_site(): void
    {
        $this->artisan('operator', ['action' => 'create', 'email' => 'first@example.com', '--name' => '最初'])->assertSuccessful();
        $first = Operator::query()->whereEmail('first@example.com')->sole();
        $this->givePasskey($first);
        $first->forceFill(['disabled_at' => CarbonImmutable::now()])->save();

        $this->artisan('operator', ['action' => 'create', 'email' => 'second@example.com', '--name' => '二人目'])->assertSuccessful();

        self::assertSame('owner', Operator::query()->whereEmail('second@example.com')->sole()->role);
    }

    private function expiry(Operator $operator): CarbonImmutable
    {
        return CarbonImmutable::parse(OperatorEnrollment::query()->where('operator_id', $operator->id)->whereNull('used_at')->latest('created_at')->firstOrFail()->expires_at);
    }

    private function givePasskey(Operator $operator): void
    {
        // 中身は使わない（「誰かがパスキーを持っているか」だけを見る）
        OperatorPasskey::query()->forceCreate([
            'user_id' => $operator->id,
            'name' => 'テスト',
            'credential_id' => 'cred-'.$operator->id,
            'credential' => ['test' => true],
        ]);
    }
}
