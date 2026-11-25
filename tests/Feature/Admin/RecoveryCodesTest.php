<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Mail\OperatorSecurityMail;
use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorRecoveryCode;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * パスキーをなくした時の回復用のコード（ADR 0038）。作り直しは本人の再確認の後だけ、平文は 1 度だけ、保存は鍵つきのハッシュ。
 * 使うとそのコードは使用済みになり、新しいパスキーを足す登録のリンクだけが出る。間違えるほど待ちが延び、合わない理由は言わない。
 */
final class RecoveryCodesTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        Mail::fake();
    }

    public function test_codes_are_made_after_reconfirming_shown_once_and_replace_the_old_ones(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->send('GET', '/auth/recovery-codes')->assertOk()->assertExactJson(['remaining' => 0, 'created_at' => null]);

        // 直前の再確認が無ければ作れない
        $this->send('POST', '/auth/recovery-codes')->assertStatus(423);
        $this->reconfirm($operator, $authenticator);
        $first = $this->send('POST', '/auth/recovery-codes')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('codes');
        $this->assertCount(10, $first);
        $this->assertCount(10, array_unique($first));

        foreach ($first as $code) {
            $this->assertMatchesRegularExpression('/\A[0-9a-hjkmnp-tv-z]{4}(-[0-9a-hjkmnp-tv-z]{4}){3}\z/', $code);
        }

        // 保存は鍵つきのハッシュだけ（平文も、ただの SHA-256 も無い）
        $stored = OperatorRecoveryCode::query()->pluck('code_hash')->all();
        $this->assertCount(10, $stored);
        $this->assertNotContains(hash('sha256', str_replace('-', '', $first[0])), $stored);
        $this->send('GET', '/auth/recovery-codes')->assertOk()->assertJsonPath('remaining', 10)->assertJsonMissingPath('codes');

        // 作り直すと前のものは無効。メールにはコードを載せない
        $second = $this->send('POST', '/auth/recovery-codes')->assertOk()->json('codes');
        $this->assertSame(10, OperatorRecoveryCode::query()->count());
        Mail::assertQueued(OperatorSecurityMail::class, fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::CODES_ISSUED
            && ! str_contains($mail->render(), $second[0]) && $mail->hasTo('hana@example.com'));
        $this->assertSame(2, AuditEvent::query()->where('type', 'operator.recovery_codes_issued')->count());

        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->send('POST', '/auth/recovery', ['email' => 'hana@example.com', 'code' => $first[0]])->assertStatus(422);
    }

    public function test_a_code_gives_an_enrollment_link_once_and_only_adds_a_passkey(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($operator, $authenticator);
        $codes = $this->send('POST', '/auth/recovery-codes')->assertOk()->json('codes');
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 大文字・区切りなし・読み違えやすい文字でも受ける（i・l → 1、o → 0）
        $typed = strtoupper(str_replace('-', ' ', $codes[2]));
        $url = $this->send('POST', '/auth/recovery', ['email' => ' Hana@Example.com ', 'code' => $typed])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json('url');
        $this->assertStringStartsWith(config('admin.url').'/enroll#', $url);

        // 出た登録のリンクで新しいパスキーを足し、ログインできる
        $this->enroll(substr($url, strpos($url, '#') + 1), $this->newAuthenticator());
        $this->send('GET', '/auth/me')->assertOk()->assertJsonPath('passkeys.count', 2);
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 同じコードは二度使えない。残りは 9 個。本人へ知らせ、監査に残る（ログインまわりの 1 分 10 回の制限を越えるため時計を進める）
        $this->travel(61)->seconds();
        $this->send('POST', '/auth/recovery', ['email' => 'hana@example.com', 'code' => $codes[2]])->assertStatus(422);
        $this->assertSame(9, OperatorRecoveryCode::query()->whereNull('used_at')->count());
        Mail::assertQueued(OperatorSecurityMail::class, fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::CODE_USED && $mail->remaining === 9);
        $this->assertSame(1, AuditEvent::query()->where('type', 'operator.recovery_code_used')->count());

        // 止めた運営のコードは使えない（理由は言わず同じ答え）
        $operator->forceFill(['disabled_at' => now()])->save();
        $this->send('POST', '/auth/recovery', ['email' => 'hana@example.com', 'code' => $codes[3]])->assertStatus(422)
            ->assertJsonPath('message', 'メールか回復用のコードが違います。');
    }

    public function test_another_operators_code_or_an_unknown_email_gets_the_same_answer(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($operator, $authenticator);
        $codes = $this->send('POST', '/auth/recovery-codes')->assertOk()->json('codes');
        $this->send('POST', '/auth/logout')->assertNoContent();
        Operator::create(['name' => 'Other', 'email' => 'other@example.com']);

        $wrongOwner = $this->send('POST', '/auth/recovery', ['email' => 'other@example.com', 'code' => $codes[0]])->assertStatus(422)->json();
        $unknown = $this->send('POST', '/auth/recovery', ['email' => 'nobody@example.com', 'code' => $codes[0]])->assertStatus(422)->json();
        $malformed = $this->send('POST', '/auth/recovery', ['email' => 'hana@example.com', 'code' => 'abcd'])->assertStatus(422)->json();
        $this->assertSame($wrongOwner, $unknown);
        $this->assertSame($wrongOwner, $malformed);
        $this->assertSame(10, OperatorRecoveryCode::query()->whereNull('used_at')->count());
        $this->send('POST', '/auth/recovery', ['email' => ['hana@example.com'], 'code' => $codes[0]])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_mistakes_make_the_wait_longer_per_source_and_email_pair_without_locking_out_others(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($operator, $authenticator);
        $codes = $this->send('POST', '/auth/recovery-codes')->assertOk()->json('codes');
        $this->send('POST', '/auth/logout')->assertNoContent();
        Operator::create(['name' => 'Staff', 'email' => 'staff@example.com']);
        $wrong = ['email' => 'staff@example.com', 'code' => 'zzzz-zzzz-zzzz-zzzz'];

        // 同じ会社の接続元のスタッフが自分のメールで間違え続ける: 3 回まではすぐ、4 回目の後は 1 分、次は 5 分（その組だけ）
        $this->fromAddress('203.0.113.7');
        for ($i = 0; $i < 4; $i++) {
            $this->send('POST', '/auth/recovery', $wrong)->assertStatus(422);
        }
        $this->assertSame('60', $this->send('POST', '/auth/recovery', $wrong)->assertStatus(429)->headers->get('Retry-After'));
        $this->travel(61)->seconds();
        $this->send('POST', '/auth/recovery', $wrong)->assertStatus(422);
        $this->assertSame('300', $this->send('POST', '/auth/recovery', $wrong)->assertStatus(429)->headers->get('Retry-After'));

        // 同じ接続元でも、オーナーは待たずに自分のコードで戻れる（ほかの人の間違いで締め出されない）
        $this->send('POST', '/auth/recovery', ['email' => 'hana@example.com', 'code' => $codes[0]])->assertOk();

        // IPv6 は /64 で 1 つの接続元。後ろだけ変えても組の数えは続く
        $this->travel(61)->minutes();
        $this->fromAddress('2001:db8:1:2::1');
        for ($i = 0; $i < 4; $i++) {
            $this->send('POST', '/auth/recovery', $wrong)->assertStatus(422);
        }
        $this->fromAddress('2001:db8:1:2::99');
        $this->send('POST', '/auth/recovery', $wrong)->assertStatus(429);
    }

    public function test_one_source_trying_many_emails_stops_after_the_daily_limit(): void
    {
        $this->fromAddress('198.51.100.20');

        for ($i = 0; $i < 30; $i++) {
            $this->send('POST', '/auth/recovery', ['email' => "user{$i}@example.com", 'code' => 'zzzz-zzzz-zzzz-zzzz'])->assertStatus(422);

            if ($i % 9 === 8) {
                $this->travel(61)->seconds(); // ログインの入口の 1 分 10 回とは別に数えていることを見るため
            }
        }

        $this->assertGreaterThan(3600, (int) $this->send('POST', '/auth/recovery', ['email' => 'new@example.com', 'code' => 'zzzz-zzzz-zzzz-zzzz'])->assertStatus(429)->headers->get('Retry-After'));
        $this->fromAddress('198.51.100.21');
        $this->send('POST', '/auth/recovery', ['email' => 'new@example.com', 'code' => 'zzzz-zzzz-zzzz-zzzz'])->assertStatus(422);
    }

    public function test_after_recovering_older_passkeys_and_the_codes_are_kept_for_a_day_and_owners_are_told(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        Operator::create(['name' => 'Owner B', 'email' => 'owner-b@example.com'])->forceFill(['role' => 'owner'])->save();
        $this->reconfirm($operator, $authenticator);
        $codes = $this->send('POST', '/auth/recovery-codes')->assertOk()->json('codes');
        $original = $this->send('GET', '/auth/passkeys')->json('passkeys.0.id');
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 全角で打ったコードも受ける。新しいパスキーで入る
        $fullWidth = mb_convert_kana(strtoupper($codes[0]), 'AS');
        $url = $this->send('POST', '/auth/recovery', ['email' => 'hana@example.com', 'code' => $fullWidth])->assertOk()->json('url');
        $newAuthenticator = $this->newAuthenticator();
        $this->enroll(substr($url, strpos($url, '#') + 1), $newAuthenticator);
        Mail::assertQueued(OperatorSecurityMail::class, fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::OTHER_CODE_USED && $mail->hasTo('owner-b@example.com'));

        // 戻ってから 24 時間は、戻る前からあるパスキーを消せず、コードも作り直せない（盗んだコードで本人を締め出させない）
        $this->travel(61)->seconds(); // ログインまわりの 1 分 10 回の制限を越えるため
        $this->reconfirm($operator, $newAuthenticator);
        $this->send('DELETE', "/auth/passkeys/{$original}")->assertStatus(422)->assertJsonValidationErrors('passkey');
        $this->send('POST', '/auth/recovery-codes')->assertStatus(422);
        $this->assertSame(9, OperatorRecoveryCode::query()->whereNull('used_at')->count());

        // 戻った後に足したパスキーは、元のパスキーで入った本人が消せる
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->travel(61)->seconds();
        $this->login($operator, $authenticator);
        $this->reconfirm($operator, $authenticator);
        $ids = array_column((array) $this->send('GET', '/auth/passkeys')->json('passkeys'), 'id');
        $added = array_values(array_diff($ids, [$original]))[0];
        $this->send('DELETE', "/auth/passkeys/{$added}")->assertNoContent();

        // 24 時間の後は、ふつうにできる
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->travel(25)->hours();
        $this->login($operator, $authenticator);
        $this->reconfirm($operator, $authenticator);
        $this->send('POST', '/auth/recovery-codes')->assertOk();
    }

    public function test_revoking_passkeys_from_the_server_or_disabling_the_operator_removes_the_codes(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($operator, $authenticator);
        $codes = $this->send('POST', '/auth/recovery-codes')->assertOk()->json('codes');
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 乗っ取りの後始末（サーバーのコマンドでパスキーを取り消す）で、乗っ取った人が作ったかもしれないコードも消える
        $this->artisan('operator', ['action' => 'enroll', 'email' => 'hana@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertSame(0, OperatorRecoveryCode::query()->count());
        $this->send('POST', '/auth/recovery', ['email' => 'hana@example.com', 'code' => $codes[0]])->assertStatus(422);

        // 止めた運営のコードも消える（有効に戻しても前のコードでは入れない）
        $other = Operator::create(['name' => 'Staff', 'email' => 'staff@example.com']);
        OperatorRecoveryCode::create(['operator_id' => $other->id, 'code_hash' => str_repeat('a', 64)]);
        $this->artisan('operator', ['action' => 'disable', 'email' => 'staff@example.com'])->assertSuccessful();
        $this->assertSame(0, OperatorRecoveryCode::query()->where('operator_id', $other->id)->count());
    }

    private function login(Operator $operator, SoftwareAuthenticator $authenticator): void
    {
        $options = $this->ceremonyOptions('/auth/login/options');
        $this->send('POST', '/auth/login', ['credential' => $authenticator->assert($options, $operator->getPasskeyUserHandle())])->assertOk();
    }

    private function fromAddress(string $ip): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }
}
