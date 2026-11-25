<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Mail\OperationsAlertMail;
use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\Anomalies;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperationsOverview;
use App\Admin\Services\ShopDigests;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * おかしな動きの知らせ（ADR 0103）。5 つの形のどれも、しきい値の手前では出ない・越えると 1 回だけ知らせる・
 * 同じ間に二重に知らせない・ほかの店（ほかの接続元・ほかの鍵）の数を混ぜない・記録とメールに個人情報と鍵が入らない、を確かめる
 */
final class AnomalyAlertsTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected bool $detectAnomalies = true;

    /** 鍵の本体（テストの値。秘密情報の検知に掛からないよう、鍵の形はその場で組み立てる） */
    private const string FAKE = 'FakeForTestsOnly000';

    /**
     * 場面ごとの昼の時刻（日本の時刻の 14 時）。監査ログは追記だけで消せず、前の場面の記録が残るので、場面ごとに日を分けて 1 時間の枠に混ぜない
     * 数えは「1 時間前から後」の全部なので、場面はファイルの中で日付の順に並べる（後の日付の記録を、先に走る場面が数えない）
     */
    private const array NOON = [
        'refunds' => '2026-06-01 14:00',
        'test_mode' => '2026-06-02 14:00',
        'keys' => '2026-06-03 14:00',
        'denials' => '2026-06-05 14:00',
        'failures' => '2026-06-06 14:00',
        'agent' => '2026-06-07 14:00',
        'access' => '2026-06-08 14:00',
        'agent_spread' => '2026-06-09 14:00',
    ];

    /** 接続元（文書用のアドレス。記録とメールに出ないことを見る） */
    private const string IP_A = '203.0.113.5';

    private const string IP_B = '198.51.100.7';

    /** @var list<Tenant> 店の DB を作らずに中央の行だけ作った店 */
    private array $bareShops = [];

    /** この場面より前の監査ログ（監査ログは追記だけで、ほかのテストの記録が残る） */
    private int $auditFrom = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditFrom = (int) AuditEvent::query()->max('sequence');
        $this->configureAdminHost();
        $this->withoutVite();
        Cache::store()->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->bareShops as $shop) {
            Tenant::withoutEvents(static fn () => $shop->delete());
        }

        $this->deleteTenants();
        FakeStripe::reset();

        parent::tearDown();
    }

    public function test_refunds_alert_once_per_shop_when_the_count_or_the_amount_crosses_and_other_shops_are_not_mixed(): void
    {
        Mail::fake();
        FakeStripe::install();
        $this->owner();
        $now = CarbonImmutable::parse(self::NOON['refunds'], 'Asia/Tokyo');
        $this->travelTo($now);
        $this->tenant('ana', '返金の多い店');
        $this->tenant('anb', 'となりの店');
        $a = $this->paidAttempt('ana');
        $b = $this->paidAttempt('anb');

        // 手前: 1 時間に 9 件（A）と 5 件（B）。合わせると 14 件でも、店ごとに数えるので出ない。2 時間前の返金は数えない
        $this->refunds('ana', $a, 9, 1000, $now->subMinutes(15));
        $this->refunds('ana', $a, 5, 1000, $now->subHours(2));
        $this->refunds('anb', $b, 5, 1000, $now->subMinutes(30));
        $this->collect('ana');
        $this->collect('anb');
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('anomalies')->count());

        // 越える: A に 10 件目。A だけ 1 回知らせる
        $this->refunds('ana', $a, 1, 1000, $now->subMinute());
        $this->collect('ana');
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['「返金の多い店」で、1 時間に返金が 10 件・合計 ¥10,000 ありました（心当たりが無ければ、取引とログイン履歴を確認してください）']);
        $this->assertSame([['anomaly_refunds:ana', 'ana', 10, 10000]], DB::table('anomalies')->get(['signature', 'tenant_id', 'count', 'amount'])->map(static fn (object $row): array => array_values((array) $row))->all());

        // 続いて増えても、同じ間は二重に知らせない（数は大きい方を残す）
        $this->travelTo($now->addMinutes(5));
        $this->refunds('ana', $a, 3, 1000, $now->addMinutes(4));
        $this->collect('ana');
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame(13, (int) DB::table('anomalies')->value('count'));

        // 運営の様子の要対応に出る（件数と合計の円だけ）
        $issues = array_values(array_filter(app(OperationsOverview::class)->present()['issues'], static fn (array $issue): bool => $issue['key'] === Anomalies::REFUNDS));
        $this->assertSame([['key' => 'anomaly_refunds', 'level' => 'urgent', 'count' => 13, 'tenant' => ['id' => 'ana', 'name' => '返金の多い店'], 'amount' => 13000]], $issues);

        // 合計の円でも越える: B に ¥300,000 の返金 1 件（件数は手前）
        $this->refunds('anb', $b, 1, Anomalies::REFUND_AMOUNT, $now->addMinutes(4));
        $this->collect('anb');
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['「となりの店」で、1 時間に返金が 6 件・合計 ¥305,000 ありました（心当たりが無ければ、取引とログイン履歴を確認してください）']);

        // 24 時間を過ぎれば消え、また起きれば知らせる
        $later = $now->addHours(Anomalies::HOLD_HOURS + 1);
        $this->travelTo($later);
        $this->collect('ana');
        $this->collect('anb');
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
        $this->assertSame(0, DB::table('alerted_issues')->count());
        $this->refunds('ana', $a, 10, 100, $later->subMinutes(10));
        $this->collect('ana');
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(3);
        $this->assertSame(10, (int) DB::table('anomalies')->where('tenant_id', 'ana')->value('count'));
    }

    public function test_refunds_in_test_mode_are_not_counted(): void
    {
        FakeStripe::install();
        $now = CarbonImmutable::parse(self::NOON['test_mode'], 'Asia/Tokyo');
        $this->travelTo($now);
        $this->tenant('anc');
        $attempt = $this->paidAttempt('anc');
        $this->refunds('anc', $attempt, 12, 1000, $now->subMinutes(10));
        $this->assertSame(['count' => 12, 'amount' => 12000], $this->inside('anc', static fn (): array => Anomalies::refundPeak(CarbonImmutable::now())));

        // テストのモードの支払いの返金はお金が動かないので数えない
        $this->inside('anc', static fn () => PaymentAttempt::query()->whereKey($attempt)->update(['test_mode' => true]));
        $this->assertSame(['count' => 0, 'amount' => 0], $this->inside('anc', static fn (): array => Anomalies::refundPeak(CarbonImmutable::now())));
    }

    public function test_payment_key_changes_alert_only_at_night_or_from_an_unusual_session_and_keep_no_key(): void
    {
        Mail::fake();
        $this->tenant('and', '鍵の店');
        $this->tenant('ane', 'となりの店');
        [$operator, $authenticator] = $this->enrolledOperator();
        $keys = ['secret_key' => implode('_', ['sk', 'test', self::FAKE.'1234']), 'webhook_secret' => implode('_', ['whsec', self::FAKE.'1234'])];

        // 昼に、いつもの接続元のセッションから: 出ない
        $this->travelTo(CarbonImmutable::parse(self::NOON['keys'], 'Asia/Tokyo'));
        $this->reconfirm($operator, $authenticator);
        $this->send('PUT', '/api/tenants/and/payment-settings/stripe', $keys)->assertOk();
        $this->send('DELETE', '/api/tenants/and/payment-settings/stripe')->assertOk();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertNothingSent();

        // 記録に鍵の中身は無い（どの鍵を・どうした・いつもと違う接続元か、だけ）
        $changes = AuditEvent::query()->where('sequence', '>', $this->auditFrom)->where('type', 'operator.payment_keys_changed')->orderBy('sequence')->get();
        $this->assertEquals([['target' => 'stripe', 'action' => 'set', 'unusual' => false], ['target' => 'stripe', 'action' => 'removed', 'unusual' => false]], $changes->map(static fn (AuditEvent $event): array => $event->data)->all());
        $this->assertStringNotContainsString(self::FAKE, (string) json_encode($changes->toArray()));

        // 深夜（日本の時刻の 2 時）に: 出る（その店だけ）
        $night = CarbonImmutable::parse('2026-06-04 02:00', 'Asia/Tokyo');
        $this->travelTo($night);
        $this->reconfirm($operator, $authenticator);
        $this->send('PUT', '/api/tenants/and/payment-settings/stripe-test', $keys)->assertOk();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['「鍵の店」の支払いの設定の鍵（Stripe）が、深夜かいつもと違う接続元から 1 回変更されました（心当たりが無ければ、Stripe の鍵を作り直してください）']);

        // 同じ間は二重に知らせない
        $this->send('DELETE', '/api/tenants/and/payment-settings/stripe-test')->assertOk();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame([['anomaly_payment_keys:and', 2]], DB::table('anomalies')->get(['signature', 'count'])->map(static fn (object $row): array => array_values((array) $row))->all());

        // 昼でも、いつもと違う接続元から入ったセッション（ADR 0083 の印）なら、となりの店でも出る
        $this->travelTo($night->addHours(10));
        DB::table('operator_sessions')->where('operator_id', $operator->id)->update(['unusual' => true]);
        $this->reconfirm($operator, $authenticator);
        $this->send('PUT', '/api/tenants/ane/payment-settings/stripe', $keys)->assertOk();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['「となりの店」の支払いの設定の鍵（Stripe）が、深夜かいつもと違う接続元から 1 回変更されました（心当たりが無ければ、Stripe の鍵を作り直してください）']);
    }

    public function test_denials_from_one_source_across_many_shops_alert_once_and_sources_are_not_mixed(): void
    {
        Mail::fake();
        $this->owner();
        $this->travelTo(CarbonImmutable::parse(self::NOON['denials'], 'Asia/Tokyo'));

        // 手前: 同じ接続元から 2 つの店、ほかの接続元から 1 つの店（合わせて 3 店でも、接続元ごとに数える）
        $this->denied(self::IP_A, 'dna');
        $this->denied(self::IP_A, 'dnb');
        $this->denied(self::IP_A, 'dnb');
        $this->denied(self::IP_B, 'dnc');
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertNothingSent();

        // 越える: 同じ回線（/24）から 3 つ目の店
        $this->denied('203.0.113.77', 'dnc');
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['同じ接続元から、多くの店へのアクセスやログインの失敗が続いています（1 か所。ログイン履歴で接続元を確認してください）']);

        // 同じ間は二重に知らせない（接続元が増えても 1 通）
        foreach (['dna', 'dnb', 'dnc'] as $shop) {
            $this->denied(self::IP_B, $shop);
        }
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame([['anomaly_denials:', null, 2]], DB::table('anomalies')->get(['signature', 'tenant_id', 'count'])->map(static fn (object $row): array => array_values((array) $row))->all());

        // 記録に接続元は無い（メールの文は上で丸ごと確かめた）
        $this->assertStringNotContainsString('203.0.113', (string) json_encode(DB::table('anomalies')->get()->all()));
        $this->assertStringNotContainsString('198.51.100', (string) json_encode(DB::table('anomalies')->get()->all()));
    }

    public function test_many_failures_from_one_source_alert_and_wrong_agent_keys_are_counted_without_the_key(): void
    {
        Mail::fake();
        $this->owner();
        $this->travelTo(CarbonImmutable::parse(self::NOON['failures'], 'Asia/Tokyo'));
        $audit = app(AuditLog::class);

        // 手前: パスキーのログインの失敗が 1 時間に 29 回。1 時間より前の失敗は数えない
        $this->travelTo(CarbonImmutable::parse(self::NOON['failures'], 'Asia/Tokyo')->subMinutes(90));
        foreach (range(1, 5) as $i) {
            $audit->record('operator.login_failed', null, ['reason' => 'rejected'], ip: self::IP_B);
        }
        $this->travelTo(CarbonImmutable::parse(self::NOON['failures'], 'Asia/Tokyo'));
        foreach (range(1, Anomalies::DENIAL_FAILURES - 1) as $i) {
            $audit->record('operator.login_failed', null, ['reason' => 'rejected'], ip: self::IP_B);
        }
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertNothingSent();

        // 越える: AI エージェントの入口へ違う鍵の要求 1 回（本物の入口を通す）
        $wrong = 'ecs_agent_'.str_repeat('Z', 43);
        $this->withServerVariables(['REMOTE_ADDR' => self::IP_B])->postJson($this->adminHost.'/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
            'Authorization' => 'Bearer '.$wrong,
            'Accept' => 'application/json, text/event-stream',
        ])->assertUnauthorized();
        $denied = AuditEvent::query()->where('sequence', '>', $this->auditFrom)->where('type', 'operator.agent_denied')->sole();
        $this->assertSame([self::IP_B, [], null], [$denied->ip, $denied->data, $denied->operator_id]);
        $this->assertStringNotContainsString(str_repeat('Z', 43), (string) json_encode($denied->toArray()));

        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_a_burst_of_agent_writes_from_one_key_alerts_per_shop_and_keys_are_not_mixed(): void
    {
        Mail::fake();
        $this->owner();
        $this->bareShop('aga', 'エージェントの店');
        $this->bareShop('agb', 'となりの店');
        $this->travelTo(CarbonImmutable::parse(self::NOON['agent'], 'Asia/Tokyo'));

        // 手前: 同じ店の 2 つの鍵から 59 件ずつ（合わせて 118 件でも、鍵ごとに数える）。読む道具は数えない
        $this->agentCalls('aga', 'key-1', 'update_product', Anomalies::AGENT_WRITE_COUNT - 1);
        $this->agentCalls('aga', 'key-2', 'adjust_stock', Anomalies::AGENT_WRITE_COUNT - 1);
        $this->agentCalls('aga', 'key-1', 'search_products', 100);
        // お金の操作は 9 件まで（ほかの店）
        $this->agentCalls('agb', 'key-3', 'refund_order', Anomalies::AGENT_MONEY_COUNT - 1);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertNothingSent();

        // 越える: 1 つ目の鍵から 60 件目
        $this->agentCalls('aga', 'key-1', 'create_product', 1);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['「エージェントの店」で、1 つの AI エージェントのアクセスキーから 1 時間に 60 件の変更の操作がありました（心当たりが無ければ、アクセスキーを取り消してください）']);

        // 同じ間は二重に知らせない
        $this->agentCalls('aga', 'key-1', 'update_product', 5);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);

        // お金の操作は 10 件で越える（承認の一覧を埋める形）。数はその鍵の書く操作の数
        $this->agentCalls('agb', 'key-3', 'cancel_order', 1);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
        $this->assertSame(['aga' => 65, 'agb' => 10], DB::table('anomalies')->orderBy('tenant_id')->pluck('count', 'tenant_id')->map(static fn (mixed $n): int => (int) $n)->all());
    }

    public function test_agent_writes_through_oauth_are_counted_per_grant_across_rotated_keys(): void
    {
        Mail::fake();
        $this->owner();
        $this->bareShop('agc', 'つなぎの店');
        $this->travelTo(CarbonImmutable::parse(self::NOON['agent'], 'Asia/Tokyo'));

        // OAuth の鍵は 1 時間ごとに入れ替わる。同じ系統の 3 本の鍵から 20 件ずつ（鍵ごとなら手前だが、系統では 60 件）
        foreach (['oauth-key-1', 'oauth-key-2', 'oauth-key-3'] as $token) {
            foreach (range(1, Anomalies::AGENT_WRITE_COUNT / 3) as $i) {
                app(AuditLog::class)->record('operator.agent_request', null, ['token' => $token, 'grant' => 'grant-1', 'method' => 'tools/call', 'tool' => 'update_product'], tenantId: 'agc', ip: self::IP_A);
            }
        }

        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame(['agc' => Anomalies::AGENT_WRITE_COUNT], DB::table('anomalies')->where('tenant_id', 'agc')->pluck('count', 'tenant_id')->map(static fn (mixed $n): int => (int) $n)->all());
    }

    public function test_a_bundle_of_access_changes_alerts_once_when_three_kinds_meet_within_an_hour(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $audit = app(AuditLog::class);
        $start = CarbonImmutable::parse(self::NOON['access'], 'Asia/Tokyo');

        // 2 時間前の回復の申し込みは数えない
        $this->travelTo($start->subHours(2));
        $audit->record('operator.recovery_email_sent', $owner, [], ip: self::IP_A);

        // 手前: 運営の追加・スタッフへの変更（オーナーへ上げたのでない）・鍵の発行の 2 種類
        $this->travelTo($start);
        $audit->record('operator.created', $owner, ['operator' => (string) Str::ulid(), 'by' => 'operator:x'], ip: self::IP_A);
        $audit->record('operator.role_changed', $owner, ['operator' => (string) Str::ulid(), 'from' => 'owner', 'to' => 'staff', 'by' => 'operator:x'], ip: self::IP_A);
        $audit->record('operator.agent_token_issued', $owner, ['token' => (string) Str::ulid(), 'days' => 30], tenantId: 'x', ip: self::IP_A);
        $audit->record('operator.created', $owner, ['operator' => (string) Str::ulid(), 'by' => 'operator:x'], ip: self::IP_A);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertNothingSent();

        // 越える: オーナーへ上げた（3 種類）
        $audit->record('operator.role_changed', $owner, ['operator' => (string) Str::ulid(), 'from' => 'staff', 'to' => 'owner', 'by' => 'operator:x'], ip: self::IP_A);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['短い間に、メンバーの追加・オーナーへの変更・パスキーの回復の申し込み・AI エージェントのアクセスキーの発行のうち 3 種類が重なりました（心当たりが無ければ、メンバーとアクセスキーを確認してください）']);

        // 同じ間は二重に知らせない（4 種類目が重なっても）
        // 回復の種類は、回復用のコードを使った記録（RecoveryCodesTest が監査ログ全体で数える）でなく、メールの回復の待ちで
        $audit->record('operator.recovery_email_waiting', $owner, ['ready_at' => '2026-06-09T05:00:00Z'], ip: self::IP_A);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame(4, (int) DB::table('anomalies')->value('count'));

        // 記録に運営・接続元は無い（種類と数と時刻だけ）
        $this->assertSame(['signature', 'key', 'tenant_id', 'count', 'amount', 'first_at', 'last_at'], array_keys((array) DB::table('anomalies')->first()));
        $this->assertNull(DB::table('anomalies')->value('tenant_id'));

        // 夜の掃除: 30 日より前の記録は消える
        $this->travelTo($start->addDays(Anomalies::KEEP_DAYS + 1));
        $this->artisan('operations:alert', ['--prune' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('anomalies')->count());
    }

    public function test_the_shipped_setting_always_counts_and_no_environment_variable_turns_it_off(): void
    {
        // 切れるのはテストの土台（tests/TestCase.php）だけ。配る設定はいつも数え、.env の 1 行で黙らせられない
        $shipped = require config_path('admin.php');
        $this->assertTrue($shipped['detect_anomalies']);
        $this->assertStringNotContainsString('env(', (string) preg_replace('/\A.*?detect_anomalies/s', '', (string) file_get_contents(config_path('admin.php'))));
    }

    public function test_agent_writes_spread_over_the_shops_of_one_grant_are_counted_together(): void
    {
        Mail::fake();
        $this->owner();
        $this->travelTo(CarbonImmutable::parse(self::NOON['agent_spread'], 'Asia/Tokyo'));

        $this->bareShop('agd', '一軒目');
        $this->bareShop('age', '二軒目');
        $this->bareShop('agf', '三軒目');

        // 2 店以上の系統（ADR 0104）: 店ごとには 30 件ずつで越えないが、系統の合計で 60 件（PR 187 の見直しの低 1）
        foreach (['agd', 'age'] as $shop) {
            foreach (range(1, Anomalies::AGENT_WRITE_COUNT / 2) as $i) {
                app(AuditLog::class)->record('operator.agent_request', null, ['token' => 'oauth-key-9', 'grant' => 'grant-9', 'method' => 'tools/call', 'tool' => 'update_product'], tenantId: $shop, ip: self::IP_A);
            }
        }
        // 1 店だけの系統は、合計の数えには入れない（店ごとの数えで足りる）
        $this->agentCalls('agf', 'key-8', 'update_product', Anomalies::AGENT_WRITE_COUNT - 1);

        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['いくつかの店にまたがって、1 つの AI エージェントの接続から 1 時間に 60 件の変更の操作がありました（心当たりが無ければ、接続を取り消してください）']);
        $this->assertSame([[null, Anomalies::AGENT_WRITE_COUNT]], DB::table('anomalies')->get(['tenant_id', 'count'])->map(static fn (object $row): array => [$row->tenant_id, (int) $row->count])->all());
    }

    private function owner(string $email = 'owner@example.com'): Operator
    {
        $operator = Operator::create(['name' => 'オーナー', 'email' => $email]);
        $operator->forceFill(['role' => 'owner'])->save();

        return $operator;
    }

    /**
     * 店の DB を作らずに、中央の行だけの店（運営の様子の数は中央の表だけを読むので足りる）
     */
    private function bareShop(string $id, string $name): void
    {
        $this->bareShops[] = Tenant::withoutEvents(static fn (): Tenant => Tenant::create(['id' => $id, 'name' => $name]));
    }

    /**
     * 店で払われた支払い（返金の行が指す先）。ID を返す
     */
    private function paidAttempt(string $shop): string
    {
        return $this->inside($shop, function (): string {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::FAKE.'1234']), 'webhook_secret' => implode('_', ['whsec', self::FAKE.'1234'])]);
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $sale = app(TransactionService::class)->open('sale', $seller, Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']), [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');
            $session = app(PaymentService::class)->startCheckout($sale, 'x', 'y')->ref;
            app(PaymentNotices::class)->confirmPaid('stripe', "evt_{$session}", 'checkout.session.completed', $session, $sale->id, 4400, 'jpy', 'pi_'.Str::lower((string) Str::ulid()));
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale->id)->where('status', 'paid')->sole();
            // 本番の鍵で払われたものとして数える（テストのモードの扱いは別の場面で）
            $attempt->forceFill(['test_mode' => false])->save();

            return $attempt->id;
        });
    }

    /**
     * 返金の行を直に足す（数え方だけを見るので、Stripe へは頼まない。失敗した返金も「頼んだ返金」として数える）
     */
    private function refunds(string $shop, string $attemptId, int $count, int $amount, CarbonImmutable $at): void
    {
        $this->inside($shop, static function () use ($attemptId, $count, $amount, $at): void {
            $attempt = PaymentAttempt::query()->findOrFail($attemptId);

            foreach (range(1, $count) as $i) {
                DB::table('refunds')->insert([
                    'id' => (string) Str::ulid(),
                    'transaction_id' => $attempt->transaction_id,
                    'attempt_id' => $attempt->id,
                    'purpose' => 'cancel',
                    'amount' => $amount,
                    'currency' => 'JPY',
                    'payment_ref' => 'pi_test',
                    'idempotency_key' => (string) Str::ulid(),
                    'status' => 'failed',
                    'failure_reason' => 'card_declined',
                    'settled_at' => $at->utc(),
                    'created_at' => $at->utc(),
                    'updated_at' => $at->utc(),
                ]);
            }
        });
    }

    private function collect(string $shop): void
    {
        $this->inside($shop, static fn () => app(ShopDigests::class)->collect());
    }

    private function denied(string $ip, string $shop): void
    {
        app(AuditLog::class)->record('operator.tenant_denied', null, ['reason' => 'not_granted', 'required' => 'read', 'method' => 'GET', 'route' => 'api/tenants/{tenant}/overview'], tenantId: $shop, ip: $ip);
    }

    private function agentCalls(string $shop, string $token, string $tool, int $count): void
    {
        foreach (range(1, $count) as $i) {
            app(AuditLog::class)->record('operator.agent_request', null, ['token' => $token, 'method' => 'tools/call', 'tool' => $tool], tenantId: $shop, ip: self::IP_A);
        }
    }
}
