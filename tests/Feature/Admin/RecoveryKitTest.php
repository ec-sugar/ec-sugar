<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\AppKeyBackup;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\RecoveryKit;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面で作る復元のキット（ADR 0111）のサーバーの側。
 *
 * 守ること: APP_KEY を受け取れるのはオーナーだけ・直前の再確認の後だけ・渡すたびに監査ログ。
 * 開ける鍵の公開の半分は、鍵そのものから数えた指紋と、開け直したキットの APP_KEY から作った印が合う時だけ受ける。
 * 合い言葉・秘密の鍵・キットそのものはどこにも残らない
 */
final class RecoveryKitTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    /** tests/Support/fixtures/recovery-kit-public.asc の指紋（gpg --show-keys と同じ値。テストのためだけの鍵で、秘密の半分は捨ててある） */
    private const string FINGERPRINT = 'FF14D9B119B27A8850228B7DD4F3B7D4ADCB18CC';

    private const string MATERIAL = '/api/operations/recovery-kit/material';

    private const string REGISTER = '/api/operations/recovery-kit';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_only_an_owner_after_a_fresh_confirmation_receives_the_app_key_and_every_issue_is_audited(): void
    {
        $before = (string) AuditEvent::query()->max('id');
        $audits = static fn () => AuditEvent::query()->where('type', RecoveryKit::AUDIT_MATERIAL)->where('id', '>', $before);

        // ログインしていなければ 401
        $this->send('POST', self::MATERIAL)->assertUnauthorized();

        // スタッフは、お金の段階まで与えられていても・再確認の直後でも 403（答えに鍵が無い）
        $this->tenant('rkw');
        [$staff, $staffKey] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'rkw', 'money', 'test');
        $this->reconfirm($staff, $staffKey);
        $denied = $this->send('POST', self::MATERIAL)->assertForbidden();
        $this->assertStringNotContainsString('base64:', (string) $denied->getContent());
        $this->send('GET', self::REGISTER)->assertForbidden();
        $this->send('POST', self::REGISTER, ['public_key' => $this->publicKey(), 'fingerprint' => self::FINGERPRINT, 'proof' => RecoveryKit::proof((string) config('app.key'), self::FINGERPRINT)])->assertForbidden();
        $this->assertSame(0, DB::table('recovery_kit_recipients')->count());
        $this->send('POST', '/auth/logout')->assertSuccessful();
        $this->resetRateLimit();

        // オーナーでも、直前の再確認が無ければ渡さない（423）。監査ログも増えない
        [$owner, $ownerKey] = $this->enrolledOperator('owner@example.com');
        $locked = $this->send('POST', self::MATERIAL)->assertStatus(423);
        $this->assertStringNotContainsString('base64:', (string) $locked->getContent());
        $this->assertSame(0, $audits()->count());

        // 再確認の後は渡す。答えはブラウザにも途中の機械にも残させない
        $this->reconfirm($owner, $ownerKey);
        $ok = $this->send('POST', self::MATERIAL)->assertOk();
        $this->assertSame(['app_key', 'server'], array_keys($ok->json()));
        $this->assertSame(config('app.key'), $ok->json('app_key'));
        $this->assertStringContainsString('no-store', (string) $ok->headers->get('Cache-Control'));

        // 監査の記録は「誰が・いつ・どこから」だけ（中身は空。鍵は載らない）
        $audit = $audits()->sole();
        $this->assertSame([$owner->id, null, []], [$audit->operator_id, $audit->tenant_id, $audit->data]);

        // 1 回の本人確認で渡すのは 1 回だけ（別の用事の再確認の後の 5 分に、乗っ取られたセッションが黙って持ち出せない）
        $again = $this->send('POST', self::MATERIAL)->assertStatus(423);
        $this->assertStringNotContainsString('base64:', (string) $again->getContent());
        $this->assertSame(1, $audits()->count());

        // もう一度本人確認をすれば渡す。渡すたびに監査の記録が 1 件増える
        $this->resetRateLimit();
        $this->reconfirm($owner, $ownerKey);
        $this->send('POST', self::MATERIAL)->assertOk();
        $this->assertSame(2, $audits()->count());
        $this->assertTrue(app(AuditLog::class)->verify()->intact());

        // わざと壊す確かめ: 様子を読む口（GET）は鍵を返さない
        $this->assertStringNotContainsString('base64:', (string) $this->send('GET', self::REGISTER)->assertOk()->getContent());
    }

    public function test_an_app_key_the_kit_cannot_hold_is_not_issued(): void
    {
        [$owner, $ownerKey] = $this->enrolledOperator('owner@example.com');
        $this->reconfirm($owner, $ownerKey);
        $before = (string) AuditEvent::query()->max('id');
        $real = config('app.key');

        // base64: の 32 バイトでない鍵は、キットの道具が受けない。渡さず、監査ログも残さない
        config()->set('app.key', 'not-a-laravel-key');
        $this->assertNull(RecoveryKit::appKey());
        // （HTTP は通さない: APP_KEY を変えると、セッションの Cookie の暗号も変わって、口に届く前に落ちる）
        $this->assertNull(RecoveryKit::material($owner, app(AuditLog::class), 'admin.localhost'));
        config()->set('app.key', $real);
        $this->assertSame($real, RecoveryKit::appKey());
        // 形の違うサーバーの名前でも渡さない
        $this->assertNull(RecoveryKit::material($owner, app(AuditLog::class), 'bad host/../x'));
        $this->assertSame(0, AuditEvent::query()->where('type', RecoveryKit::AUDIT_MATERIAL)->where('id', '>', $before)->count());
        $this->assertTrue($this->send('GET', self::REGISTER)->assertOk()->json('available'));
    }

    public function test_the_public_half_is_accepted_only_with_a_proof_made_from_the_reopened_kit(): void
    {
        $this->artisan('operations:host-report', ['--disk' => ['data,40,1000']])->assertSuccessful();
        [$owner, $ownerKey] = $this->enrolledOperator('owner@example.com');
        $key = $this->publicKey();
        $proof = RecoveryKit::proof((string) config('app.key'), self::FINGERPRINT);
        $body = ['public_key' => $key, 'fingerprint' => self::FINGERPRINT, 'proof' => $proof];
        $before = (string) AuditEvent::query()->max('id');
        $audits = static fn () => AuditEvent::query()->where('type', RecoveryKit::AUDIT_REGISTERED)->where('id', '>', $before);

        // 直前の再確認が無ければ受けない
        $this->send('POST', self::REGISTER, $body)->assertStatus(423);
        $this->reconfirm($owner, $ownerKey);

        // 断るもの。どれも何も残さず、APP_KEY の控えの答えも付かない
        $other = strrev(self::FINGERPRINT);
        $refused = [
            '別の APP_KEY から作った印' => ['proof' => RecoveryKit::proof('base64:'.base64_encode(str_repeat('x', 32)), self::FINGERPRINT)] + $body,
            '別の指紋のための印' => ['proof' => RecoveryKit::proof((string) config('app.key'), $other)] + $body,
            '鍵と違う指紋' => ['fingerprint' => $other, 'proof' => RecoveryKit::proof((string) config('app.key'), $other)] + $body,
            '秘密の鍵の包み' => ['public_key' => str_replace('PUBLIC', 'PRIVATE', $key)] + $body,
            '包みが 2 つ' => ['public_key' => $key.$key] + $body,
            '包みの外に字' => ['public_key' => "rm -rf /\n".$key] + $body,
            // 最初の 1 字を変えると、最初のパケットが公開鍵でなくなる
            '公開鍵のパケットで始まらない包み' => ['public_key' => str_replace("\n\nx", "\n\nw", $key)] + $body,
        ];

        foreach ($refused as $what => $bad) {
            $this->resetRateLimit();
            $this->send('POST', self::REGISTER, $bad)->assertStatus(422);
            $this->assertSame(0, DB::table('recovery_kit_recipients')->count(), $what);
            $this->assertNull(AppKeyBackup::confirmedAt(), $what);
        }

        foreach (['fingerprint' => 'abc', 'proof' => 'zz', 'public_key' => str_repeat('A', 9000)] as $field => $value) {
            $this->resetRateLimit();
            $this->send('POST', self::REGISTER, [$field => $value] + $body)->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, $audits()->count());
        $this->resetRateLimit();

        // 合えば受ける。答えは公開してよい値だけで、APP_KEY の控えの困りごとも消える
        $ok = $this->send('POST', self::REGISTER, $body)->assertOk();
        $this->assertSame(['available', 'fingerprint', 'registered_at', 'host_fingerprint', 'confirmed_at'], array_keys($ok->json()));
        $this->assertNull($ok->json('host_fingerprint'));
        $this->assertSame(self::FINGERPRINT, $ok->json('fingerprint'));
        $this->assertNotNull($ok->json('confirmed_at'));
        $this->assertStringNotContainsString('base64:', (string) $ok->getContent());
        $issues = array_column($this->send('GET', '/api/operations')->assertOk()->json('issues'), 'key');
        $this->assertNotContains(AppKeyBackup::ISSUE, $issues);

        // 残るのは公開鍵・指紋・いつ・誰が。監査ログには指紋（前の鍵は無い）
        $row = DB::table('recovery_kit_recipients')->sole();
        $this->assertSame(['id', 'fingerprint', 'public_key', 'registered_at', 'operator_id'], array_keys((array) $row));
        $this->assertSame([1, self::FINGERPRINT, $owner->id], [$row->id, $row->fingerprint, $row->operator_id]);
        $this->assertEquals(['fingerprint' => self::FINGERPRINT, 'replaced' => ''], $audits()->sole()->data);

        // 同じ鍵をもう一度送っても増えない
        $this->send('POST', self::REGISTER, $body)->assertOk();
        $this->assertSame(1, $audits()->count());
        $this->assertTrue(app(AuditLog::class)->verify()->intact());

        // ホストの ec-sugar が聞く口: 1 行目に指紋、2 行目から公開鍵
        Artisan::call('operations:recovery-recipient');
        $this->assertSame(self::FINGERPRINT."\n".trim($key)."\n", Artisan::output());
    }

    public function test_the_host_reports_the_key_it_encrypts_with_so_the_screen_can_tell_a_different_kit(): void
    {
        $this->enrolledOperator('owner@example.com');

        // 形の違う指紋は控えない（終わりの値 2）
        foreach (['abc', strtolower(self::FINGERPRINT), self::FINGERPRINT.';rm -rf /', str_repeat('A', 65)] as $bad) {
            $this->assertSame(2, Artisan::call('operations:recovery-recipient', ['--host-fingerprint' => $bad]));
        }

        $this->assertSame(0, DB::table('recovery_kit_host_keys')->count());
        $this->assertNull($this->send('GET', self::REGISTER)->assertOk()->json('host_fingerprint'));

        // ホストがいま使っている鍵の指紋を知らせると、画面の口が返す（知らせる時は公開鍵を出さない）
        $other = strrev(self::FINGERPRINT);
        $this->assertSame(0, Artisan::call('operations:recovery-recipient', ['--host-fingerprint' => $other]));
        $this->assertSame('', Artisan::output());
        $this->assertSame($other, $this->send('GET', self::REGISTER)->assertOk()->json('host_fingerprint'));

        // 鍵が替われば置き換わる（1 行だけ）
        $this->assertSame(0, Artisan::call('operations:recovery-recipient', ['--host-fingerprint' => self::FINGERPRINT]));
        $this->assertSame([self::FINGERPRINT], DB::table('recovery_kit_host_keys')->pluck('fingerprint')->all());
    }

    public function test_the_host_is_told_nothing_while_no_kit_was_made(): void
    {
        Artisan::call('operations:recovery-recipient');
        $this->assertSame('', Artisan::output());
        $this->assertNull(RecoveryKit::recipient());
    }

    public function test_the_fingerprint_is_counted_from_the_key_itself(): void
    {
        $key = $this->publicKey();
        $this->assertSame(self::FINGERPRINT, RecoveryKit::fingerprintOf($key));
        // Windows の改行でも同じ（短すぎる・長すぎる包みは、DB の制約に当たる前にここで断る）
        $this->assertSame(self::FINGERPRINT, RecoveryKit::fingerprintOf(str_replace("\n", "\r\n", $key)));

        foreach (['', 'hello', "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\n!!!!\n-----END PGP PUBLIC KEY BLOCK-----", str_replace('PUBLIC', 'PRIVATE', $key), $key."\n".$key] as $bad) {
            try {
                RecoveryKit::fingerprintOf($bad);
                $this->fail('受けてはいけない形を受けた');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function publicKey(): string
    {
        return (string) file_get_contents(base_path('tests/Support/fixtures/recovery-kit-public.asc'));
    }
}
