<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorEnrollments;
use App\Admin\Services\OperatorSessions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * 運営のログイン中のセッション（ADR 0083）。パスキーを消したらそのパスキーで入った画面も切れる・ほかの端末を全部ログアウト・
 * 1 つを終える・止めた運営・期限・いつもと違う接続元の印。セッションの Cookie を持ち替えて、別の端末として確かめる
 */
final class OperatorSessionsTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    private const string MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

    private const string IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 19_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureAdminHost();
        Mail::fake();
        // セッションの Cookie を本当に送る（JSON で送る道具は、これが無いと Cookie を付けない。画面ごとのセッションを見分けるため）
        $this->withCredentials();
    }

    public function test_login_records_the_session_and_logout_ends_it(): void
    {
        $this->withHeaders(['User-Agent' => self::MAC]);
        [$operator] = $this->enrolledOperator();

        $sessions = $this->send('GET', '/auth/sessions')->assertOk()->json('sessions');
        $this->assertCount(1, $sessions);
        $this->assertSame('Chrome / Mac', $sessions[0]['device']);
        $this->assertSame('MacBook', $sessions[0]['passkey']);
        $this->assertTrue($sessions[0]['current']);
        $this->assertFalse($sessions[0]['unusual']);

        $this->send('POST', '/auth/logout')->assertNoContent();
        $row = DB::table('operator_sessions')->where('operator_id', $operator->id)->first();
        $this->assertSame('logout', $row?->end_reason);
        $this->assertNotNull($row->ended_at);
    }

    public function test_signing_out_other_devices_keeps_this_one(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $mac = $this->sessionId;

        $phone = $this->signIn($operator, $authenticator, self::IPHONE);
        $this->send('GET', '/auth/me')->assertOk();

        // Mac から「ほかを全部ログアウト」
        $this->switchTo($mac);
        $this->send('POST', '/auth/sessions/others')->assertOk()->assertJsonPath('ended', 1);
        $this->send('GET', '/auth/me')->assertOk();
        $this->assertCount(1, $this->send('GET', '/auth/sessions')->json('sessions'));

        // iPhone の画面は次の操作で締め出される
        $this->switchTo($phone);
        $this->send('GET', '/auth/me')->assertUnauthorized();

        $this->assertEquals(['reason' => 'others', 'count' => 1], AuditEvent::query()->where('type', 'operator.sessions_ended')->latest('sequence')->first()?->data);
        $this->assertSame('ended', AuditEvent::query()->where('type', 'operator.session_ended')->latest('sequence')->first()?->data['reason']);
    }

    public function test_removing_a_passkey_ends_the_sessions_signed_in_with_it(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $first = $this->sessionId;

        // 予備のキーを足し、それで別の端末から入る
        $this->reconfirm($operator, $authenticator);
        $spare = $this->newAuthenticator();
        $this->send('POST', '/auth/passkeys', ['name' => '予備のキー', 'credential' => $spare->register($this->ceremonyOptions('/auth/passkeys/options'))])->assertCreated();
        $stolen = $this->signIn($operator, $spare, self::IPHONE);
        // 元のキーでもう 1 つ（消さないキーの画面は残る）
        $other = $this->signIn($operator, $authenticator, self::MAC);

        // 最初の画面から予備のキーを消す
        $this->switchTo($first);
        $this->reconfirm($operator, $authenticator);
        $spareId = (string) $operator->passkeys()->where('name', '予備のキー')->value('id');
        $this->send('DELETE', "/auth/passkeys/{$spareId}")->assertNoContent();

        $this->switchTo($stolen);
        $this->send('GET', '/auth/me')->assertUnauthorized();
        $this->switchTo($other);
        $this->send('GET', '/auth/me')->assertOk();
        $this->switchTo($first);
        $this->send('GET', '/auth/me')->assertOk();
        $this->assertSame(1, DB::table('operator_sessions')->where('end_reason', 'passkey_removed')->count());
    }

    public function test_the_passkey_used_by_this_screen_can_be_removed_without_signing_it_out(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($operator, $authenticator);
        $spare = $this->newAuthenticator();
        $this->send('POST', '/auth/passkeys', ['name' => '予備のキー', 'credential' => $spare->register($this->ceremonyOptions('/auth/passkeys/options'))])->assertCreated();

        // 元のキーで入った画面から、元のキーを消す: この画面は残す（消した本人）。元のキーで入った別の画面は切れる
        $before = $this->sessionId;
        $elsewhere = $this->signIn($operator, $authenticator, self::IPHONE);
        $this->switchTo($before);
        $this->reconfirm($operator, $authenticator);
        $mainId = (string) $operator->passkeys()->where('name', 'MacBook')->value('id');
        $this->send('DELETE', "/auth/passkeys/{$mainId}")->assertNoContent();

        $this->send('GET', '/auth/me')->assertOk();
        $this->switchTo($elsewhere);
        $this->send('GET', '/auth/me')->assertUnauthorized();
    }

    public function test_ending_one_session_only_touches_your_own_and_not_this_screen(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $mac = $this->sessionId;
        $phone = $this->signIn($operator, $authenticator, self::IPHONE);
        $phoneRow = (string) DB::table('operator_sessions')->where('device', 'Safari / iPhone')->value('id');

        // ほかの運営の画面
        $this->freshBrowser();
        $this->withHeaders(['User-Agent' => self::MAC]);
        $other = Operator::create(['name' => 'Staff', 'email' => 'staff@example.com']);
        $this->enroll(app(OperatorEnrollments::class)->issue($other, 'test')['token'], $this->newAuthenticator());
        $otherRow = (string) DB::table('operator_sessions')->where('operator_id', $other->id)->value('id');
        $otherSession = $this->sessionId;

        $this->switchTo($mac);
        $current = $this->listed('current', true)['id'];
        $this->send('DELETE', "/auth/sessions/{$current}")->assertStatus(422);
        $this->send('DELETE', "/auth/sessions/{$otherRow}")->assertNotFound();
        $this->send('DELETE', "/auth/sessions/{$phoneRow}")->assertNoContent();
        // 終えた行をもう一度は見つからない
        $this->send('DELETE', "/auth/sessions/{$phoneRow}")->assertNotFound();

        $this->switchTo($phone);
        $this->send('GET', '/auth/me')->assertUnauthorized();
        $this->switchTo($otherSession);
        $this->send('GET', '/auth/me')->assertOk();
        $this->switchTo($mac);
        $this->send('GET', '/auth/me')->assertOk();
    }

    public function test_a_missing_row_signs_the_screen_out(): void
    {
        // この仕組みの前のログイン（行の無いセッション）も、行を消された画面も、ログインし直させる
        [$operator] = $this->enrolledOperator();
        DB::table('operator_sessions')->where('operator_id', $operator->id)->delete();

        $this->send('GET', '/auth/me')->assertUnauthorized();
    }

    public function test_disabling_an_operator_ends_every_session(): void
    {
        $this->artisan('operator', ['action' => 'create', 'email' => 'owner@example.com', '--name' => 'オーナー'])->assertSuccessful();
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        $first = $this->sessionId;
        $this->signIn($staff, $authenticator, self::IPHONE);

        $this->artisan('operator', ['action' => 'disable', 'email' => 'staff@example.com'])->assertSuccessful();

        $this->assertSame(0, DB::table('operator_sessions')->where('operator_id', $staff->id)->whereNull('ended_at')->count());
        $this->assertSame(2, DB::table('operator_sessions')->where('operator_id', $staff->id)->where('end_reason', 'disabled')->count());
        $this->switchTo($first);
        $this->send('GET', '/auth/me')->assertUnauthorized();
    }

    public function test_an_owner_disabling_a_member_ends_their_sessions(): void
    {
        [$owner, $ownerKey] = $this->enrolledOperator();
        $ownerSession = $this->sessionId;

        $this->freshBrowser();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $staffSession = $this->sessionId;

        $this->switchTo($ownerSession);
        $this->reconfirm($owner, $ownerKey);
        $this->send('PUT', "/api/members/{$staff->id}/status", ['active' => false])->assertOk();

        $this->assertSame('disabled', DB::table('operator_sessions')->where('operator_id', $staff->id)->value('end_reason'));
        $this->switchTo($staffSession);
        $this->send('GET', '/auth/me')->assertUnauthorized();
    }

    public function test_an_expired_session_is_ended_and_leaves_the_list(): void
    {
        [$operator] = $this->enrolledOperator();
        config(['admin.session_idle_minutes' => 24 * 60]);
        $this->travel(13)->hours();

        $this->assertSame([], app(OperatorSessions::class)->active($operator, null));
        $this->send('GET', '/auth/me')->assertUnauthorized();
        $this->assertSame('expired', DB::table('operator_sessions')->where('operator_id', $operator->id)->value('end_reason'));
    }

    public function test_last_seen_is_written_at_most_once_a_minute(): void
    {
        [$operator] = $this->enrolledOperator();
        $seen = fn (): string => (string) DB::table('operator_sessions')->where('operator_id', $operator->id)->value('last_seen_at');
        $start = $seen();

        $this->travel(30)->seconds();
        $this->send('GET', '/auth/me')->assertOk();
        $this->assertSame($start, $seen());

        $this->travel(31)->seconds();
        $this->send('GET', '/auth/me')->assertOk();
        $this->assertNotSame($start, $seen());
    }

    public function test_a_sign_in_from_an_unusual_place_is_marked_and_shown_to_the_other_screens(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        [$operator, $authenticator] = $this->enrolledOperator();
        // いつもの回線から入った記録（監査ログ）を残す
        $home = $this->signIn($operator, $authenticator, self::MAC);
        $this->send('GET', '/auth/me')->assertOk()->assertJsonPath('unusual_session', null);

        // 知らない回線から入る
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);
        $away = $this->signIn($operator, $authenticator, self::IPHONE);
        // 入った本人の画面には出さない（自分が出先から入っただけ）
        $this->send('GET', '/auth/me')->assertOk()->assertJsonPath('unusual_session', null);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->switchTo($home);
        $notice = $this->send('GET', '/auth/me')->assertOk()->json('unusual_session');
        $this->assertSame('Safari / iPhone', $notice['device']);
        $this->assertTrue($this->listed('id', $notice['id'])['unusual']);

        // 心当たりが無いので終える → 知らせも消える
        $this->send('DELETE', "/auth/sessions/{$notice['id']}")->assertNoContent();
        $this->send('GET', '/auth/me')->assertJsonPath('unusual_session', null);
        $this->switchTo($away);
        $this->send('GET', '/auth/me')->assertUnauthorized();
    }

    public function test_device_names_keep_only_the_browser_and_the_system(): void
    {
        $cases = [
            [self::MAC, 'Chrome / Mac'],
            [self::IPHONE, 'Safari / iPhone'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'Edge / Windows'],
            ['Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0', 'Firefox / Linux'],
            ['Mozilla/5.0 (Linux; Android 16) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0 Mobile Safari/537.36', 'Chrome / Android'],
            ['Mozilla/5.0 (iPad; CPU OS 19_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/142.0 Mobile/15E148 Safari/604.1', 'Chrome / iPad'],
            ['<script>alert(1)</script>', 'Browser / Unknown'],
            [null, 'Browser / Unknown'],
        ];

        foreach ($cases as [$userAgent, $expected]) {
            $this->assertSame($expected, OperatorSessions::device($userAgent));
        }
    }

    /**
     * ログイン中の端末の一覧から、$key が $value の 1 つ
     *
     * @return array<string, mixed>
     */
    private function listed(string $key, mixed $value): array
    {
        $sessions = $this->send('GET', '/auth/sessions')->assertOk()->json('sessions');
        $this->assertIsArray($sessions);

        foreach ($sessions as $session) {
            if (is_array($session) && ($session[$key] ?? null) === $value) {
                return $session;
            }
        }

        $this->fail("一覧に {$key} の合う端末が無い");
    }

    /**
     * 別の画面に持ち替える（テストの中では認証の部品とセッションの入れ物が前の要求の中身を覚えたままなので、それも忘れさせる。
     * 保存済みの各セッションは array の置き場に残る）
     */
    private function switchTo(string $sessionId): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->sessionId = $sessionId;
    }

    /**
     * 別の端末（Cookie を持たないブラウザ）にする
     */
    private function freshBrowser(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->sessionId = null;
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->resetRateLimit();
    }

    /**
     * 別の端末として入り直す（Cookie を持たずにログインし、そのセッションの番号を返す）
     */
    private function signIn(Operator $operator, SoftwareAuthenticator $authenticator, string $userAgent): string
    {
        $this->freshBrowser();
        $this->withHeaders(['User-Agent' => $userAgent]);
        $assertion = $authenticator->assert($this->ceremonyOptions('/auth/login/options'), $operator->getPasskeyUserHandle());
        $this->send('POST', '/auth/login', ['credential' => $assertion])->assertOk();

        return (string) $this->sessionId;
    }
}
