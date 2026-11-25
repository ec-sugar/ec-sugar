<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Models\ShopDailySale;
use App\Admin\Models\ShopDigest;
use App\Admin\Services\AppKeyBackup;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperationsOverview;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\ShopDigests;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Orders\SentMail;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 運営全体の画面（ADR 0042）: 店の文脈の中で数えた日ごとの件数と金額・困りごとの件数だけが中央に書かれ、
 * 中央の API（オーナーだけ）はそれを足すだけ。数え方はホームの数と同じ（払い済みだけ・取消と返金とテストの注文は外す・日本の時刻）。
 * ほかの店の数が混ざらない・中央に個人情報が無い・作り直すコマンド・困りごとの拾い方。
 * APP_KEY の控え: ec-sugar で入れたサーバーでは、オーナーが「控えを取りました」と答えるまで「近いうちに」に出し続ける
 */
final class OperationsOverviewTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string OPERATOR = 'operator:01aaaaaaaaaaaaaaaaaaaaaaaa';

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

    public function test_the_shop_writes_only_paid_real_orders_per_japanese_day_and_the_overview_adds_them(): void
    {
        $this->tenant('opa', 'みどり商店');
        $this->tenant('opb', 'あお商店');
        $at = static fn (string $jst): CarbonImmutable => CarbonImmutable::parse($jst, 'Asia/Tokyo');

        // 先月の終わり（日本の 10/31 23:30 = UTC 14:30）と今月の初め（日本の 11/1 0:30 = UTC 10/31 15:30）
        $this->travelTo($at('2026-10-31 23:30'));
        $this->sale('opa', 3000, paid: true);
        $this->travelTo($at('2026-11-01 00:30'));
        $this->sale('opa', 1000, paid: true);
        $this->sale('opa', 0, paid: true);
        // 入金待ち・払った後の取消・返金は数えない。テストの注文は件数だけ
        $this->sale('opa', 5000, paid: false);
        $this->sale('opa', 7000, paid: true, then: [Phase::Cancel]);
        $this->sale('opa', 9000, paid: true, then: [Phase::Dispute, Phase::Refund]);
        $this->sale('opa', 4000, paid: true, ref: 'cs_test_ops1', test: true);
        $this->sale('opa', 500, paid: true, ref: 'cs_live_ops1');
        $this->travelTo($at('2026-11-02 09:00'));
        $later = $this->sale('opa', 100, paid: true);
        // ほかの店
        $this->sale('opb', 99999, paid: true);
        $this->inside('opb', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, self::OPERATOR));

        $this->travelTo($at('2026-11-02 23:50'));
        $this->artisan('operations:collect')->assertSuccessful();

        // 中央の日ごとの行（日は日本の時刻の日付）。ほかの店の数は別の行
        $this->assertSame([
            ['2026-10-31', 1, 3000, 0],
            ['2026-11-01', 3, 1500, 1],
            ['2026-11-02', 1, 100, 0],
        ], $this->days('opa'));
        $this->assertSame([['2026-11-02', 1, 99999, 0]], $this->days('opb'));

        $this->enrolledOperator();
        $body = $this->send('GET', '/api/operations')->assertOk()->json();
        // 今月（11 月）: 1000 + 0 + 500 + 100 と 99999。10/31 の 3000 は先月
        $this->assertSame(['orders' => 5, 'sales' => 101599, 'test_orders' => 1], $body['month']);
        $this->assertSame(['total' => 2, 'open' => 1, 'preparing' => 1], $body['shops']);
        // 30 日の動き: 終わりは今日（日本の 11/2）、0 の日も並ぶ
        $this->assertCount(30, $body['daily']);
        $this->assertSame(['day' => '2026-11-02', 'orders' => 2, 'sales' => 100099], $body['daily'][29]);
        $this->assertSame(['day' => '2026-11-01', 'orders' => 3, 'sales' => 1500], $body['daily'][28]);
        $this->assertSame(['day' => '2026-10-31', 'orders' => 1, 'sales' => 3000], $body['daily'][27]);
        $this->assertSame('2026-10-04', $body['daily'][0]['day']);
        // 店の一覧: 店ごとの今月の数（売上の多い店から）
        $this->assertSame([['opb', 'preparing', 1, 99999], ['opa', 'open', 4, 1600]], array_map(
            static fn (array $shop): array => [$shop['id'], $shop['status'], $shop['month']['orders'], $shop['month']['sales']],
            $body['tenants'],
        ));
        $this->assertSame('みどり商店', $body['tenants'][1]['name']);

        // 後から取り消すと、次に集めた時に前の日の数も直る（数え直して置き換える）
        $this->inside('opa', fn () => app(TransactionService::class)->transition(Transaction::query()->findOrFail($later), Phase::Cancel));
        $this->travelTo($at('2026-11-03 00:10'));
        $this->artisan('operations:collect')->assertSuccessful();
        $this->assertSame([['2026-10-31', 1, 3000, 0], ['2026-11-01', 3, 1500, 1]], $this->days('opa'));

        // 月が替わると今月は 0（日本の 12/1 0:00 から 12 月）
        $this->travelTo($at('2026-12-01 00:05'));
        $this->artisan('operations:collect')->assertSuccessful();
        // （ひと月たってログインは切れているので、中身は直に読む）
        $this->assertSame(['orders' => 0, 'sales' => 0, 'test_orders' => 0], app(OperationsOverview::class)->present()['month']);
    }

    public function test_the_central_tables_and_the_api_hold_no_personal_data_or_order_contents(): void
    {
        $this->tenant('opc');
        $sale = $this->sale('opc', 1200, paid: true, customer: true);
        $this->artisan('operations:collect')->assertSuccessful();
        $this->enrolledOperator();

        $central = json_encode([
            DB::table('shop_daily_sales')->get()->all(),
            DB::table('shop_digests')->get()->all(),
            $this->send('GET', '/api/operations')->assertOk()->json(),
        ], JSON_UNESCAPED_UNICODE);
        $this->assertIsString($central);

        foreach (['山田', 'yamada@example.com', '品 1200', $sale] as $secret) {
            $this->assertStringNotContainsString($secret, $central);
        }

        // 中央の列は決まったものだけ（注文の中身や個人情報の列を足していない）
        $this->assertSame(['tenant_id', 'day', 'orders', 'sales', 'test_orders', 'collected_at'], array_keys((array) DB::table('shop_daily_sales')->first()));
        $this->assertSame(
            // 流し損ねた知らせは件数だけ（ADR 0089）。返金の山も件数と合計の円だけ（ADR 0103）。
            // 記録の連鎖の先頭は番号と hash（店の鍵の HMAC。中身は分からない）と真偽だけ
            ['tenant_id', 'status', 'stray_payments', 'refund_issues', 'stuck_mails', 'chain_intact', 'chain_checked_at', 'collected_at', 'failed_at', 'media_bytes', 'event_waiting', 'event_stopped', 'refund_peak_count', 'refund_peak_amount', 'chain_head_sequence', 'chain_head_hash', 'chain_rolled_back'],
            array_keys((array) DB::table('shop_digests')->first()),
        );
    }

    public function test_a_shop_near_its_photo_limit_shows_up_and_a_full_one_is_urgent(): void
    {
        // 写真の置き場: 9 割を超えたら早めに、上限に届いたら急ぎ。数は使った割合
        config(['media.shop_quota_mb' => 10]);
        $this->tenant('opm');
        $this->enrolledOperator();
        $fill = function (int $bytes): array {
            $this->inside('opm', static fn () => DB::table('shop_media')->insert([
                'id' => (string) Str::ulid(), 'width' => 1, 'height' => 1, 'checksum' => str_repeat('a', 64), 'bytes' => $bytes, 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->artisan('operations:collect', ['--tenant' => 'opm'])->assertSuccessful();

            return array_values(array_filter($this->send('GET', '/api/operations')->assertOk()->json('issues'), static fn (array $i): bool => $i['key'] === 'media_quota'));
        };

        // 上限 10 MB = 10,485,760 バイト。89% → ちょうど 95% → ちょうど 100%
        $this->assertSame([], $fill(9_332_326));
        $this->assertSame([['media_quota', 'soon', 95, 'opm']], array_map(static fn (array $i): array => [$i['key'], $i['level'], $i['count'], $i['tenant']['id']], $fill(629_146)));
        $this->assertSame(['urgent', 100], [$fill(524_288)[0]['level'], $fill(0)[0]['count']]);
    }

    public function test_the_rebuild_command_recounts_every_day_and_one_shop_can_be_chosen(): void
    {
        $this->tenant('opd');
        $this->tenant('ope');
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00', 'Asia/Tokyo'));
        $this->sale('opd', 800, paid: true);
        $this->sale('ope', 300, paid: true);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00', 'Asia/Tokyo'));
        $this->sale('opd', 200, paid: true);

        // いつもの集め方は今月と 30 日の動きの分だけ（古い日は数えない）
        $this->artisan('operations:collect', ['--tenant' => 'opd'])->assertSuccessful();
        $this->assertSame([['2026-11-02', 1, 200, 0]], $this->days('opd'));
        // 選んだ店だけ（ほかの店は集めていない）
        $this->assertSame([], $this->days('ope'));
        $this->assertNull(ShopDigest::query()->find('ope'));

        // 作り直すと全部の日を数え直し、記録の検証もする
        $this->artisan('operations:collect', ['--rebuild' => true])->assertSuccessful();
        $this->assertSame([['2026-06-15', 1, 800, 0], ['2026-11-02', 1, 200, 0]], $this->days('opd'));
        $this->assertSame([['2026-06-15', 1, 300, 0]], $this->days('ope'));
        $this->assertTrue(ShopDigest::query()->findOrFail('opd')->chain_intact);

        // 作り直した後のいつもの集め方は、範囲の外の古い日を消さない
        $this->artisan('operations:collect')->assertSuccessful();
        $this->assertSame([['2026-06-15', 1, 800, 0], ['2026-11-02', 1, 200, 0]], $this->days('opd'));

        // 店の ID の形が違う・無い店は断る
        $this->artisan('operations:collect', ['--tenant' => "opd' OR 1=1"])->assertExitCode(2);
        $this->artisan('operations:collect', ['--tenant' => 'nosuchshop'])->assertFailed();
    }

    public function test_only_owners_can_read_the_overview(): void
    {
        $this->tenant('opf');
        $this->artisan('operations:collect')->assertSuccessful();

        // ログインしていなければ 401
        $this->send('GET', '/api/operations')->assertUnauthorized();

        // スタッフは、どの段階を与えられていても 403
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'opf', 'money', 'test');
        $this->send('GET', '/api/operations')->assertForbidden();

        $this->signOut();
        $this->enrolledOperator('owner@example.com');
        $this->send('GET', '/api/operations')->assertOk()->assertJsonPath('shops.total', 1);
    }

    public function test_troubles_are_picked_up_per_shop_and_for_the_machine(): void
    {
        $now = CarbonImmutable::parse('2026-11-02 12:00', 'Asia/Tokyo');
        $this->travelTo($now->subHours(3));
        $this->tenant('opg', '困った商店');
        $this->tenant('oph', '平気な商店');

        // 返金が要る支払い 1・確かめが要る返金 1（2 時間前から途中のまま）・送りかけのまま残ったメール 1・記録の鎖の切れ
        $sale = $this->sale('opg', 2000, paid: true, ref: 'cs_live_trouble');
        $this->inside('opg', function () use ($sale): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale)->sole();
            // 二重に払われた支払い（払われた支払いは注文に 1 つだけなので、後の支払いの画面は期限切れの形で残る）
            $late = PaymentAttempt::create(['transaction_id' => $sale, 'provider' => 'stripe', 'provider_ref' => 'cs_live_trouble2', 'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_live_trouble2', 'amount' => 2000, 'currency' => 'JPY', 'status' => 'expired', 'expires_at' => now()]);
            StrayPayment::create(['transaction_id' => $sale, 'attempt_id' => $late->id, 'reason' => 'duplicate', 'amount' => 2000, 'payment_ref' => 'pi_trouble2']);
            Refund::create(['transaction_id' => $sale, 'attempt_id' => $attempt->id, 'purpose' => 'cancel', 'amount' => 2000, 'currency' => 'JPY', 'payment_ref' => 'pi_trouble', 'idempotency_key' => 'ops-test-'.Str::ulid()]);
            SentMail::create(['transaction_id' => $sale, 'kind' => 'order_paid', 'subject' => 'ご注文ありがとうございます', 'status' => 'sending']);
        });
        $this->travelTo($now);
        $this->breakChain('opg');
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();

        // この 7 日に失敗した裏の仕事 1（8 日前のものは数えない）
        foreach ([$now->subDay(), $now->subDays(8)] as $failedAt) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'テスト', 'failed_at' => $failedAt->utc()]);
        }

        $this->enrolledOperator();
        $body = $this->send('GET', '/api/operations')->assertOk()->json();
        $issues = array_map(static fn (array $issue): array => [$issue['key'], $issue['level'], $issue['count'], $issue['tenant']['id'] ?? null], $body['issues']);
        $this->assertSame([
            ['stray_payments', 'urgent', 1, 'opg'],
            ['refund_issues', 'urgent', 1, 'opg'],
            ['chain_broken', 'urgent', 1, 'opg'],
            ['failed_jobs', 'urgent', 1, null],
            ['stuck_mails', 'soon', 1, 'opg'],
        ], $issues);
        // 困りごとのある店が一覧の先に。困りごとの数は件数の合計
        $this->assertSame([['opg', 4], ['oph', 0]], array_map(static fn (array $shop): array => [$shop['id'], $shop['issues']], $body['tenants']));
        $this->assertSame(['status' => 'bad', 'broken' => 1, 'unchecked' => 0], array_intersect_key($body['health']['chain'], ['status' => 0, 'broken' => 0, 'unchecked' => 0]));
        $this->assertSame(['status' => 'warn', 'stuck' => 1], $body['health']['mail']);
        $this->assertSame('ok', $body['health']['schedule']['status']);
        $this->assertSame(['status' => 'warn', 'waiting' => 0, 'failed' => 1], $body['health']['queue']);
        // まだ見ていないものは unknown（見ていないことを正直に）
        foreach (['certificates', 'disk', 'updates'] as $key) {
            $this->assertSame(['status' => 'unknown'], $body['health'][$key]);
        }

        // 31 分集めないと、定期の処理が止まっているとみなす
        $this->travelTo($now->addMinutes(31));
        $body = $this->send('GET', '/api/operations')->assertOk()->json();
        $this->assertSame('bad', $body['health']['schedule']['status']);
        $this->assertContains(['schedule_stopped', 'urgent'], array_map(static fn (array $issue): array => [$issue['key'], $issue['level']], $body['issues']));
    }

    public function test_a_shop_that_cannot_be_collected_is_marked_and_the_others_go_on(): void
    {
        $this->tenant('opi');
        // 店の DB の無い店（作る途中で落ちた店に当たる）。中央の行だけ作る
        $broken = Tenant::withoutEvents(static fn (): Tenant => Tenant::create(['id' => 'opj', 'name' => '壊れた商店']));
        $broken->domains()->create(['domain' => 'opj.localhost']);

        try {
            $this->artisan('operations:collect')->assertFailed();
            $this->assertNotNull(ShopDigest::query()->findOrFail('opi')->collected_at);
            $digest = ShopDigest::query()->findOrFail('opj');
            $this->assertNull($digest->collected_at);
            $this->assertNotNull($digest->failed_at);

            $this->enrolledOperator();
            $body = $this->send('GET', '/api/operations')->assertOk()->json();
            $issues = array_map(static fn (array $issue): array => [$issue['key'], $issue['level'], $issue['tenant']['id'] ?? null], $body['issues']);
            $this->assertContains(['collect_failed', 'urgent', 'opj'], $issues);
            // まだ一度も数えられていない店がある
            $this->assertContains(['not_collected', 'soon', null], $issues);
        } finally {
            Tenant::withoutEvents(static fn () => $broken->delete());
        }
    }

    public function test_a_fresh_pending_refund_is_not_an_issue_but_one_pending_for_two_hours_is_on_the_home_and_in_the_overview(): void
    {
        // 日本の時刻の Carbon を SQL にそのまま渡すと、DB が 9 時間先として読み、作ったばかりの返金まで「1 時間以上途中」に入っていた
        $now = CarbonImmutable::parse('2026-11-02 12:00', 'Asia/Tokyo');
        $this->tenant('opl');
        $this->tenant('opm');

        foreach (['opl' => 10, 'opm' => 120] as $tenant => $minutes) {
            $this->travelTo($now->subMinutes($minutes));
            $sale = $this->sale($tenant, 2000, paid: true, ref: "cs_live_wait_{$tenant}");
            $this->inside($tenant, function () use ($sale, $tenant): void {
                $attempt = PaymentAttempt::query()->where('transaction_id', $sale)->sole();
                Refund::create(['transaction_id' => $sale, 'attempt_id' => $attempt->id, 'purpose' => 'cancel', 'amount' => 2000, 'currency' => 'JPY', 'payment_ref' => "pi_wait_{$tenant}", 'provider_ref' => "re_wait_{$tenant}", 'status' => 'pending', 'idempotency_key' => 'ops-wait-'.Str::ulid()]);
            });
        }

        $this->travelTo($now);
        $this->artisan('operations:collect')->assertSuccessful();
        $this->enrolledOperator();

        // 店のホーム: 10 分前の返金は出さない、2 時間前の返金は出す
        $this->assertSame([], $this->send('GET', '/api/tenants/opl/overview')->assertOk()->json('refund_issues'));
        $this->assertCount(1, $this->send('GET', '/api/tenants/opm/overview')->assertOk()->json('refund_issues'));
        // 運営の様子も同じ（日本の時刻で数えても）
        $this->assertSame(0, ShopDigest::query()->findOrFail('opl')->refund_issues);
        $this->assertSame(1, ShopDigest::query()->findOrFail('opm')->refund_issues);
        $issues = array_map(static fn (array $issue): array => [$issue['key'], $issue['tenant']['id'] ?? null], $this->send('GET', '/api/operations')->assertOk()->json('issues'));
        $this->assertContains(['refund_issues', 'opm'], $issues);
        $this->assertNotContains(['refund_issues', 'opl'], $issues);
    }

    public function test_an_older_collection_that_finishes_late_does_not_overwrite_newer_numbers(): void
    {
        $this->tenant('opn');
        $at = static fn (string $jst): CarbonImmutable => CarbonImmutable::parse($jst, 'Asia/Tokyo');
        $this->travelTo($at('2026-11-02 12:00'));
        $this->sale('opn', 1000, paid: true);
        $this->sale('opn', 500, paid: true);

        // 新しい回（12:10）が先に書き終え、古い回（12:00 に始めた夜の検証など）が後から書きに来る
        $this->inside('opn', fn (): bool => app(ShopDigests::class)->collect($at('2026-11-02 12:10')));
        $this->sale('opn', 99, paid: true);
        $written = $this->inside('opn', fn (): bool => app(ShopDigests::class)->collect($at('2026-11-02 12:00'), verify: true));

        // 古い回は数を上書きしない（2 件のまま）。記録の検証の結果だけは残す
        $this->assertFalse($written);
        $this->assertSame([['2026-11-02', 2, 1500, 0]], $this->days('opn'));
        $digest = ShopDigest::query()->findOrFail('opn');
        $this->assertTrue($digest->collected_at->equalTo($at('2026-11-02 12:10')));
        $this->assertTrue($digest->chain_intact);

        // 同じ店を続けて何度集めても落ちない（店ごとの鍵の中で消してから入れる）
        $this->travelTo($at('2026-11-02 12:20'));
        $this->artisan('operations:collect', ['--tenant' => 'opn'])->assertSuccessful();
        $this->artisan('operations:collect', ['--tenant' => 'opn', '--rebuild' => true])->assertSuccessful();
        $this->assertSame([['2026-11-02', 3, 1599, 0]], $this->days('opn'));
    }

    public function test_writing_waits_for_the_same_shops_lock_held_by_another_collection(): void
    {
        $this->tenant('opq');
        $this->sale('opq', 700, paid: true);
        $central = ShopDigests::central();
        // ほかの集め直しに当たる 2 本目の接続が、同じ店の鍵を持っている
        config(['database.connections.ops_probe' => config("database.connections.{$central}")]);
        DB::connection('ops_probe')->select('SELECT pg_advisory_lock(hashtext(?))', ['shop_digest:opq']);

        try {
            DB::connection($central)->statement("SET lock_timeout = '300ms'");

            try {
                $this->inside('opq', fn (): bool => app(ShopDigests::class)->collect());
                $this->fail('同じ店の鍵を待たずに書いた');
            } catch (QueryException $e) {
                $this->assertStringContainsString('55P03', $e->getMessage());
            }

            $this->assertSame([], $this->days('opq'));
        } finally {
            DB::connection('ops_probe')->select('SELECT pg_advisory_unlock(hashtext(?))', ['shop_digest:opq']);
            DB::connection('ops_probe')->disconnect();
            DB::connection($central)->statement('SET lock_timeout = 0');
        }

        // 鍵が空けば書ける。ほかの店の鍵は関係ない
        $this->assertTrue($this->inside('opq', fn (): bool => app(ShopDigests::class)->collect()));
        $this->assertCount(1, $this->days('opq'));
    }

    public function test_a_chain_verification_older_than_a_day_and_a_bit_is_a_warning(): void
    {
        $this->tenant('opo');
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $this->assertSame('ok', app(OperationsOverview::class)->present()['health']['chain']['status']);

        // 夜の検証が止まった（最後の検証が 27 時間前）。数は 10 分ごとに集まっていても「問題なし」にしない
        $this->travelTo(now()->addHours(27));
        $this->artisan('operations:collect')->assertSuccessful();
        $this->assertSame('warn', app(OperationsOverview::class)->present()['health']['chain']['status']);
    }

    /**
     * 外への通信の出口の代理（ADR 0102）の数が、機械の調子の札と困りごとに入る。代理を使わない開発の形では札も困りごとも無い
     */
    public function test_the_egress_proxy_counts_reach_the_overview(): void
    {
        $this->assertArrayNotHasKey('egress', app(OperationsOverview::class)->present()['health']);

        $path = (string) tempnam(sys_get_temp_dir(), 'egress-state-');
        config(['egress.proxy' => 'http://egress:3128', 'egress.state' => $path]);

        try {
            file_put_contents($path, (string) json_encode([
                'mode' => 'enforce', 'updated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'last24h' => ['allowed' => 5, 'unlisted' => 0, 'refused' => 3, 'failed' => 0, 'smtp' => 1],
            ]));
            $overview = app(OperationsOverview::class)->present();

            $this->assertSame(['status' => 'warn', 'mode' => 'enforce'], array_intersect_key($overview['health']['egress'], ['status' => 1, 'mode' => 1]));
            $this->assertContains(['key' => 'egress_refused', 'level' => 'urgent', 'count' => 3, 'tenant' => null], $overview['issues']);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_collector_runs_only_inside_a_shop_and_the_overview_only_outside(): void
    {
        $this->tenant('opk');

        try {
            app(ShopDigests::class)->collect();
            $this->fail('店の文脈の外で数えられてしまった');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->inside('opk', function (): void {
            try {
                app(OperationsOverview::class)->present();
                $this->fail('店の文脈の中で運営全体の数を読めてしまった');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        });
    }

    public function test_a_remembered_chain_head_catches_a_cut_off_tail_and_does_not_clear_itself(): void
    {
        // 連鎖の検証は「つながっているか」しか見ないので、末尾を切り落とされても残りは無傷に見える。
        // 検証のたびに先頭を中央に控え、次の検証で同じ所にあるかを見る（ADR 0012）
        $this->tenant('opq', 'しろ商店');
        $this->tenant('opr', 'くろ商店');
        $this->sale('opq', 1000, paid: true);
        $this->sale('opq', 2000, paid: true);
        $this->sale('opr', 500, paid: true);

        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $first = ShopDigest::query()->findOrFail('opq');
        $head = $this->inside('opq', fn (): DomainEvent => DomainEvent::query()->orderByDesc('sequence')->firstOrFail());
        $this->assertGreaterThan(1, $head->sequence);
        $this->assertSame([$head->sequence, $head->hash, false], [$first->chain_head_sequence, $first->chain_head_hash, $first->chain_rolled_back]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $first->chain_head_hash);

        // 記録が増えるのは巻き戻しではない（先頭が進むだけ）
        $this->sale('opq', 300, paid: true);
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $grown = ShopDigest::query()->findOrFail('opq');
        $this->assertFalse($grown->chain_rolled_back);
        $this->assertGreaterThan($head->sequence, $grown->chain_head_sequence);

        // 末尾を 2 つ切り落とす（表の持ち主ならトリガーを外せる）。残りはつながったままなので、連鎖の検証は通ってしまう
        $this->inside('opq', function () use ($grown): void {
            DB::statement('ALTER TABLE domain_events DISABLE TRIGGER USER');
            DB::table('domain_events')->where('sequence', '>', $grown->chain_head_sequence - 2)->delete();
            DB::statement('ALTER TABLE domain_events ENABLE TRIGGER USER');
            $this->assertTrue(app(EventStore::class)->verify()->intact());
        });

        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $cut = ShopDigest::query()->findOrFail('opq');
        $this->assertTrue($cut->chain_intact);
        $this->assertTrue($cut->chain_rolled_back);
        // 控えは進めない（切り落とした後の先頭で上書きしない）
        $this->assertSame([$grown->chain_head_sequence, $grown->chain_head_hash], [$cut->chain_head_sequence, $cut->chain_head_hash]);
        // ほかの店には出ない
        $this->assertFalse(ShopDigest::query()->findOrFail('opr')->chain_rolled_back);

        // もう一度確かめても、新しい注文で番号が元の所まで戻っても、知らせはひとりでに消えない（同じ番号でも hash が違う）
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $this->assertTrue(ShopDigest::query()->findOrFail('opq')->chain_rolled_back);
        $this->sale('opq', 400, paid: true);
        $this->sale('opq', 400, paid: true);
        $this->inside('opq', fn () => $this->assertGreaterThanOrEqual($grown->chain_head_sequence, DomainEvent::query()->max('sequence')));
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $this->assertTrue(ShopDigest::query()->findOrFail('opq')->chain_rolled_back);

        // 運営の様子に、要対応として店の名前つきで出る
        $this->enrolledOperator();
        $issues = array_map(static fn (array $issue): array => [$issue['key'], $issue['level'], $issue['tenant']['id'] ?? null], $this->send('GET', '/api/operations')->assertOk()->json('issues'));
        $this->assertContains(['chain_rolled_back', 'urgent', 'opq'], $issues);
        $this->assertNotContains(['chain_broken', 'urgent', 'opq'], $issues);

        // バックアップから戻した後は、控えを取り直す（監査ログに残る）。次の検証で今の先頭を控える
        $this->artisan('operations:accept-chain-head', ['tenant' => 'nai'])->assertFailed();
        $this->artisan('operations:accept-chain-head', ['tenant' => 'opq'])->assertSuccessful();
        $forgotten = ShopDigest::query()->findOrFail('opq');
        $this->assertSame([null, null, null], [$forgotten->chain_head_sequence, $forgotten->chain_head_hash, $forgotten->chain_rolled_back]);
        $this->assertSame(1, DB::connection(config('tenancy.database.central_connection'))->table('audit_events')->where('type', 'tenant.chain_head_accepted')->where('tenant_id', 'opq')->count());
        // 忘れる前の控え（どこまであったはずか）が監査ログに残る
        $accepted = json_decode((string) DB::connection(config('tenancy.database.central_connection'))->table('audit_events')->where('type', 'tenant.chain_head_accepted')->value('data'), true);
        $this->assertSame([$grown->chain_head_sequence, $grown->chain_head_hash, true], [$accepted['sequence'] ?? null, $accepted['hash'] ?? null, $accepted['rolled_back'] ?? null]);
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $again = ShopDigest::query()->findOrFail('opq');
        $this->assertFalse($again->chain_rolled_back);
        $this->assertSame($this->inside('opq', fn (): int => (int) DomainEvent::query()->max('sequence')), $again->chain_head_sequence);
    }

    public function test_the_ten_minute_run_also_notices_a_cut_made_since_the_last_full_check(): void
    {
        // 全件の検証は 1 日 1 回。その時だけ控えると、前の検証より後に積まれた記録（跡を消したい人がいちばん消したい所）を
        // 切り落とされても気づけない。10 分ごとの回でも、控えた先頭の照らしと先頭の控え直しをする（どちらも 1 行読むだけ）
        $this->tenant('opu', 'みず商店');
        $this->sale('opu', 1000, paid: true);
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $checked = ShopDigest::query()->findOrFail('opu');

        // 検証の後に記録が増える → 検証なしの回が先頭を進める（検証した時刻は変えない）
        $this->sale('opu', 2000, paid: true);
        $this->artisan('operations:collect')->assertSuccessful();
        $grown = ShopDigest::query()->findOrFail('opu');
        $this->assertGreaterThan($checked->chain_head_sequence, $grown->chain_head_sequence);
        $this->assertFalse($grown->chain_rolled_back);
        $this->assertTrue($grown->chain_checked_at->equalTo($checked->chain_checked_at));

        // 検証の後に積まれた分だけを切り落とす（前の検証の時の先頭は残る）。次の検証なしの回が気づく
        $this->inside('opu', function () use ($checked): void {
            DB::statement('ALTER TABLE domain_events DISABLE TRIGGER USER');
            DB::table('domain_events')->where('sequence', '>', $checked->chain_head_sequence)->delete();
            DB::statement('ALTER TABLE domain_events ENABLE TRIGGER USER');
        });
        $this->artisan('operations:collect')->assertSuccessful();
        $cut = ShopDigest::query()->findOrFail('opu');
        $this->assertTrue($cut->chain_rolled_back);
        $this->assertSame([$grown->chain_head_sequence, $grown->chain_head_hash], [$cut->chain_head_sequence, $cut->chain_head_hash]);
    }

    public function test_a_broken_chain_stops_the_ten_minute_run_from_moving_the_head_too(): void
    {
        $this->tenant('opv', 'つち商店');
        $this->sale('opv', 1000, paid: true);
        $this->breakChain('opv');
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $broken = ShopDigest::query()->findOrFail('opv');
        $this->assertFalse($broken->chain_intact);

        // 連鎖が切れていると分かっている間は、検証なしの回も控えを進めない（壊れた記録の先頭を覚えない）
        $this->sale('opv', 500, paid: true);
        $this->artisan('operations:collect')->assertSuccessful();
        $after = ShopDigest::query()->findOrFail('opv');
        $this->assertSame([$broken->chain_head_sequence, $broken->chain_head_hash], [$after->chain_head_sequence, $after->chain_head_hash]);
    }

    public function test_a_broken_chain_does_not_move_the_remembered_head(): void
    {
        $this->tenant('ops', 'あか商店');
        $this->sale('ops', 1000, paid: true);
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $before = ShopDigest::query()->findOrFail('ops');

        // 連鎖が切れている時も控えを進めない（壊れた記録の先頭を「正しい先頭」として覚えない）。
        // 控えた先頭より前のイベントを書き換える（確かめられるのは 0 件目までになる。ここで控えを進めると先頭が 0 に戻る）
        $this->assertGreaterThan(1, $before->chain_head_sequence);
        $this->inside('ops', function (): void {
            DB::statement('ALTER TABLE domain_events DISABLE TRIGGER USER');
            DB::table('domain_events')->where('sequence', 1)->update(['actor' => 'someone-else']);
            DB::statement('ALTER TABLE domain_events ENABLE TRIGGER USER');
        });
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();
        $after = ShopDigest::query()->findOrFail('ops');
        $this->assertFalse($after->chain_intact);
        $this->assertFalse($after->chain_rolled_back);
        $this->assertSame([$before->chain_head_sequence, $before->chain_head_hash], [$after->chain_head_sequence, $after->chain_head_hash]);
    }

    public function test_the_database_refuses_a_chain_head_of_the_wrong_shape(): void
    {
        $this->tenant('opt', 'きいろ商店');
        $this->sale('opt', 1000, paid: true);
        $this->artisan('operations:collect', ['--verify' => true])->assertSuccessful();

        // hash は 16 進 64 桁だけ・番号と hash は組（自由な文や半端な組は DB も拒む）
        foreach ([['chain_head_hash' => 'not-a-hash'], ['chain_head_hash' => null], ['chain_head_sequence' => -1]] as $bad) {
            try {
                ShopDigest::query()->whereKey('opt')->update($bad);
                $this->fail('断られなかった: '.json_encode($bad));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_app_key_backup_reminder_shows_only_on_an_installed_server_and_stays_until_confirmed(): void
    {
        $now = CarbonImmutable::now();

        // ホストの報告が一度も無い（手元の開発）なら出さない
        $this->assertSame([], $this->appKeyIssues(app(OperationsOverview::class)->present($now)));

        // ec-sugar で入れたサーバー（ホストの報告が来た）では「近いうちに」に 1 行。要対応ではない
        $this->hostReport();
        $this->assertSame(
            [['key' => 'app_key_backup_unconfirmed', 'level' => 'soon', 'count' => 1, 'tenant' => null]],
            $this->appKeyIssues(app(OperationsOverview::class)->present($now)),
        );
        $this->assertSame(0, app(OperationsOverview::class)->urgentCount($now));

        // 日が経っても、次の報告が来ても、答えるまで出し続ける
        $this->assertCount(1, $this->appKeyIssues(app(OperationsOverview::class)->present($now->addDays(30))));
        $this->hostReport();
        $this->assertCount(1, $this->appKeyIssues(app(OperationsOverview::class)->present($now)));

        // 答えると消える。次の報告（host_checks の置き換え）でも戻らない
        $owner = Operator::create(['name' => 'オーナー', 'email' => 'owner@example.com']);
        $this->assertTrue(AppKeyBackup::confirm($owner, $now, app(AuditLog::class)));
        $this->assertSame([], $this->appKeyIssues(app(OperationsOverview::class)->present($now)));
        $this->hostReport();
        $this->assertSame([], $this->appKeyIssues(app(OperationsOverview::class)->present($now->addDays(30))));
    }

    public function test_only_an_owner_after_a_fresh_confirmation_can_answer_that_the_app_key_is_backed_up(): void
    {
        $this->hostReport();
        $path = '/api/operations/app-key-backup';
        // 監査ログは追記専用で、テストの間も空にならない（TestCase の exceptTables）。このテストで増えた分だけを数える
        $before = (string) AuditEvent::query()->max('id');
        $audits = static fn () => AuditEvent::query()->where('type', AppKeyBackup::AUDIT)->where('id', '>', $before);

        // ログインしていなければ 401
        $this->send('POST', $path)->assertUnauthorized();

        // スタッフは、どの段階を与えられていても・再確認の直後でも 403
        $this->tenant('opu');
        [$staff, $staffKey] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'opu', 'money', 'test');
        $this->reconfirm($staff, $staffKey);
        $this->send('POST', $path)->assertForbidden();
        $this->send('POST', '/auth/logout')->assertSuccessful();
        $this->resetRateLimit();

        // オーナーでも、直前の再確認が無ければ通らない（423）。何も残らず、困りごとは出たまま
        [$owner, $ownerKey] = $this->enrolledOperator('owner@example.com');
        $this->send('POST', $path)->assertStatus(423);
        $this->assertSame(0, DB::table('operations_confirmations')->count());
        $this->assertSame(0, $audits()->count());
        $this->assertCount(1, $this->appKeyIssues($this->send('GET', '/api/operations')->assertOk()->json()));

        // 再確認の後は通り、困りごとが消える。答えは時刻だけ（鍵・設定の値は返さない）
        $this->reconfirm($owner, $ownerKey);
        $first = $this->send('POST', $path)->assertOk();
        $this->assertSame(['confirmed_at'], array_keys($first->json()));
        $this->assertSame([], $this->appKeyIssues($this->send('GET', '/api/operations')->assertOk()->json()));

        // 残るのは「何を・いつ・誰が」だけ
        $row = DB::table('operations_confirmations')->sole();
        $this->assertSame(['key', 'confirmed_at', 'operator_id'], array_keys((array) $row));
        $this->assertSame(['app_key_backup', $owner->id], [$row->key, $row->operator_id]);

        // 監査の記録が 1 件（誰が・どこから。中身は空で、店にも結びつかない）
        $audit = $audits()->sole();
        $this->assertSame([$owner->id, null, []], [$audit->operator_id, $audit->tenant_id, $audit->data]);
        $this->assertTrue(app(AuditLog::class)->verify()->intact());

        // 二度押しても壊れない: 最初の答えの時刻のまま、行も監査の記録も増えない
        $this->travel(1)->minutes();
        $this->send('POST', $path)->assertOk()->assertJsonPath('confirmed_at', $first->json('confirmed_at'));
        $this->assertSame(1, DB::table('operations_confirmations')->count());
        $this->assertSame(1, $audits()->count());
    }

    public function test_the_app_key_backup_answer_holds_no_free_text_and_is_written_only_once(): void
    {
        $owner = Operator::create(['name' => 'オーナー', 'email' => 'owner@example.com']);
        $this->assertTrue(AppKeyBackup::confirm($owner, CarbonImmutable::now(), app(AuditLog::class)));
        // もう答えてあれば何も変えない
        $this->assertFalse(AppKeyBackup::confirm($owner, CarbonImmutable::now()->addDay(), app(AuditLog::class)));

        // 決めていない種類は DB も拒む（自由な文を置けない）
        try {
            DB::table('operations_confirmations')->insert(['key' => 'APP_KEY=base64:…', 'confirmed_at' => now(), 'operator_id' => null]);
            $this->fail('断られなかった');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // 運営は消さずに止める決まり（Operator は削除を断る）なので、「答えた人が消えた後」は起きない。答えは残っている
        $this->assertNotNull(AppKeyBackup::confirmedAt());

        // 店の文脈の中からは書かない
        $this->tenant('opv');
        $this->expectException(LogicException::class);
        $this->inside('opv', fn () => AppKeyBackup::confirm(Operator::create(['name' => '別の人', 'email' => 'other@example.com']), CarbonImmutable::now(), app(AuditLog::class)));
    }

    /**
     * ホストの報告を 1 回（ec-sugar で入れたサーバーの形。困りごとの無い空き容量だけ）
     */
    private function hostReport(): void
    {
        $this->artisan('operations:host-report', ['--disk' => ['data,40,1000']])->assertSuccessful();
    }

    /**
     * 運営の様子の困りごとのうち、APP_KEY の控えのもの
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function appKeyIssues(array $body): array
    {
        return array_values(array_filter($body['issues'], static fn (array $issue): bool => $issue['key'] === AppKeyBackup::ISSUE));
    }

    /**
     * 店の記録の鎖に、つながらない行を 1 つ足す（追記はできるので、書き換えずに切れを作る）
     */
    private function breakChain(string $tenant): void
    {
        $this->inside($tenant, function (): void {
            $last = DomainEvent::query()->orderByDesc('sequence')->firstOrFail();
            DB::table('domain_events')->insert([
                'id' => strtolower((string) Str::ulid()),
                'sequence' => $last->sequence + 1,
                'type' => 'transaction.opened',
                'aggregate_type' => 'transaction',
                'aggregate_id' => strtolower((string) Str::ulid()),
                'payload' => '{}',
                'actor' => 'system',
                'occurred_at' => now(),
                'prev_hash' => $last->hash,
                'hash' => str_repeat('f', 64),
            ]);
        });
    }

    /**
     * 中央の日ごとの行（日・件数・売上・テストの件数）
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int}>
     */
    private function days(string $tenant): array
    {
        return ShopDailySale::query()->where('tenant_id', $tenant)->orderBy('day')->get()
            ->map(static fn (ShopDailySale $row): array => [(string) $row->day, $row->orders, $row->sales, $row->test_orders])->values()->all();
    }

    /**
     * 物販の注文を 1 つ作り、ID を返す。paid なら入金済み（0 円なら受付済み）へ進め、ref があれば払われた支払いの画面を残す。then のフェーズへさらに進める
     *
     * @param  list<Phase>  $then
     */
    private function sale(string $tenant, int $amount, bool $paid, ?string $ref = null, array $then = [], bool $customer = false, bool $test = false): string
    {
        return $this->inside($tenant, function () use ($amount, $paid, $ref, $then, $customer, $test): string {
            $seller = Seller::query()->firstOrFail();
            $store = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => "品 {$amount}", 'unit_amount' => $amount, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($listing, $store, 5);
            $buyer = $customer ? Party::create(['kind' => PartyKind::Customer, 'name' => '山田 太郎', 'email' => 'yamada@example.com']) : null;
            $service = app(TransactionService::class);
            $sale = $service->open('sale', $seller, $buyer, [LineDraft::fromListing($listing, 1, 1000, $store)], actor: 'system');

            if ($paid) {
                $sale = $service->transition($sale, Phase::Payment);
            }

            if ($ref !== null) {
                PaymentAttempt::create(['transaction_id' => $sale->id, 'provider' => 'stripe', 'provider_ref' => $ref, 'checkout_url' => "https://checkout.stripe.com/c/pay/{$ref}", 'amount' => max(1, $amount), 'currency' => 'JPY', 'status' => 'paid', 'expires_at' => now()->addHour(), 'paid_at' => now(), 'test_mode' => $test]);

                // 受け取った額を台帳にも（返金の記録は受け取った額までしか作れない。ADR 0048 の DB の守り）
                if ($amount > 0) {
                    app(LedgerService::class)->recordPayment($sale, $amount, PaymentRoute::Platform, 'pi_'.$ref);
                }
            }

            foreach ($then as $phase) {
                $sale = $service->transition($sale, $phase);
            }

            return $sale->id;
        });
    }

    private function signOut(): void
    {
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }
}
