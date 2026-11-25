<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Incident;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\Incidents;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Trace\TraceId;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\SentMail;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Queue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use RuntimeException;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 処理を追う ID（ADR 0087）: 1 つの要求から積んだ裏の仕事・イベント・送ったメールの記録・監査ログに同じ ID が入る、
 * キューの仕事を処理する時に ID と店が戻る、外から来る X-Request-Id を信じない、定期の処理ごと・CLI ごとに別の ID、
 * ログに足すのは ID と店の ID だけ、処理の ID で記録を並べる口はオーナーだけでほかの店の記録を出さない
 */
final class TraceIdTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    /** 外から名乗られる ID（形は正しい ULID） */
    private const string FORGED = '01jbz0000000000000000fakee';

    private Operator $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('tra');
        $this->tenant('trb');
        [$this->owner] = $this->enrolledOperator();
        TraceIdTestJob::$seen = [];
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_one_admin_request_carries_one_id_to_its_events_job_mail_and_audit(): void
    {
        Mail::fake();
        [$sale, $fulfillment] = $this->paidShopOrder('tra');

        // 外から名乗った ID は使わない。応答の見出しには、こちらで決めた ID を返す（管理画面だけ）
        $response = $this->withHeader('X-Request-Id', self::FORGED)
            ->send('POST', "/api/tenants/tra/transactions/{$sale}/ship", ['fulfillment_id' => $fulfillment, 'carrier' => 'yamato', 'tracking_number' => '1234-5678'])
            ->assertOk();
        $id = (string) $response->headers->get('X-Request-Id');
        $this->assertTrue(TraceId::valid($id), $id);
        $this->assertNotSame(self::FORGED, $id);

        $this->inside('tra', function () use ($id, $sale): void {
            // 発送のイベント（この要求で書いたもの）は、どれもこの ID
            $shipped = DomainEvent::query()->where('aggregate_id', $sale)->orderByDesc('sequence')->firstOrFail();
            $this->assertSame($id, $shipped->trace_id);
            $this->assertGreaterThan(0, DomainEvent::query()->where('trace_id', $id)->count());
            $this->assertSame(0, DomainEvent::query()->where('trace_id', self::FORGED)->count());
            // 積んだ裏の仕事（知らせのメール）が、処理する時に戻った ID で送った記録を残す
            $mail = SentMail::query()->where('transaction_id', $sale)->where('kind', 'order_shipped')->sole();
            $this->assertSame('sent', $mail->status);
            $this->assertSame($id, $mail->trace_id);
            // 連鎖の検証は ID を足しても通る（ハッシュの本文の外）
            $this->assertTrue(app(EventStore::class)->verify()->intact());
        });

        // 店への立ち入りの監査ログも同じ ID。監査の連鎖の検証も通る
        $audit = AuditEvent::query()->where('type', 'operator.tenant_request')->where('tenant_id', 'tra')->latest('sequence')->firstOrFail();
        $this->assertSame($id, $audit->trace_id);
        $this->assertTrue(app(AuditLog::class)->verify()->intact());

        // 次の要求は別の ID
        $next = (string) $this->send('GET', '/api/tenants/tra/overview')->assertOk()->headers->get('X-Request-Id');
        $this->assertTrue(TraceId::valid($next));
        $this->assertNotSame($id, $next);
    }

    public function test_the_shop_and_the_webhook_do_not_get_the_id_back(): void
    {
        Route::middleware(['storefront-media', 'public'])->get('/_t0197/shop', static fn () => response('ok'));

        $shop = $this->withHeader('X-Request-Id', self::FORGED)->get('http://tra.localhost/_t0197/shop')->assertOk();
        $this->assertFalse($shop->headers->has('X-Request-Id'));
        $this->assertFalse($this->get('http://localhost/up')->headers->has('X-Request-Id'));
    }

    public function test_a_queued_job_gets_its_id_and_shop_back_when_it_is_processed(): void
    {
        // 店の中の要求で積む（ID と店を決めてから、仕事の中身を作る）
        tenancy()->initialize($this->tenantModel('tra'));
        $id = TraceId::start();
        $payload = $this->payloadOf(new TraceIdTestJob);
        tenancy()->end();

        // 処理役は別のプロセス: 店の外で、別の ID・空の文脈から始まる
        Context::flush();
        $worker = TraceId::start();
        $this->process($payload);

        $this->assertSame([['trace' => $id, 'tenant' => 'tra', 'log_tenant' => 'tra']], TraceIdTestJob::seen());
        // 処理の後は店の外に戻る
        $this->assertFalse(tenancy()->initialized);

        // ID の無い文脈から積んだ仕事は、処理する時に仕事ごとに新しい ID を決める
        Context::flush();
        $bare = $this->payloadOf(new TraceIdTestJob);
        $this->assertArrayNotHasKey('illuminate:log:context', $bare);
        TraceIdTestJob::$seen = [];
        TraceId::start();
        $this->process($bare);
        $this->process($bare);

        $this->assertCount(2, TraceIdTestJob::seen());
        [$first, $second] = array_column(TraceIdTestJob::seen(), 'trace');
        $this->assertTrue(TraceId::valid((string) $first));
        $this->assertNotSame($first, $second);
        $this->assertNotContains($worker, [$first, $second]);
        $this->assertNull(TraceIdTestJob::seen()[0]['tenant']);
    }

    public function test_each_scheduled_task_and_each_command_gets_its_own_id(): void
    {
        $before = TraceId::ensure();
        $seen = [];
        $schedule = app(Schedule::class);
        $tasks = [
            $schedule->call(static function () use (&$seen): void {
                $seen[] = TraceId::current();
            }),
            $schedule->call(static function () use (&$seen): void {
                $seen[] = TraceId::current();
            }),
        ];

        // schedule:run と同じく、始める前に ScheduledTaskStarting を出してから動かす（schedule:run そのものは流さない。ほかの定期の処理まで動き出すため）
        foreach ($tasks as $task) {
            event(new ScheduledTaskStarting($task));
            $task->run(app());
        }

        $this->assertCount(2, $seen);
        $this->assertTrue(TraceId::valid((string) $seen[0]));
        $this->assertTrue(TraceId::valid((string) $seen[1]));
        $this->assertNotSame($seen[0], $seen[1]);
        $this->assertNotContains($before, $seen);

        // CLI のコマンドは、ID が無ければ始まりで決める（人が流したコマンド・裏の仕事の処理役）。
        // テストの中では Laravel がコマンドの始まりの出来事を出さないので、本番と同じく出すようにしてから流す
        $kernel = app(KernelContract::class);
        $this->assertInstanceOf(ConsoleKernel::class, $kernel);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
        Context::flush();
        Artisan::call('inspire');
        $command = TraceId::current();
        $this->assertTrue(TraceId::valid((string) $command));
        // 定期の処理から渡された ID（Laravel が環境変数で子のプロセスへ渡し、Context に戻したもの）があれば、それを使う。
        // 戻した店の ID は、店の文脈に入っていないので外す
        $task = strtolower((string) Str::ulid());
        Context::hydrate(['data' => [TraceId::KEY => serialize($task), TraceId::TENANT_KEY => serialize('tra')], 'hidden' => []]);
        Artisan::call('inspire');
        $this->assertSame($task, TraceId::current());
        $this->assertNotSame($command, $task);
        $this->assertFalse(Context::has(TraceId::TENANT_KEY));
    }

    public function test_the_log_gets_only_the_id_and_the_shop(): void
    {
        config(['logging.channels.t0197' => ['driver' => 'monolog', 'handler' => TestHandler::class], 'logging.default' => 't0197']);
        Route::middleware(['storefront-media', 'public'])->get('/_t0197/log', static function () {
            Log::warning('ためしの行', ['n' => 1]);

            return response('ok');
        });

        $this->get('http://tra.localhost/_t0197/log?email=yamada@example.com&name='.urlencode('山田 太郎'), ['X-Request-Id' => self::FORGED])->assertOk();

        $channel = Log::channel('t0197');
        $this->assertInstanceOf(LaravelLogger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $handler = $logger->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);
        $records = array_values(array_filter($handler->getRecords(), static fn (LogRecord $r): bool => $r->message === 'ためしの行'));
        $this->assertCount(1, $records);

        // 足すのは ID と店の ID だけ（要求の中身・宛先・外から名乗った ID は足さない）
        $this->assertSame(['trace_id', 'tenant_id'], array_keys($records[0]->extra));
        $this->assertTrue(TraceId::valid($records[0]->extra['trace_id']));
        $this->assertSame('tra', $records[0]->extra['tenant_id']);
        $this->assertSame(['n' => 1], $records[0]->context);
        $all = json_encode(array_map(static fn (LogRecord $r): array => $r->toArray(), $handler->getRecords()), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($all);

        foreach (['yamada@example.com', '山田', self::FORGED] as $secret) {
            $this->assertStringNotContainsString($secret, $all, $secret);
        }
    }

    public function test_the_trace_lookup_shows_only_this_shop_and_only_to_owners(): void
    {
        $trace = strtolower((string) Str::ulid());
        $aggregates = [];

        // 同じ ID の記録を 2 つの店に置く（ID は推し量れる値ではないが、出すのは入った店の分だけであること）
        foreach (['tra', 'trb'] as $shop) {
            $aggregates[$shop] = strtolower((string) Str::ulid());
            TraceId::within($trace, function () use ($shop, $aggregates): void {
                $this->inside($shop, function () use ($shop, $aggregates): void {
                    app(EventStore::class)->append('test.traced', 'transaction', $aggregates[$shop], ['n' => 1], 'test');
                    Incidents::record(Incidents::JOB_FAILED, new RuntimeException('山田 太郎'), location: "tests {$shop}");
                });
                app(AuditLog::class)->record('operator.tenant_request', $this->owner, ['route' => 'x'], tenantId: $shop, ip: '203.0.113.9');
            });
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\Example', 'tenant_id' => $shop, 'data' => ['secret' => '山田'], 'illuminate:log:context' => ['data' => [TraceId::KEY => serialize($trace)], 'hidden' => []]]),
                'exception' => 'RuntimeException: 山田 太郎',
                'failed_at' => CarbonImmutable::now(),
            ]);
        }

        // 別の ID の記録は出ない
        $this->inside('tra', fn () => TraceId::within(strtolower((string) Str::ulid()), fn () => app(EventStore::class)->append('test.other', 'transaction', strtolower((string) Str::ulid()), [], 'test')));

        $body = $this->send('GET', "/api/tenants/tra/traces/{$trace}")->assertOk()->json();
        $this->assertSame($trace, $body['trace_id']);
        $this->assertSame(['test.traced'], array_column($body['events'], 'type'));
        $this->assertSame([$aggregates['tra']], array_column($body['events'], 'aggregate_id'));
        $this->assertCount(1, $body['audit']);
        $this->assertSame(['sequence', 'type', 'operator_id', 'at'], array_keys($body['audit'][0]));
        $this->assertSame(['tests tra'], array_column($body['incidents'], 'location'));
        $this->assertCount(1, $body['failed_jobs']);
        $this->assertSame('App\\Jobs\\Example', $body['failed_jobs'][0]['job']);
        // 中身（イベントの payload・監査の接続元と中身・例外の文・仕事の中身）は返さない
        $raw = (string) json_encode($body, JSON_UNESCAPED_UNICODE);

        foreach (['203.0.113.9', '山田', 'payload', '"n"', $aggregates['trb'], 'tests trb'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $raw, $hidden);
        }

        // 大文字の ID でも同じ記録。形の違う ID は 404
        $this->assertSame($body['events'], $this->send('GET', '/api/tenants/tra/traces/'.strtoupper($trace))->assertOk()->json('events'));
        $this->send('GET', '/api/tenants/tra/traces/not-a-trace')->assertNotFound();
        $this->send('GET', "/api/tenants/tra/traces/{$trace}x")->assertNotFound();

        // スタッフは、店のお金まで任されていても見られない（障害の調べ物はオーナーだけ）。オーナーのセッションを捨てて、スタッフで入り直す
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'tra', 'money', 'test');
        $this->send('GET', "/api/tenants/tra/traces/{$trace}")->assertForbidden();
    }

    public function test_a_breakage_keeps_the_id_of_its_last_occurrence(): void
    {
        $first = TraceId::start();
        Incidents::record(Incidents::HTTP_ERROR, new RuntimeException('x'), location: 'tests here');
        $this->assertSame($first, Incident::query()->sole()->last_trace_id);

        $second = TraceId::start();
        Incidents::record(Incidents::HTTP_ERROR, new RuntimeException('x'), location: 'tests here');
        $row = Incident::query()->sole();
        $this->assertSame(2, $row->count);
        $this->assertSame($second, $row->last_trace_id);
    }

    /**
     * 支払いの済んだショップの注文（お客さんのメールあり。発送すると知らせのメールを積む）
     *
     * @return array{0: string, 1: string} 取引・受渡
     */
    private function paidShopOrder(string $shop): array
    {
        return $this->inside($shop, function (): array {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 2);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');
            $line = $sale->lines()->where('line_type', LineType::Item)->sole();
            $fulfillment = app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => 1], $location, ['name' => '山田 花子'], 'test');
            app(LedgerService::class)->recordPayment($sale, $sale->total_amount, PaymentRoute::Platform, 'pi_test', 'test');
            app(TransactionService::class)->transition($sale, Phase::Payment, 'test');
            CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);

            return [$sale->id, $fulfillment->id];
        });
    }

    /**
     * キューに積む時と同じ形の仕事の中身（Laravel の Context と店の ID が載る）
     *
     * @return array<string, mixed>
     */
    private function payloadOf(object $job): array
    {
        $queue = app('queue')->connection('sync');
        $this->assertInstanceOf(Queue::class, $queue);
        $payload = (fn (): string => $this->createPayload($job, 'default'))->call($queue);
        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * 処理役（queue:work）と同じ道で 1 つ処理する（処理の前の出来事 → 仕事 → 処理の後の出来事）
     *
     * @param  array<string, mixed>  $payload
     */
    private function process(array $payload): void
    {
        $job = new SyncJob(app(), (string) json_encode($payload), 'sync', 'default');
        app('queue.worker')->process('sync', $job, new WorkerOptions);
    }

    private function tenantModel(string $id): Tenant
    {
        return Tenant::query()->findOrFail($id);
    }
}

/**
 * 処理する時の ID と店を書き留める仕事
 */
final class TraceIdTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<array{trace: string|null, tenant: string|null, log_tenant: mixed}> */
    public static array $seen = [];

    /**
     * 書き留めた分（読むだけ。呼ぶたびに変わりうる）
     *
     * @phpstan-impure
     *
     * @return list<array{trace: string|null, tenant: string|null, log_tenant: mixed}>
     */
    public static function seen(): array
    {
        return self::$seen;
    }

    public function handle(): void
    {
        $tenant = tenant();
        self::$seen[] = [
            'trace' => TraceId::current(),
            'tenant' => $tenant === null ? null : (string) $tenant->getTenantKey(),
            'log_tenant' => Context::get(TraceId::TENANT_KEY),
        ];
    }
}
