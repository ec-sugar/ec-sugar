<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Mail\OperationsAlertMail;
use App\Admin\Models\Incident;
use App\Admin\Models\Operator;
use App\Admin\Services\AppKeyBackup;
use App\Admin\Services\AuditLog;
use App\Admin\Services\Incidents;
use App\Admin\Services\OperatorAccess;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 壊れたことの知らせ（ADR 0043）: 最初はすぐ 1 通・同じものは 1 時間に 1 通まで・増えていなければ送らない、
 * 止めたオーナーとスタッフには送らない、メールにメッセージやメールアドレスが無い、送れなければ次の回にまた送る、
 * 困りごとは出ている間 1 回だけ。画面の API（要対応の件数・エラーの節）と /up の移行の流し忘れ
 */
final class OperationsAlertTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string SECRET_NAME = '山田 太郎';

    private const string SECRET_EMAIL = 'yamada@example.com';

    /** @var list<Tenant> 店の DB を作らずに中央の行だけ作った店 */
    private array $bareShops = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        foreach ($this->bareShops as $shop) {
            Tenant::withoutEvents(static fn () => $shop->delete());
        }

        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_first_at_once_then_at_most_hourly_and_only_when_it_grew(): void
    {
        Mail::fake();
        $this->owner('owner@example.com');
        $start = CarbonImmutable::parse('2026-10-30 12:00', 'Asia/Tokyo');
        $this->travelTo($start);
        $this->boom();

        // 最初はすぐ 1 通（キューを通さず直に）
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertNothingQueued();
        $incident = Incident::query()->sole();
        $this->assertSame(1, $incident->notified_count);
        $this->assertNotNull($incident->notified_at);

        // 同じものが 30 分後にまた起きても、1 時間のうちは送らない
        $this->travelTo($start->addMinutes(30));
        $this->boom();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);

        // 1 時間を過ぎて増えていれば送る（前の知らせからの回数つき）
        $this->travelTo($start->addMinutes(61));
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->incidents[0]['added'] === 1 && $mail->incidents[0]['count'] === 2);
        $this->assertSame(2, Incident::query()->sole()->notified_count);

        // 増えていなければ、何時間たっても送らない
        $this->travelTo($start->addHours(5));
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
    }

    public function test_only_active_owners_receive_it(): void
    {
        Mail::fake();
        $this->tenant('alq');
        $this->owner('owner1@example.com');
        $this->owner('owner2@example.com');
        $disabled = $this->owner('stopped@example.com');
        $disabled->forceFill(['disabled_at' => now()])->save();
        $staff = Operator::create(['name' => 'スタッフ', 'email' => 'staff@example.com']);
        app(OperatorAccess::class)->grant($staff, 'alq', 'money', 'test');
        $this->boom();

        $this->artisan('operations:alert')->assertSuccessful();

        // 1 人 1 通
        Mail::assertSentCount(2);
        foreach (['owner1@example.com', 'owner2@example.com'] as $email) {
            Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->hasTo($email));
        }
        foreach (['stopped@example.com', 'staff@example.com'] as $email) {
            Mail::assertNotSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->hasTo($email));
        }
    }

    public function test_the_mail_has_no_message_or_email_address_and_is_sent_again_after_a_failure(): void
    {
        $this->owner('owner@example.com');
        $this->boom();

        // 送れない（メールの相手に届かない）。印を付けず、失敗で終わる
        config(['mail.mailers.t0096_broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2]]);
        config(['mail.default' => 't0096_broken']);
        $this->artisan('operations:alert')->assertFailed();
        $this->assertNull(Incident::query()->sole()->notified_at);

        // 次の回に送れる
        config(['mail.default' => 'array']);
        $this->artisan('operations:alert')->assertSuccessful();
        $this->assertNotNull(Incident::query()->sole()->notified_at);

        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $messages = $transport->messages();
        $this->assertCount(1, $messages);
        $sent = $messages[0];
        $this->assertInstanceOf(SentMessage::class, $sent);
        $email = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('[EC-SUGAR] 動作に問題があります（1 件）', $email->getSubject());
        $body = (string) $email->getTextBody();
        // どこで・何が・何回
        $this->assertStringContainsString('画面や API のエラー', $body);
        $this->assertStringContainsString('RuntimeException（tests/Feature/Admin/OperationsAlertTest.php:', $body);
        $this->assertStringContainsString('1 回・最初 ', $body);
        $this->assertStringContainsString('/operations', $body);

        // 例外のメッセージ（個人情報）は載らない
        foreach ([self::SECRET_NAME, self::SECRET_EMAIL] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString($secret, (string) $email->getSubject());
        }
    }

    public function test_a_trouble_is_told_once_while_it_lasts_and_again_when_it_comes_back(): void
    {
        Mail::fake();
        $this->owner('owner@example.com');
        $shop = $this->bareShop('alr', '困った商店');
        $this->digest($shop, stray: 1);

        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['「困った商店」に返金が必要な支払いが 1 件あります'] && $mail->incidents === []);

        // 出ている間は 1 回だけ（数が増えても）
        $this->digest($shop, stray: 2);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);

        // 消えたら忘れる（送るものは無い）
        $this->digest($shop, stray: 0);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame(0, DB::table('alerted_issues')->count());

        // 出直したら、また知らせる
        $this->digest($shop, stray: 1);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
    }

    public function test_the_app_key_backup_reminder_is_mailed_once_and_not_again_while_it_stays(): void
    {
        $owner = $this->owner('owner@example.com');
        $at = CarbonImmutable::now()->subMinutes(5)->toIso8601String();
        // ec-sugar で入れたサーバー。空きが少ない（近いうちに）も一緒に出ているが、メールで知らせる「近いうちに」は APP_KEY の控えだけ
        $this->artisan('operations:host-report', ['--disk' => ['data,14,1000'], '--update-result' => 'ok', '--update-at' => $at, '--backup-result' => 'ok', '--backup-at' => $at])->assertSuccessful();

        $this->artisan('operations:alert')->assertSuccessful();
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $sent = $transport->messages()[0];
        $this->assertInstanceOf(SentMessage::class, $sent);
        $email = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        // 壊れたと読める題にしない。本文は「近いうちに」の節に 1 行で、取り方（サーバーのコマンド）を案内する
        $this->assertSame('[EC-SUGAR] 近いうちに確認してほしいことがあります（1 件）', $email->getSubject());
        $body = (string) $email->getTextBody();
        $this->assertStringContainsString("■ 近いうちに\n・復元のキットをまだ作っていません。サーバーを失うと、バックアップがあってもお客さんの個人情報を復号できません", $body);
        $this->assertStringContainsString('sudo ec-sugar app-key', $body);
        $this->assertStringNotContainsString('■ 要対応', $body);
        $this->assertStringNotContainsString('動作に問題があります', $body);
        $this->assertStringNotContainsString('空きが', $body);
        // 鍵は載らない（鍵の形の文字も無い）
        $this->assertStringNotContainsString('base64:', $body);

        // 出ている間は 1 回だけ（毎分の回でも、日が経っても、送り続けない）。運営の様子には出たまま
        $this->artisan('operations:alert')->assertSuccessful();
        $this->travel(1)->days();
        $this->artisan('operations:alert')->assertSuccessful();
        $this->assertCount(1, $transport->messages());
        $this->assertSame(1, DB::table('alerted_issues')->where('signature', 'app_key_backup_unconfirmed:')->count());

        // 答えたら忘れる（送るものは無い）
        $this->assertTrue(AppKeyBackup::confirm($owner, CarbonImmutable::now(), app(AuditLog::class)));
        $this->artisan('operations:alert')->assertSuccessful();
        $this->assertCount(1, $transport->messages());
        $this->assertSame(0, DB::table('alerted_issues')->count());
    }

    public function test_the_reminder_rides_in_its_own_section_next_to_an_urgent_trouble(): void
    {
        Mail::fake();
        $this->owner('owner@example.com');
        $this->artisan('operations:host-report', ['--disk' => ['data,40,1000']])->assertSuccessful();
        $this->digest($this->bareShop('alu', '困った商店'), stray: 1);

        // 要対応の文は今までどおり issues に、APP_KEY の控えは reminders に（要対応に混ぜない）
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['「困った商店」に返金が必要な支払いが 1 件あります']
            && count($mail->reminders) === 1 && str_starts_with($mail->reminders[0], '復元のキットをまだ作っていません。'));

        // ホストの報告が無い（手元の開発）なら、知らせない
        DB::table('host_checks')->delete();
        DB::table('alerted_issues')->delete();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->reminders === []);
    }

    public function test_old_records_are_pruned_at_night(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01 12:00', 'Asia/Tokyo'));
        $this->boom();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00', 'Asia/Tokyo'));
        Incidents::record(Incidents::JOB_FAILED, new RuntimeException('x'));

        $this->artisan('operations:alert', ['--prune' => true])->assertSuccessful();
        $this->assertSame([Incidents::JOB_FAILED], Incident::query()->pluck('kind')->all());
    }

    public function test_the_alerts_api_is_for_owners_only_and_counts_urgent_troubles(): void
    {
        $this->tenant('als');

        // ログインしていなければ 401、スタッフは 403
        $this->send('GET', '/api/operations/alerts')->assertUnauthorized();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'als', 'money', 'test');
        $this->send('GET', '/api/operations/alerts')->assertForbidden();

        $this->signOut();
        $this->enrolledOperator('owner@example.com');
        $before = $this->send('GET', '/api/operations/alerts')->assertOk()->json('urgent');
        $this->assertIsInt($before);

        // この 24 時間のエラーは要対応の 1 行
        $this->boom();
        $this->assertSame($before + 1, $this->send('GET', '/api/operations/alerts')->assertOk()->json('urgent'));
        $this->assertSame(['urgent'], array_keys($this->send('GET', '/api/operations/alerts')->json()));
    }

    public function test_the_overview_lists_this_weeks_errors_newest_first_and_the_last_day_is_urgent(): void
    {
        $now = CarbonImmutable::parse('2026-10-30 12:00', 'Asia/Tokyo');
        $shop = $this->bareShop('alt', 'みどり商店');

        // 8 日前（並べない）・2 日前（並べるが要対応ではない）・1 時間前（店の中）
        $this->travelTo($now->subDays(8));
        Incidents::record(Incidents::JOB_FAILED, new LogicException('old'));
        $this->travelTo($now->subDays(2));
        Incidents::record(Incidents::COMMAND_FAILED, new RuntimeException('x'), location: 'artisan operations:collect');
        $this->travelTo($now->subHour());
        tenancy()->initialize($shop);

        try {
            Incidents::record(Incidents::HTTP_ERROR, new RuntimeException(self::SECRET_EMAIL));
        } finally {
            tenancy()->end();
        }

        $this->travelTo($now);
        $this->enrolledOperator();
        $body = $this->send('GET', '/api/operations')->assertOk()->json();

        $this->assertSame([
            ['http_error', 'RuntimeException', ['id' => 'alt', 'name' => 'みどり商店'], 1],
            ['command_failed', 'RuntimeException', null, 1],
        ], array_map(static fn (array $error): array => [$error['kind'], class_basename($error['exception']), $error['tenant'], $error['count']], $body['errors']));
        $this->assertSame('artisan operations:collect', $body['errors'][1]['location']);
        $this->assertSame(2, $body['errors_total']);
        // 要対応のいちばん上に、この 24 時間のエラーの 1 行（1 件）
        $this->assertSame(['key' => 'incidents', 'level' => 'urgent', 'count' => 1, 'tenant' => null], $body['issues'][0]);

        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);
        $this->assertStringNotContainsString(self::SECRET_EMAIL, $json);
    }

    public function test_up_fails_while_a_central_migration_has_not_been_run(): void
    {
        $this->get('http://localhost/up')->assertOk();

        // 流していないマイグレーションを 1 つ足す（中央のマイグレーションの置き場に加わった形）
        $directory = sys_get_temp_dir().'/t0096-pending-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/2099_01_01_000000_t0096_pending.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void {}\n};\n");
        app('migrator')->path($directory);

        try {
            $this->get('http://localhost/up')->assertStatus(500);
            // 壊れたこととしても記録される（知らせのメールに載る）
            $this->assertSame('app/Admin/Services/CentralMigrations.php', explode(':', Incident::query()->sole()->location)[0]);
        } finally {
            unlink($directory.'/2099_01_01_000000_t0096_pending.php');
            rmdir($directory);
        }
    }

    /**
     * 500 を起こしたのと同じ記録（場所はこの行。メッセージに個人情報を入れる）
     */
    private function boom(): void
    {
        Incidents::record(Incidents::HTTP_ERROR, new RuntimeException(self::SECRET_NAME.' '.self::SECRET_EMAIL));
    }

    private function owner(string $email): Operator
    {
        $operator = Operator::create(['name' => 'オーナー', 'email' => $email]);
        $operator->forceFill(['role' => 'owner'])->save();

        return $operator;
    }

    /**
     * 店の DB を作らずに、中央の行だけの店（運営の様子の数は中央の表だけを読むので足りる）
     */
    private function bareShop(string $id, string $name): Tenant
    {
        $shop = Tenant::withoutEvents(static fn (): Tenant => Tenant::create(['id' => $id, 'name' => $name]));
        $this->bareShops[] = $shop;

        return $shop;
    }

    /**
     * 店の様子の行（数えた時刻は今。返金が要る支払いの数）
     */
    private function digest(Tenant $shop, int $stray): void
    {
        DB::table('shop_digests')->upsert([[
            'tenant_id' => (string) $shop->getTenantKey(),
            'status' => 'open',
            'stray_payments' => $stray,
            'refund_issues' => 0,
            'stuck_mails' => 0,
            'collected_at' => now()->utc(),
        ]], ['tenant_id'], ['stray_payments', 'collected_at']);
    }

    private function signOut(): void
    {
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }
}
