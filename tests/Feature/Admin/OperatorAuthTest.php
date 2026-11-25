<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorEnrollments;
use App\Admin\Services\RevokedSessions;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Tests\Support\AppContract;
use Tests\Support\OperatorSession;
use Tests\TestCase;

/**
 * 運営の管理画面のログイン（ADR 0013）。パスキーの儀式はソフトウェア認証器で本物のまま通す。
 */
final class OperatorAuthTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    // 監査ログは消さない表なので、このテストより前の行と区別するための境目
    private int $auditBaseline = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureAdminHost();

        $this->auditBaseline = (int) AuditEvent::query()->max('sequence');
    }

    public function test_enrollment_shows_the_system_version_only_after_the_link_is_checked(): void
    {
        $revision = str_repeat('ab12', 10);
        config(['app.revision' => $revision, 'app.revision_date' => '2026-10-29T16:40:00+09:00']);
        $issued = app(OperatorEnrollments::class)->issue(Operator::create(['name' => 'Hana', 'email' => 'hana@example.com']), 'test');

        // 無効なリンクの応答にはバージョンを出さない（狙う弱さのバージョンを教えない）
        $rejected = $this->send('POST', '/auth/enrollment', ['token' => str_repeat('0', 64)])->assertStatus(422);
        $this->assertStringNotContainsString($revision, (string) $rejected->getContent());
        $this->assertStringNotContainsString(substr($revision, 0, 12), (string) $rejected->getContent());

        $this->send('POST', '/auth/enrollment', ['token' => $issued['token']])->assertOk()
            ->assertJsonPath('system', ['revision' => $revision, 'short' => substr($revision, 0, 12), 'date' => '2026-10-29T16:40:00+09:00']);
    }

    public function test_signed_in_operators_see_the_system_version_and_others_do_not(): void
    {
        $revision = str_repeat('cd34', 10);
        config(['app.revision' => $revision, 'app.revision_date' => '2026-10-29T19:00:00+09:00']);

        // ログインの前はバージョンを出さない
        $this->assertStringNotContainsString(substr($revision, 0, 12), (string) $this->send('GET', '/auth/me')->assertStatus(401)->getContent());

        $issued = app(OperatorEnrollments::class)->issue(Operator::create(['name' => 'Hana', 'email' => 'hana@example.com']), 'test');
        $this->enroll($issued['token'], $this->newAuthenticator());

        $this->send('GET', '/auth/me')->assertOk()
            ->assertJsonPath('system.short', substr($revision, 0, 12))
            ->assertJsonPath('system.date', '2026-10-29T19:00:00+09:00');
    }

    public function test_signed_in_operators_get_the_installed_apps_for_the_help(): void
    {
        // ログインの前は出さない
        $this->assertStringNotContainsString('"apps"', (string) $this->send('GET', '/auth/me')->assertStatus(401)->getContent());

        $issued = app(OperatorEnrollments::class)->issue(Operator::create(['name' => 'Hana', 'email' => 'hana@example.com']), 'test');
        $this->enroll($issued['token'], $this->newAuthenticator());

        // 入っている App の名前（登録簿の名前の順）。入っていない App の説明のページはヘルプに出さない（ADR 0098）
        $apps = $this->send('GET', '/auth/me')->assertOk()->json('apps');
        $this->assertSame(array_keys(AppContract::packages()), $apps);
        $this->assertContains('faq', $apps);
    }

    public function test_a_malformed_version_is_not_shown(): void
    {
        $good = str_repeat('ab12', 10);
        $cases = [
            // [焼き込んだ印, 日時, 返す short, 返す日時]
            ['<script>', '2026-10-29T16:40:00+09:00', null, null],
            [strtoupper($good), '2026-10-29T16:40:00+09:00', null, null],
            [substr($good, 0, 39), '2026-10-29T16:40:00+09:00', null, null],
            [$good, '<b>', substr($good, 0, 12), null],
            [$good, '2026-10-29', substr($good, 0, 12), null],
            [null, null, null, null],
        ];

        $operator = Operator::create(['name' => 'Hana', 'email' => 'hana@example.com']);

        foreach ($cases as [$revision, $date, $short, $shownDate]) {
            config(['app.revision' => $revision, 'app.revision_date' => $date]);
            $issued = app(OperatorEnrollments::class)->issue($operator, 'test');

            $system = $this->send('POST', '/auth/enrollment', ['token' => $issued['token']])->assertOk()->json('system');
            $this->assertSame($short, $system['short']);
            $this->assertSame($shownDate, $system['date']);
            $this->assertSame($short === null ? null : $good, $system['revision']);
        }
    }

    public function test_enrollment_registers_a_passkey_logs_in_and_spends_the_token(): void
    {
        $operator = Operator::create(['name' => 'Hana', 'email' => 'Hana@Example.com']);
        $this->assertSame('hana@example.com', $operator->email);

        $issued = app(OperatorEnrollments::class)->issue($operator, 'test');
        $this->assertStringContainsString('/enroll#', $issued['url']);
        $this->assertNotSame($issued['token'], DB::table('operator_enrollments')->value('token_hash'));

        // ログイン前は自分の情報を見られない
        $this->send('GET', '/auth/me')->assertStatus(401);

        $this->enroll($issued['token'], $this->newAuthenticator());
        $this->send('GET', '/auth/me')->assertOk()->assertJsonPath('operator.email', 'hana@example.com');
        $this->assertSame(1, $operator->passkeys()->count());

        // 使ったトークンは二度と通らない
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->send('POST', '/auth/enrollment', ['token' => $issued['token']])->assertStatus(422);

        // 新しく発行すると、前の未使用のトークンは失効する
        $first = app(OperatorEnrollments::class)->issue($operator, 'test');
        app(OperatorEnrollments::class)->issue($operator, 'test');
        $this->send('POST', '/auth/enrollment', ['token' => $first['token']])->assertStatus(422);

        // 期限切れも通らない
        $late = app(OperatorEnrollments::class)->issue($operator, 'test');
        $this->travel(31)->minutes();
        $this->send('POST', '/auth/enrollment', ['token' => $late['token']])->assertStatus(422);
    }

    public function test_a_logged_out_session_number_is_shut_out_even_if_its_login_comes_back(): void
    {
        // JSON で送る道具は withCredentials() が無いと Cookie を送らない（このテストはセッションの番号そのものを見る）
        $this->withCredentials();

        // ログアウトすると、そのセッションの番号は取り消し済みになる
        [$operator, $authenticator] = $this->enrolledOperator();
        $loggedOut = (string) $this->sessionId;
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->assertTrue(app(RevokedSessions::class)->isRevoked($loggedOut));

        // 取り消し済みの番号のセッションは、中身がログイン済みでも締め出す（ログアウトの前に始まったリクエストが後から書き戻した場合）。
        // 書き戻しの代わりに、ログイン中のセッションの番号を取り消し済みにして確かめる
        $this->resetRateLimit();
        $this->sessionId = null;
        $assertion = $authenticator->assert($this->ceremonyOptions('/auth/login/options'), $operator->getPasskeyUserHandle());
        $this->send('POST', '/auth/login', ['credential' => $assertion])->assertOk();
        $this->send('GET', '/auth/me')->assertOk();
        app(RevokedSessions::class)->revoke((string) $this->sessionId);
        $this->send('GET', '/auth/me')->assertUnauthorized();

        $ended = AuditEvent::query()->where('type', 'operator.session_ended')->where('operator_id', $operator->id)->latest('sequence')->first();
        $this->assertSame('revoked', $ended?->data['reason'] ?? null);
    }

    public function test_login_with_a_passkey_and_the_ways_it_is_refused(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->send('POST', '/auth/logout')->assertNoContent();
        $handle = $operator->getPasskeyUserHandle();

        // 試行回数の制限（IP ごとに 1 分 10 回）が効く。以降の確認のため、ここでカウンタを空にする
        for ($i = 0; $i < 10; $i++) {
            $this->send('GET', '/auth/login/options');
        }
        $this->send('GET', '/auth/login/options')->assertStatus(429);
        $this->resetRateLimit();

        // 正しい応答でログインできる
        $options = $this->ceremonyOptions('/auth/login/options');
        $assertion = $authenticator->assert($options, $handle);
        $this->send('POST', '/auth/login', ['credential' => $assertion])->assertOk()->assertJsonPath('operator.email', $operator->email);
        $this->assertNotNull($operator->fresh()?->last_login_at);
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 同じ応答の再送は通らない（挑戦は 1 回で消える）
        $this->send('POST', '/auth/login', ['credential' => $assertion])->assertStatus(422);
        $this->ceremonyOptions('/auth/login/options');
        $this->send('POST', '/auth/login', ['credential' => $assertion])->assertStatus(422);

        // 偽サイト（別の origin）で取られた応答は通らない
        $options = $this->ceremonyOptions('/auth/login/options');
        $this->send('POST', '/auth/login', ['credential' => $authenticator->assert($options, $handle, origin: 'https://admin.evil.example')])->assertStatus(422);

        $this->resetRateLimit();

        // 本人確認（生体認証・PIN）なしの応答は通らない
        $options = $this->ceremonyOptions('/auth/login/options');
        $this->send('POST', '/auth/login', ['credential' => $authenticator->assert($options, $handle, userVerified: false)])->assertStatus(422);

        // 署名カウンタが戻った応答（複製された認証器の疑い）は通らない
        $options = $this->ceremonyOptions('/auth/login/options');
        $this->send('POST', '/auth/login', ['credential' => $authenticator->assert($options, $handle, counter: 1)])->assertStatus(422);

        // 止めた運営はログインできず、ログイン中のセッションも次のリクエストで切れる
        $options = $this->ceremonyOptions('/auth/login/options');
        $this->send('POST', '/auth/login', ['credential' => $authenticator->assert($options, $handle)])->assertOk();
        $operator->update(['disabled_at' => CarbonImmutable::now()]);
        $this->send('GET', '/auth/me')->assertStatus(401);

        $options = $this->ceremonyOptions('/auth/login/options');
        $this->send('POST', '/auth/login', ['credential' => $authenticator->assert($options, $handle)])->assertStatus(422);

        // 失敗はすべて監査ログに残る（再送 2 回・偽 origin・本人確認なし・カウンタ戻り・停止中）
        $failures = AuditEvent::query()->where('sequence', '>', $this->auditBaseline)->where('type', 'operator.login_failed')->count();
        $this->assertGreaterThanOrEqual(6, $failures);
    }

    public function test_managing_passkeys_needs_a_fresh_confirmation_and_the_last_one_stays(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();

        // 再確認なしではパスキーを足せない
        $this->send('GET', '/auth/passkeys/options')->assertStatus(423);

        $options = $this->ceremonyOptions('/auth/confirm/options');
        $this->send('POST', '/auth/confirm', ['credential' => $authenticator->assert($options, $operator->getPasskeyUserHandle())])->assertNoContent();

        $second = $this->newAuthenticator();
        $options = $this->ceremonyOptions('/auth/passkeys/options');
        $this->send('POST', '/auth/passkeys', ['name' => '予備のキー', 'credential' => $second->register($options)])->assertCreated();
        $this->assertSame(2, $operator->passkeys()->count());

        $ids = $this->send('GET', '/auth/passkeys')->assertOk()->json('passkeys.*.id');
        $this->assertCount(2, $ids);
        $this->assertArrayNotHasKey('credential', $this->send('GET', '/auth/passkeys')->json('passkeys.0'));

        $this->send('DELETE', "/auth/passkeys/{$ids[1]}")->assertNoContent();
        $this->send('DELETE', "/auth/passkeys/{$ids[0]}")->assertStatus(422);
        $this->assertSame(1, $operator->passkeys()->count());

        // ログインしたまま別の運営の登録トークンは使えない（セッションの取り違えを防ぐ）
        $this->resetRateLimit();
        $other = Operator::create(['name' => 'Other', 'email' => 'other@example.com']);
        $otherToken = app(OperatorEnrollments::class)->issue($other, 'test')['token'];
        $this->send('POST', '/auth/enrollment', ['token' => $otherToken])->assertStatus(409);
        $this->send('GET', '/auth/login/options')->assertStatus(409);

        // 他人のパスキーは消せない（見つからない扱い）
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->enroll($otherToken, $this->newAuthenticator());
        $this->send('POST', '/auth/logout');
        $otherId = (string) $other->passkeys()->value('id');
        $options = $this->ceremonyOptions('/auth/login/options');
        $this->send('POST', '/auth/login', ['credential' => $authenticator->assert($options, $operator->getPasskeyUserHandle())])->assertOk();
        $options = $this->ceremonyOptions('/auth/confirm/options');
        $this->send('POST', '/auth/confirm', ['credential' => $authenticator->assert($options, $operator->getPasskeyUserHandle())])->assertNoContent();
        $this->send('DELETE', "/auth/passkeys/{$otherId}")->assertNotFound();
    }

    public function test_admin_lives_on_its_own_host_and_can_be_fenced_by_ip(): void
    {
        $this->get('http://localhost/auth/me')->assertNotFound();
        $this->send('GET', '/auth/csrf')->assertNoContent();

        config(['admin.allowed_ips' => ['10.1.2.3/32']]);
        $this->send('GET', '/auth/csrf')->assertNotFound();
        config(['admin.allowed_ips' => ['127.0.0.1']]);
        $this->send('GET', '/auth/csrf')->assertNoContent();

        // 接続元を偽る見出しでは抜けられない（家の LAN などの私設の住所から来ても。nginx から PHP へは直結で、信じる代理は無い）
        config(['admin.allowed_ips' => ['10.1.2.3/32']]);
        foreach (['X-Forwarded-For' => '10.1.2.3', 'X-Real-IP' => '10.1.2.3', 'Forwarded' => 'for=10.1.2.3'] as $header => $value) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])->withHeaders([$header => $value])->send('GET', '/auth/csrf')->assertNotFound();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->send('GET', '/auth/csrf')->assertNoContent();
        // 信じる代理の部品に直に通す: 偽の見出しでも接続元は変わらない。Host が .on-forge.com で終わっても（設定が null だと誰でも信じる形になる）
        foreach (['admin.localhost', 'x.on-forge.com'] as $host) {
            $request = Request::create("http://{$host}/", 'GET', server: ['REMOTE_ADDR' => '192.168.1.50', 'HTTP_X_FORWARDED_FOR' => '10.1.2.3']);
            app(TrustProxies::class)->handle($request, static fn (): Response => new Response(''));
            $this->assertSame('192.168.1.50', $request->ip(), $host);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        config(['admin.allowed_ips' => ['127.0.0.1']]);

        // 本番で接続元の制限が空なら、閉じる側に倒す
        config(['admin.allowed_ips' => []]);
        $this->app->instance('env', 'production');
        $this->send('GET', '/auth/csrf')->assertNotFound();
    }

    public function test_requests_from_other_sites_do_not_overwrite_the_session_cookie(): void
    {
        $cookie = (string) config('admin.session_cookie');
        $this->enrolledOperator();

        // 管理画面そのもの・ブックマークからの通信には、今までどおり Cookie を返す
        foreach ([null, 'same-origin', 'none'] as $site) {
            $response = $this->withHeaders(array_filter(['Sec-Fetch-Site' => $site]))->send('GET', '/auth/csrf');
            $this->assertNotNull($response->getCookie($cookie), (string) $site);
            $this->assertNotNull($response->getCookie('XSRF-TOKEN'), (string) $site);
        }

        // 他サイトからの通信（Strict の Cookie が付かない）には返さない。返すと本物のセッションが上書きされる
        foreach (['cross-site', 'same-site'] as $site) {
            $response = $this->withHeaders(['Sec-Fetch-Site' => $site])->send('GET', '/auth/csrf');
            $this->assertNull($response->getCookie($cookie), $site);
            $this->assertNull($response->getCookie('XSRF-TOKEN'), $site);
        }

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->send('GET', '/auth/me')->assertOk();
    }

    public function test_sessions_end_after_the_absolute_limit(): void
    {
        $this->enrolledOperator();
        $this->send('GET', '/auth/me')->assertOk();

        config(['admin.session_idle_minutes' => 24 * 60]);
        $this->travel(13)->hours();
        $this->send('GET', '/auth/me')->assertStatus(401);
    }

    public function test_the_audit_log_is_append_only_and_chained(): void
    {
        $this->enrolledOperator();

        $types = AuditEvent::query()->where('sequence', '>', $this->auditBaseline)->pluck('type')->all();
        $this->assertContains('operator.enrollment_issued', $types);
        $this->assertContains('operator.passkey_added', $types);
        $this->assertContains('operator.login_succeeded', $types);
        $this->assertTrue(app(AuditLog::class)->verify()->intact());

        foreach (['UPDATE audit_events SET type = type', 'DELETE FROM audit_events', 'TRUNCATE audit_events'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail("監査ログを書き換えられてしまった: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('追記専用', $e->getMessage());
            }
        }
    }

    public function test_operator_command_creates_disables_and_refuses_duplicates(): void
    {
        // 最初の運営はオーナーになり、最後のオーナーは止められない（OperatorFirstRunTest）。ここではスタッフを作って止める
        $this->artisan('operator', ['action' => 'create', 'email' => 'owner@example.com', '--name' => 'オーナー'])->assertSuccessful();
        $this->artisan('operator', ['action' => 'create', 'email' => 'cli@example.com', '--name' => 'CLI'])
            ->expectsOutputToContain('/enroll#')
            ->assertSuccessful();
        $this->artisan('operator', ['action' => 'create', 'email' => 'CLI@example.com', '--name' => 'CLI'])->assertFailed();
        $this->artisan('operator', ['action' => 'disable', 'email' => 'cli@example.com'])->assertSuccessful();
        $this->assertFalse(Operator::query()->whereEmail('cli@example.com')->firstOrFail()->isActive());
        $this->artisan('operator', ['action' => 'enroll', 'email' => 'cli@example.com'])->assertFailed();
        $this->artisan('operator', ['action' => 'list'])->assertSuccessful();
    }
}
