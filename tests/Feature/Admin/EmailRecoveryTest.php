<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Mail\OperatorSecurityMail;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorRecoveryRequest;
use App\Admin\Services\OperatorEmailRecovery;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * メールでの戻し（ADR 0038 の区切り 2）。申し込みは運営の有無を言わず、リンクは 15 分・1 回、開くと 24 時間待ち、待った後に同じブラウザで登録のリンク。
 * 本人が元のパスキーでログインすると取り消し、運営を止めても取り消し。待ちに入った申し込みは新しい申し込みで消えない。
 */
final class EmailRecoveryTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        Mail::fake();
    }

    public function test_a_link_starts_a_day_of_waiting_and_then_the_same_browser_can_add_a_passkey(): void
    {
        [$operator] = $this->enrolledOperator();
        Operator::create(['name' => 'Owner B', 'email' => 'owner-b@example.com'])->forceFill(['role' => 'owner'])->save();
        $original = $this->send('GET', '/auth/passkeys')->json('passkeys.0.id');
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 運営がいてもいなくても同じ答え。いないメールには何も送らない
        $unknown = $this->send('POST', '/auth/recovery/email', ['email' => 'nobody@example.com'])->assertStatus(202)->json();
        $known = $this->send('POST', '/auth/recovery/email', ['email' => 'Hana@Example.com'])->assertStatus(202)->json();
        $this->assertSame($unknown, $known);
        $token = $this->linkToken();
        Mail::assertQueuedCount(1);

        // リンクを開くと 24 時間の待ちに入り、本人とほかのオーナーへ知らせる。同じリンクは 2 度使えない
        $confirmed = $this->send('POST', '/auth/recovery/email/confirm', ['token' => $token])->assertOk();
        $browser = $confirmed->getCookie(OperatorEmailRecovery::COOKIE)?->getValue();
        $this->assertIsString($browser);

        $this->assertSame(now()->addDay()->utc()->format('Y-m-d\TH:i'), substr((string) $confirmed->json('ready_at'), 0, 16));
        Mail::assertQueued(OperatorSecurityMail::class, fn (OperatorSecurityMail $m): bool => $m->kind === OperatorSecurityMail::RECOVERY_WAITING && $m->hasTo('hana@example.com'));
        Mail::assertQueued(OperatorSecurityMail::class, fn (OperatorSecurityMail $m): bool => $m->kind === OperatorSecurityMail::OTHER_RECOVERY_WAITING && $m->hasTo('owner-b@example.com'));
        $this->send('POST', '/auth/recovery/email/confirm', ['token' => $token])->assertStatus(422);

        // 待つ間は受け取れない。印の無いブラウザも受け取れない
        $this->send('POST', '/auth/recovery/email/finish')->assertStatus(422);
        $this->withCredentials()->withCookie(OperatorEmailRecovery::COOKIE, $browser);
        $this->send('POST', '/auth/recovery/email/finish')->assertStatus(409)->assertJsonPath('ready_at', $confirmed->json('ready_at'));

        // 24 時間の後に同じブラウザで受け取り、新しいパスキーを足す。前のパスキーは 24 時間消せない
        $this->travel(24)->hours();
        $this->travel(1)->minutes();
        $url = $this->send('POST', '/auth/recovery/email/finish')->assertOk()->json('url');
        $this->enroll(substr($url, strpos($url, '#') + 1), $this->newAuthenticator());
        $this->assertNotNull(Operator::query()->whereKey($operator->id)->value('recovered_at'));
        $this->travel(61)->seconds();
        $newcomer = $this->newAuthenticator();
        $this->send('DELETE', "/auth/passkeys/{$original}")->assertStatus(423); // 再確認が先
        Mail::assertQueued(OperatorSecurityMail::class, fn (OperatorSecurityMail $m): bool => $m->kind === OperatorSecurityMail::OTHER_CODE_USED && $m->hasTo('owner-b@example.com'));
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->withCredentials()->withCookie(OperatorEmailRecovery::COOKIE, $browser);
        $this->send('POST', '/auth/recovery/email/finish')->assertStatus(422);
        unset($newcomer);
    }

    public function test_logging_in_with_the_original_passkey_or_disabling_cancels_the_waiting_request(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $browser = $this->send('POST', '/auth/recovery/email/confirm', ['token' => $this->linkToken()])->assertOk()->getCookie(OperatorEmailRecovery::COOKIE)?->getValue();

        // 乗っ取りの申し込みでも、本人が元のパスキーで入れば止まる
        $this->travel(61)->seconds();
        $this->login($operator, $authenticator);
        Mail::assertQueued(OperatorSecurityMail::class, fn (OperatorSecurityMail $m): bool => $m->kind === OperatorSecurityMail::RECOVERY_CANCELLED);
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->travel(25)->hours();
        $this->withCredentials()->withCookie(OperatorEmailRecovery::COOKIE, (string) $browser);
        $this->send('POST', '/auth/recovery/email/finish')->assertStatus(422);

        // 運営を止めても取り消される
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $this->send('POST', '/auth/recovery/email/confirm', ['token' => $this->linkToken()])->assertOk();
        $this->artisan('operator', ['action' => 'enroll', 'email' => 'hana@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertSame(0, OperatorRecoveryRequest::query()->whereNull('cancelled_at')->whereNull('used_at')->count());
    }

    public function test_links_expire_new_requests_do_not_cancel_old_links_and_requests_are_limited(): void
    {
        $this->enrolledOperator();
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 15 分を過ぎたリンクは使えない
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $stale = $this->linkToken();
        $this->travel(16)->minutes();
        $this->send('POST', '/auth/recovery/email/confirm', ['token' => $stale])->assertStatus(422);

        // 新しい申し込み（メールを知っているだけの人のものかもしれない）で前のリンクは消えない。どれか 1 つで受け付けたら残りは取り消す
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $first = $this->linkToken();
        $this->fromAddress('198.51.100.30');
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $this->send('POST', '/auth/recovery/email/confirm', ['token' => $first])->assertOk();
        $this->assertSame(2, OperatorRecoveryRequest::query()->where('cancelled_by', 'superseded')->count()); // 期限の切れたリンクと、もう 1 つの開いていないリンク

        // 同じ接続元と同じメールの組は 1 日 3 回まで（4 回目は同じ答えで、何も送らない）。別の接続元からは送れる
        $this->fromAddress('203.0.113.40');
        $count = fn (): int => count(Mail::queued(OperatorSecurityMail::class, fn (OperatorSecurityMail $m): bool => $m->kind === OperatorSecurityMail::RECOVERY_LINK));
        for ($i = 0; $i < 3; $i++) {
            $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        }
        $sent = $count();
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $this->assertSame($sent, $count());
        $this->fromAddress('203.0.113.41');
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $this->assertSame($sent + 1, $count());
    }

    public function test_the_wait_must_be_used_within_three_days_and_the_owner_can_cancel_while_signed_in(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $browser = (string) $this->send('POST', '/auth/recovery/email/confirm', ['token' => $this->linkToken()])->assertOk()->getCookie(OperatorEmailRecovery::COOKIE)?->getValue();

        // 待ちの後 72 時間を過ぎたら使えない（取り消し扱い）
        $this->travel(24 + 73)->hours();
        $this->withCredentials()->withCookie(OperatorEmailRecovery::COOKIE, $browser);
        $this->send('POST', '/auth/recovery/email/finish')->assertStatus(422);
        $this->assertSame('expired', OperatorRecoveryRequest::query()->value('cancelled_by'));

        // ログインしたまま知らせを読んだ本人は、ホームの知らせから取り消せる（ログインした時は 24 時間の後なので取り消さない形を、ここでは使わない）
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $this->send('POST', '/auth/recovery/email/confirm', ['token' => $this->linkToken()])->assertOk();
        $this->travel(61)->seconds();
        $operator->forceFill(['recovered_at' => now()])->save(); // 戻ってすぐの運営（ログインでは取り消さない）
        $this->login($operator, $authenticator);
        $this->assertNotNull($this->send('GET', '/auth/me')->assertOk()->json('recovery_pending'));
        $this->send('POST', '/auth/recovery/email/cancel')->assertNoContent();
        $this->assertNull($this->send('GET', '/auth/me')->json('recovery_pending'));
        $this->assertSame('self', OperatorRecoveryRequest::query()->latest('created_at')->value('cancelled_by'));
    }

    public function test_reconfirming_with_the_original_passkey_cancels_the_waiting_request(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(409); // ログイン中は申し込めない
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana@example.com'])->assertStatus(202);
        $this->send('POST', '/auth/recovery/email/confirm', ['token' => $this->linkToken()])->assertOk();
        $this->travel(61)->seconds();
        $operator->forceFill(['recovered_at' => now()])->save();
        $this->login($operator, $authenticator);
        $operator->forceFill(['recovered_at' => now()->subDays(2)])->save();
        $this->app['auth']->forgetGuards(); // テストでは同じアプリが前の要求の運営を覚えているので、読み直させる
        $this->reconfirm($operator, $authenticator);
        $this->assertSame('confirmed', OperatorRecoveryRequest::query()->value('cancelled_by'));
    }

    /**
     * キューに積んだ知らせ: リンク（合い言葉）・運営のメール・名前は、キューの表とその先の failed_jobs・バックアップに平文で残さない。
     * Mail::fake ではなく database のドライバで実際に積まれた中身（jobs の payload）を見る
     */
    public function test_queued_security_mails_keep_the_link_email_and_name_out_of_the_queue_in_plain_text(): void
    {
        $this->enrolledOperator('hana-owner@example.com');
        Operator::create(['name' => 'Owner Bravo', 'email' => 'owner-bravo@example.com'])->forceFill(['role' => 'owner'])->save();
        $this->send('POST', '/auth/logout')->assertNoContent();

        // メールの送り方を本物に戻し、キューは中央 DB の jobs の表へ（働き手は動かさないので、積まれたまま残る）
        $fake = Mail::getFacadeRoot();
        $this->assertInstanceOf(MailFake::class, $fake);
        Mail::swap($fake->manager);
        config(['queue.default' => 'database']);
        $this->assertSame(0, DB::table('jobs')->count());

        // 申し込み（リンクのメール）→ リンクを開く（本人とほかのオーナーへの待ちの知らせ）
        $this->send('POST', '/auth/recovery/email', ['email' => 'hana-owner@example.com'])->assertStatus(202);
        $payloads = DB::table('jobs')->pluck('payload')->all();
        $this->assertCount(1, $payloads);
        $link = $this->unsealed($payloads[0])->link;
        $this->assertIsString($link);
        $token = substr($link, strpos($link, '#') + 1);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);
        $this->send('POST', '/auth/recovery/email/confirm', ['token' => $token])->assertOk();

        $payloads = DB::table('jobs')->orderBy('id')->pluck('payload')->all();
        $this->assertCount(3, $payloads);
        $this->assertSame(
            [OperatorSecurityMail::RECOVERY_LINK, OperatorSecurityMail::RECOVERY_WAITING, OperatorSecurityMail::OTHER_RECOVERY_WAITING],
            array_map(fn (string $p): string => $this->unsealed($p)->kind, $payloads),
        );

        foreach ($payloads as $payload) {
            // 暗号化した中身（上の unsealed が開けて確かめた）は、ランダムな英数字の列。短い名前（Hana の 4 文字）は偶然現れうるので
            // （200 回に 1 回ほど落ちていた）、その列を除いた残り＝平文で積まれる部分だけを見る
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $sealed = $decoded['data']['command'];
            $this->assertIsString($sealed);
            $plain = str_replace($sealed, '', $payload);
            $this->assertNotSame($payload, $plain);

            foreach ([$token, 'hana-owner@example.com', 'owner-bravo@example.com', 'Owner Bravo', 'Hana'] as $secret) {
                $this->assertStringNotContainsStringIgnoringCase($secret, $plain);
                // 暗号化した列の中にも、そのままの形（大文字小文字まで同じ）では現れない長いものは見る
                if (strlen($secret) >= 11) {
                    $this->assertStringNotContainsString($secret, $payload);
                }
            }
        }
    }

    /**
     * 積まれた payload を、アプリの鍵で開いて中のメールを取り出す（開けない＝暗号化されていないなら落ちる）
     */
    private function unsealed(string $payload): OperatorSecurityMail
    {
        $command = json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command'];
        $this->assertIsString($command);
        $job = unserialize((string) Crypt::decrypt($command));
        $this->assertInstanceOf(SendQueuedMailable::class, $job);
        $this->assertInstanceOf(OperatorSecurityMail::class, $job->mailable);

        return $job->mailable;
    }

    private function fromAddress(string $ip): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function linkToken(): string
    {
        $mails = Mail::queued(OperatorSecurityMail::class, fn (OperatorSecurityMail $m): bool => $m->kind === OperatorSecurityMail::RECOVERY_LINK);
        $link = (string) $mails->last()?->link;
        $this->assertStringContainsString('/recover/email#', $link);

        return substr($link, strpos($link, '#') + 1);
    }

    private function login(Operator $operator, SoftwareAuthenticator $authenticator): void
    {
        $options = $this->ceremonyOptions('/auth/login/options');
        $this->send('POST', '/auth/login', ['credential' => $authenticator->assert($options, $operator->getPasskeyUserHandle())])->assertOk();
    }
}
