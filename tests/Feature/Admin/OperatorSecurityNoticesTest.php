<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Mail\OperatorSecurityMail;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorEnrollments;
use App\Admin\Services\OperatorSecurityNotices;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * 運営のログインまわりの本人への知らせ（2）。パスキーを足した・消した・いつもと違う接続元から入った、を本人のメールへ。
 * 初めての登録と初めてのログインは知らせない。メールに接続元を載せない
 */
final class OperatorSecurityNoticesTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureAdminHost();
        Mail::fake();
    }

    public function test_adding_and_removing_a_passkey_tells_the_owner_but_the_first_enrollment_does_not(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();

        // 初めての登録は本人がしたばかりなので知らせない
        Mail::assertNotQueued(OperatorSecurityMail::class, static fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::PASSKEY_ADDED);

        // 2 本目を足す: 本人へ、足したパスキーの名前つきで
        $this->reconfirm($operator, $authenticator);
        $options = $this->ceremonyOptions('/auth/passkeys/options');
        $this->send('POST', '/auth/passkeys', ['name' => 'iPhone', 'credential' => $this->newAuthenticator()->register($options)])->assertCreated();

        Mail::assertQueued(OperatorSecurityMail::class, static fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::PASSKEY_ADDED
            && $mail->hasTo('hana@example.com') && $mail->subjectName === 'iPhone');

        // 1 本目を消す: 本人へ
        $first = $operator->passkeys()->orderBy('created_at')->firstOrFail();
        $this->reconfirm($operator, $authenticator);
        $this->send('DELETE', '/auth/passkeys/'.$first->getKey())->assertNoContent();

        Mail::assertQueued(OperatorSecurityMail::class, static fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::PASSKEY_REMOVED
            && $mail->hasTo('hana@example.com') && $mail->subjectName === 'MacBook');

        // 消せなかった（最後の 1 本）ときは知らせない
        $count = count(Mail::queued(OperatorSecurityMail::class, static fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::PASSKEY_REMOVED));
        $last = $operator->passkeys()->firstOrFail();
        $this->resetRateLimit();
        $this->send('DELETE', '/auth/passkeys/'.$last->getKey())->assertStatus(422);
        $this->assertCount($count, Mail::queued(OperatorSecurityMail::class, static fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::PASSKEY_REMOVED));
    }

    public function test_a_passkey_added_through_an_enrollment_link_is_reported_when_one_already_exists(): void
    {
        [$operator] = $this->enrolledOperator();
        $this->send('POST', '/auth/logout')->assertNoContent();

        // サーバーやオーナーが出した登録のリンクで、別の端末のパスキーが足された
        $this->enroll(app(OperatorEnrollments::class)->issue($operator, 'test')['token'], $this->newAuthenticator());

        Mail::assertQueued(OperatorSecurityMail::class, static fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::PASSKEY_ADDED
            && $mail->hasTo('hana@example.com'));
    }

    public function test_a_login_from_an_unseen_network_is_reported(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 登録した時と同じ回線（127.0.0.1）からのログイン: 知らせない
        $this->loginFrom('127.0.0.1', $operator, $authenticator);
        $this->assertNewLocationMails(0);

        // 同じ /24 の別の番号: 知らせない
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->loginFrom('127.0.0.9', $operator, $authenticator);
        $this->assertNewLocationMails(0);

        // 見たことの無い回線: 知らせる。メールに接続元を載せない
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->loginFrom('203.0.113.7', $operator, $authenticator);
        $this->assertNewLocationMails(1);
        Mail::assertQueued(OperatorSecurityMail::class, function (OperatorSecurityMail $mail): bool {
            if ($mail->kind !== OperatorSecurityMail::NEW_LOCATION_LOGIN) {
                return false;
            }

            $text = $mail->render();
            $this->assertStringNotContainsString('203.0.113', $text);
            $this->assertStringContainsString('ログインがありました', $text);

            return $mail->hasTo('hana@example.com');
        });

        // 一度入った回線からは、もう知らせない
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->loginFrom('203.0.113.200', $operator, $authenticator);
        $this->assertNewLocationMails(1);
    }

    public function test_only_the_last_90_days_count_and_ipv6_is_grouped_by_64(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 120 日前に入った回線は数えない（監査ログは追記専用で書き換えられないので、時計を戻してログインの記録を残す。記録の部品は本物と同じ）
        $this->travelTo(CarbonImmutable::now()->subDays(120));
        app(AuditLog::class)->record('operator.login_succeeded', $operator, ['method' => 'passkey'], ip: '198.51.100.4');
        $this->travelBack();
        $before = count(Mail::queued(OperatorSecurityMail::class, static fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::NEW_LOCATION_LOGIN));
        $this->loginFrom('198.51.100.5', $operator, $authenticator);
        $this->assertNewLocationMails($before + 1);

        // IPv6 は /64 でまとめる
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->loginFrom('2001:db8:1:2::10', $operator, $authenticator);
        $this->assertNewLocationMails($before + 2);
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->loginFrom('2001:db8:1:2:aaaa::1', $operator, $authenticator);
        $this->assertNewLocationMails($before + 2);
    }

    public function test_the_network_is_the_24_or_64_prefix(): void
    {
        $this->assertSame(OperatorSecurityNotices::network('192.0.2.1'), OperatorSecurityNotices::network('192.0.2.254'));
        $this->assertNotSame(OperatorSecurityNotices::network('192.0.2.1'), OperatorSecurityNotices::network('192.0.3.1'));
        $this->assertSame(OperatorSecurityNotices::network('2001:db8::1'), OperatorSecurityNotices::network('2001:db8::ffff:1'));
        $this->assertNotSame(OperatorSecurityNotices::network('2001:db8:0:1::1'), OperatorSecurityNotices::network('2001:db8:0:2::1'));
        // IPv4 を IPv6 に写した形は IPv4 として扱う
        $this->assertSame(OperatorSecurityNotices::network('192.0.2.1'), OperatorSecurityNotices::network('::ffff:192.0.2.9'));
        $this->assertNull(OperatorSecurityNotices::network(null));
        $this->assertNull(OperatorSecurityNotices::network('not-an-ip'));
        $this->assertNull(OperatorSecurityNotices::network(''));
    }

    private function loginFrom(string $ip, Operator $operator, SoftwareAuthenticator $authenticator): void
    {
        $this->resetRateLimit();
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $assertion = $authenticator->assert($this->ceremonyOptions('/auth/login/options'), $operator->getPasskeyUserHandle());
        $this->send('POST', '/auth/login', ['credential' => $assertion])->assertOk();
    }

    private function assertNewLocationMails(int $expected): void
    {
        $this->assertCount($expected, Mail::queued(OperatorSecurityMail::class, static fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::NEW_LOCATION_LOGIN));
    }
}
