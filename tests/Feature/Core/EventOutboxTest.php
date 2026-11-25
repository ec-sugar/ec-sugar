<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Incident;
use App\Admin\Models\ShopDigest;
use App\Admin\Services\Incidents;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\ShopDigests;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventDeliveryStopped;
use App\Core\Event\Outbox\EventReceivers;
use App\Core\Event\Outbox\EventRelay;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Trace\TraceId;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\SentMail;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 非同期の確かさ（ADR 0089）。記録（domain_events）を後でする仕事の控えにし、送り出し役（EventRelay）が名前のある受け手へ流す。
 * コミットの直後に落ちても見回りが流し直す・2 回届いても二重にならない・同じ集約は順に・止めた App には流さない・
 * 何度も落ちる受け手は止めて要対応に・ほかの店に混ざらない・流し直しの操作はオーナーと再確認だけ
 */
final class EventOutboxTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    /** @var list<string> 試しの受け手が受け取った記録（店の ID:記録の種類:集約の ID） */
    private array $seen = [];

    /** @var array<string, int> 試しの受け手が、この集約の記録で落ちる残りの回数 */
    private array $failFor = [];

    /** @var array<int, int> 試しの受け手が、この番号の記録で落ちる残りの回数 */
    private array $failSequence = [];

    protected function setUp(): void
    {
        parent::setUp();
        FakeStripe::install();
        $this->configureAdminHost();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_crash_right_after_the_commit_is_picked_up_by_the_sweep_under_the_original_trace_id(): void
    {
        Mail::fake();
        $this->shop('oba');
        [$sale, $ref] = $this->order('oba');

        $original = $this->inside('oba', function () use ($sale, $ref): string {
            $trace = TraceId::start();
            // 払いの確定はコミットしたが、知らせを流す前にプロセスが落ちた
            EventRelay::pausing(fn () => app(PaymentNotices::class)->confirmPaid('stripe', 'evt_1', 'checkout.session.completed', $ref, $sale, 4400, 'jpy', 'pi_1'));
            $this->assertSame(1, DomainEvent::query()->where('type', PaymentConfirmed::type())->where('aggregate_id', $sale)->count());
            $this->assertSame(0, SentMail::query()->count());

            return $trace;
        });
        Mail::assertNothingSent();

        // 毎分の見回り（別の処理の ID）が印から先を拾い、元の記録の処理の ID の下で流す
        $this->inside('oba', static fn () => TraceId::start());
        $this->artisan('events:relay')->assertSuccessful();

        Mail::assertSent(OrderMail::class, static fn (OrderMail $mail): bool => $mail->kind === 'order_paid');
        $this->inside('oba', function () use ($original): void {
            $this->assertSame([$original], SentMail::query()->pluck('trace_id')->all());
            $this->assertSame(['waiting' => 0, 'stopped' => 0], app(EventRelay::class)->counts());
        });
    }

    public function test_every_record_delivered_twice_gives_the_same_result(): void
    {
        Mail::fake();
        $this->shop('obb');
        [$sale, $ref, $fulfillment] = $this->order('obb');

        $this->inside('obb', function () use ($sale, $ref, $fulfillment): void {
            app(PaymentNotices::class)->confirmPaid('stripe', 'evt_1', 'checkout.session.completed', $ref, $sale, 4400, 'jpy', 'pi_1');
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale), $fulfillment, 'yamato', '1234-5678', 'test');
        });
        Mail::assertSent(OrderMail::class, 2);

        $before = $this->inside('obb', fn (): array => $this->effects());

        // 印を最初に戻して、全部の記録を全部の受け手（核と、入っている App。止めた App には届かない）へもう一度流す（少なくとも 1 回の届け方で起きうる 2 回目）
        $this->inside('obb', function (): void {
            DB::table('event_receiver_positions')->update(['position' => 0]);
            $result = app(EventRelay::class)->run();
            $this->assertNotNull($result);
            $this->assertSame(0, $result['failed'] + $result['stopped']);
            $this->assertGreaterThan(0, $result['delivered']);
        });

        Mail::assertSent(OrderMail::class, 2);
        $this->assertSame($before, $this->inside('obb', fn (): array => $this->effects()));
    }

    public function test_records_of_one_aggregate_go_in_order_and_a_failure_holds_only_the_later_ones_of_that_aggregate(): void
    {
        $this->shop('obc');
        $this->receiver('test.recorder');
        $a = strtolower((string) Str::ulid());
        $b = strtolower((string) Str::ulid());

        $this->inside('obc', function () use ($a, $b): void {
            $this->failFor[$a] = 1;
            $this->append('test.step', $a);
            $this->append('test.step', $a);
            $this->append('test.step', $b);

            // a の 1 件目が落ちた: a の 2 件目は順番待ち、b は先へ進む
            $this->assertSame(["obc:test.step:{$b}"], $this->seen);
            $this->assertSame(2, DB::table('event_retries')->where('status', 'waiting')->count());
            $this->assertSame([1, 0], DB::table('event_retries')->orderBy('sequence')->pluck('attempts')->map(static fn ($n): int => (int) $n)->all());
            // 落ちたことは壊れたら知らせる仕組みへ（どこで落ちたか）
            $this->assertSame(1, (int) Incident::query()->where('kind', Incidents::EVENT_FAILED)->where('exception', RuntimeException::class)->where('tenant_id', 'obc')->value('count'));

            // 間を空ける前の見回りでは届けない（a の 2 件目も前を追い越さない）
            app(EventRelay::class)->run();
            $this->assertCount(1, $this->seen);

            $this->travel(2)->minutes();
            app(EventRelay::class)->run();
            $this->assertSame(["obc:test.step:{$b}", "obc:test.step:{$a}", "obc:test.step:{$a}"], $this->seen);
            $this->assertSame(['waiting' => 0, 'stopped' => 0], app(EventRelay::class)->counts());
        });
    }

    public function test_a_receiver_that_keeps_failing_is_stopped_and_does_not_block_the_records_after_it(): void
    {
        $this->shop('obd');
        $this->receiver('test.recorder');
        $a = strtolower((string) Str::ulid());

        $this->inside('obd', function () use ($a): void {
            // 1 件目だけがいつも落ちる
            $first = EventRelay::pausing(fn (): DomainEvent => $this->append('test.step', $a));
            $this->failSequence[$first->sequence] = 100;
            $this->append('test.step', $a);

            for ($i = 1; $i < EventRelay::MAX_ATTEMPTS; $i++) {
                $this->travel(3)->hours();
                app(EventRelay::class)->run();
            }

            // 1 件目は止め（要対応）、後ろの記録は先へ進む（毒の記録で詰まらない）
            $this->assertSame(["obd:test.step:{$a}"], $this->seen);
            $this->assertSame(['waiting' => 0, 'stopped' => 1], app(EventRelay::class)->counts());
            $this->assertSame(RuntimeException::class, DB::table('event_retries')->value('error'));
            // 落ちた回と止めたことは、見回りの中でも壊れたら知らせる仕組みに数える（止めたものは受け手ごとに 1 行）
            $this->assertSame(EventRelay::MAX_ATTEMPTS, (int) Incident::query()->where('kind', Incidents::EVENT_FAILED)->where('exception', RuntimeException::class)->where('tenant_id', 'obd')->sum('count'));
            $this->assertSame('receiver test.recorder', Incident::query()->where('kind', Incidents::EVENT_FAILED)->where('exception', EventDeliveryStopped::class)->where('tenant_id', 'obd')->value('location'));

            // 運営の様子の数（中央には件数だけ）
            app(ShopDigests::class)->collect();
        });
        $digest = ShopDigest::query()->findOrFail('obd');
        $this->assertSame([0, 1], [$digest->event_waiting, $digest->event_stopped]);

        // 直した後に流し直す（見回りの --resume）
        $this->failSequence = [];
        $this->artisan('events:relay', ['--tenant' => 'obd', '--resume' => true])->assertSuccessful();
        $this->assertSame(["obd:test.step:{$a}", "obd:test.step:{$a}"], $this->seen);
        $this->inside('obd', fn () => $this->assertSame(['waiting' => 0, 'stopped' => 0], app(EventRelay::class)->counts()));
    }

    public function test_a_stopped_app_gets_no_records_not_even_those_from_while_it_was_stopped(): void
    {
        $this->shop('obe');
        $this->receiver('test.app-recorder', app: 'faq');
        $a = strtolower((string) Str::ulid());

        $this->inside('obe', function () use ($a): void {
            // 使っていない
            $this->append('test.step', $a);
            $this->travel(2)->seconds();
            app(PluginStates::class)->set('faq', true, 'operator:test');
            $this->travel(2)->seconds();
            $this->append('test.step', $a);
            $this->assertCount(1, $this->seen);

            // 落ちて届け直しを待つ間に止め、止めている間にも記録が積まれ、見回りが流す前に使うに戻した
            $this->failFor[$a] = 100;
            $this->append('test.step', $a);
            $this->travel(2)->seconds();
            app(PluginStates::class)->set('faq', false, 'operator:test');
            $this->travel(2)->seconds();
            EventRelay::pausing(fn () => $this->append('test.step', $a));
            $this->travel(2)->seconds();
            app(PluginStates::class)->set('faq', true, 'operator:test');
            $this->failFor = [];
            $this->travel(5)->minutes();
            app(EventRelay::class)->run();

            // 止める前の落ちた記録・止めている間の記録は、使うに戻しても届けない
            $this->assertCount(1, $this->seen);
            $this->assertSame(['waiting' => 0, 'stopped' => 0], app(EventRelay::class)->counts());

            // 戻した後の記録は届く
            $this->append('test.step', $a);
            $this->assertCount(2, $this->seen);
        });
    }

    public function test_a_receiver_that_writes_records_gets_them_delivered_in_the_same_pass(): void
    {
        $this->shop('obf');
        $receivers = app(EventReceivers::class);
        $receivers->on('test.echo', ['test.step'], static fn (DomainEvent $event) => app(EventStore::class)->append('test.echoed', 'test', $event->aggregate_id));
        $this->receiver('test.recorder', types: ['test.echoed']);
        $a = strtolower((string) Str::ulid());

        $this->inside('obf', function () use ($a): void {
            $this->append('test.step', $a);
            $this->assertSame(["obf:test.echoed:{$a}"], $this->seen);
        });
    }

    public function test_shops_do_not_mix(): void
    {
        $this->shop('obg');
        $this->shop('obh');
        $this->receiver('test.recorder');
        $a = strtolower((string) Str::ulid());
        $b = strtolower((string) Str::ulid());

        $this->failFor[$a] = 1;
        $this->inside('obg', fn () => EventRelay::pausing(fn () => $this->append('test.step', $a)));
        $this->inside('obh', fn () => EventRelay::pausing(fn () => $this->append('test.step', $b)));

        // 見回りは店ごとに店の中で流す（受け手が見る店は記録の店）
        $this->artisan('events:relay')->assertSuccessful();
        $this->assertSame(["obh:test.step:{$b}"], $this->seen);
        $this->inside('obg', fn () => $this->assertSame(['waiting' => 1, 'stopped' => 0], app(EventRelay::class)->counts()));
        $this->inside('obh', fn () => $this->assertSame(['waiting' => 0, 'stopped' => 0], app(EventRelay::class)->counts()));

        $this->travel(2)->minutes();
        $this->artisan('events:relay', ['--tenant' => 'obh'])->assertSuccessful();
        $this->assertCount(1, $this->seen);
        $this->artisan('events:relay', ['--tenant' => 'obg'])->assertSuccessful();
        $this->assertSame(["obh:test.step:{$b}", "obg:test.step:{$a}"], $this->seen);
    }

    public function test_a_shop_whose_database_is_being_rebuilt_is_skipped_and_the_others_flow(): void
    {
        $this->shop('obr');
        $this->shop('obs');
        $this->receiver('test.recorder');
        $a = strtolower((string) Str::ulid());
        $b = strtolower((string) Str::ulid());
        $this->inside('obr', fn () => EventRelay::pausing(fn () => $this->append('test.step', $a)));
        $this->inside('obs', fn () => EventRelay::pausing(fn () => $this->append('test.step', $b)));

        // 店 obr は作り直しの途中（印を立て、店の DB を消した所。デモの店の毎晩の戻し〔ADR 0086〕の途中と同じ形）
        $rebuilt = Tenant::query()->findOrFail('obr');
        $rebuilt->setAttribute(Tenant::REBUILDING, ['at' => now()->toIso8601String(), 'stripe' => null]);
        $rebuilt->save();
        $rebuilt->database()->manager()->deleteDatabase($rebuilt);
        $this->assertTrue($rebuilt->rebuildingNow());

        // 見回りはその店に入らず、失敗に数えずに終わる（定期の処理の失敗として運営に知らせない）。ほかの店は流れる
        $this->artisan('events:relay')->expectsOutputToContain('作り直し中で飛ばした店 1')->assertSuccessful();
        $this->assertSame(["obs:test.step:{$b}"], $this->seen);
        $this->artisan('events:relay', ['--tenant' => 'obr'])->assertSuccessful();
        $this->artisan('operations:collect')->expectsOutputToContain('作り直し中で飛ばした店 1')->assertSuccessful();
        $this->assertFalse(ShopDigest::query()->where('tenant_id', 'obr')->whereNotNull('failed_at')->exists());

        // 店ごとに回るほかの定期の処理も、その店を飛ばして終わる（核の処理と、App の処理〔PluginTenants〕）
        $commands = ['points:expire', 'storefront:prune-carts', 'mail-records:prune', 'gift-cards:expire', 'gift-cards:issue', 'storefront:expire-unpaid-orders', 'storefront:reconcile-refunds'];

        // App の処理の例は抽選販売とチケット（公開用の 1.0 の木には入らない。ADR 0098。入っているときだけ）
        foreach (['lottery' => 'lottery:run', 'tickets' => 'tickets:resale'] as $app => $command) {
            if ($this->hasApp($app)) {
                $commands[] = $command;
            }
        }

        foreach ($commands as $command) {
            $this->artisan($command)->assertSuccessful();
        }

        $this->assertSame(0, Incident::query()->count());
    }

    public function test_a_new_receiver_starts_from_the_records_of_the_last_few_minutes(): void
    {
        $this->shop('obi');
        $a = strtolower((string) Str::ulid());
        $this->inside('obi', fn () => $this->append('test.step', $a));
        $this->travel(EventRelay::LOOKBACK_MINUTES + 1)->minutes();
        $this->inside('obi', fn () => $this->append('test.step', $a));

        // 後から入った受け手（新しい App のバージョンなど）は、前の記録を全部は流さない
        $this->receiver('test.recorder');
        $this->inside('obi', fn () => app(EventRelay::class)->run());
        $this->assertCount(1, $this->seen);
    }

    public function test_only_an_owner_after_a_fresh_confirmation_can_retry_and_only_in_that_shop(): void
    {
        $this->shop('obj');
        $this->shop('obk');
        $this->receiver('test.recorder');
        $a = strtolower((string) Str::ulid());
        $this->failFor[$a] = 100;
        $this->inside('obj', function () use ($a): void {
            $this->append('test.step', $a);
            DB::table('event_retries')->update(['status' => 'stopped']);
        });
        $this->inside('obk', function () use ($a): void {
            $this->append('test.step', $a);
            DB::table('event_retries')->update(['status' => 'stopped']);
        });
        $this->failFor = [];

        [$staff, $staffKey] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'obj', 'money', 'test');
        $this->reconfirm($staff, $staffKey);
        $this->send('POST', '/api/tenants/obj/event-retries')->assertForbidden();
        $this->send('POST', '/auth/logout')->assertSuccessful();

        $this->resetRateLimit();
        [$owner, $ownerKey] = $this->enrolledOperator('owner@example.com');
        // 直前の再確認が無ければ流さない
        $this->send('POST', '/api/tenants/obj/event-retries')->assertStatus(423);
        $this->assertSame([], $this->seen);

        $this->reconfirm($owner, $ownerKey);
        $this->send('POST', '/api/tenants/obj/event-retries')->assertOk()
            ->assertJsonPath('resumed', 1)->assertJsonPath('delivered', 1)->assertJsonPath('stopped', 0)->assertJsonPath('waiting', 0);

        // 入った店の記録だけ（ほかの店の止めた記録はそのまま）
        $this->assertSame(["obj:test.step:{$a}"], $this->seen);
        $this->inside('obk', fn () => $this->assertSame(['waiting' => 0, 'stopped' => 1], app(EventRelay::class)->counts()));
        $audit = AuditEvent::query()->where('type', 'tenant.events_retried')->sole();
        $this->assertSame(['obj', $owner->id], [$audit->tenant_id, $audit->operator_id]);
        $this->assertSame(0, ShopDigest::query()->findOrFail('obj')->event_stopped);
    }

    /**
     * 試しの受け手（受け取った記録を並べ、$failFor の集約では落ちる）
     *
     * @param  list<string>  $types
     */
    private function receiver(string $name, ?string $app = null, array $types = ['test.step']): void
    {
        app(EventReceivers::class)->on($name, $types, function (DomainEvent $event): void {
            if (($this->failFor[$event->aggregate_id] ?? 0) > 0 || ($this->failSequence[$event->sequence] ?? 0) > 0) {
                isset($this->failFor[$event->aggregate_id]) ? $this->failFor[$event->aggregate_id]-- : $this->failSequence[$event->sequence]--;

                throw new RuntimeException('試しの受け手が落ちた');
            }

            $this->seen[] = tenant()?->getTenantKey().':'.$event->type.':'.$event->aggregate_id;
        }, $app);
    }

    private function append(string $type, string $aggregateId): DomainEvent
    {
        return DB::transaction(static function () use ($type, $aggregateId): DomainEvent {
            app(EventStore::class)->lock();

            return app(EventStore::class)->append($type, 'test', $aggregateId, [], 'system:test');
        });
    }

    private function shop(string $id): void
    {
        $this->tenant($id);
        $this->inside($id, fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]));
    }

    /**
     * ショップの注文（払う前）。[取引の ID, 支払いの画面の ID, 受渡の ID]
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function order(string $shop): array
    {
        return $this->inside($shop, function (): array {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');
            $line = $sale->lines()->where('line_type', LineType::Item)->sole();
            $fulfillment = app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => 1], $location, ['name' => '山田 花子', 'city' => '渋谷区'], 'test');
            CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);

            return [$sale->id, (string) app(PaymentService::class)->startCheckout($sale, 'x', 'y')->ref, $fulfillment->id];
        });
    }

    /**
     * 受け手が残す作用の数（メールの記録・ポイント・クーポン・記録の種類ごとの数。受け手の届け直しの印は除く）
     *
     * @return array<string, mixed>
     */
    private function effects(): array
    {
        return [
            'sent_mails' => SentMail::query()->orderBy('kind')->get(['kind', 'status'])->toArray(),
            'points' => DB::table('point_entries')->count(),
            'events' => DomainEvent::query()->selectRaw('type, count(*) AS n')->groupBy('type')->orderBy('type')->pluck('n', 'type')->map(static fn ($n): int => (int) $n)->all(),
        ];
    }
}
