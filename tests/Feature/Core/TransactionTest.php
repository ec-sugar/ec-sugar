<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Event\DomainEventRecorded;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Enums\LocationKind;
use App\Core\Inventory\Enums\UnitStatus;
use App\Core\Inventory\Models\InventoryUnit;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InsufficientStockException;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\SellerStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\PartyRole;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\QuoteStatus;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Services\TransactionService;
use App\Core\Transaction\TransactionFlow;
use App\Core\Transaction\TransactionFlows;
use App\Models\Tenant;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use Tests\TestCase;

/**
 * 核の第 3 片: 取引のフェーズ、提示、仕訳、受渡、追記専用のイベント（CONCEPT 5.4〜5.7、ADR 0011・0012）。
 */
final class TransactionTest extends TestCase
{
    use DatabaseTruncation;

    public function test_sale_runs_from_agreement_to_settlement_with_ledger_stock_and_events(): void
    {
        $this->inTenant('trs', function (): void {
            [$seller, $store, $listing] = $this->product(stock: 5);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '佐藤 花子']);

            $recorded = [];
            Event::listen(DomainEventRecorded::class, static function (DomainEventRecorded $e) use (&$recorded): void {
                $recorded[] = $e->event->type;
            });

            $service = app(TransactionService::class);
            $ledger = app(LedgerService::class);

            // 1,100 円 × 2 − 値引き 200 円 = 2,000 円（内税 10%）。税は合計から 1 回だけ切り捨て: 2000 × 10/110 = 181.8 → 181
            $sale = $service->open('sale', $seller, $customer, [
                LineDraft::fromListing($listing, 2, 1000, $store),
                LineDraft::adjustment('discount', '初回値引き', -200, 1000),
            ], actor: 'system');

            $this->assertSame(Phase::Agreement, $sale->phase);
            $this->assertSame(PartyRole::Customer, $sale->payer);
            $this->assertSame([2000, 181, 1819], [$sale->total_amount, $sale->tax_amount, $sale->subtotal_amount]);
            $this->assertNotNull($sale->agreed_at);

            // 合意で仕訳（請求と消費税）が立ち、在庫が確保される
            $this->assertSame(['customer' => -2000, 'seller' => 1819, 'tax' => 181], $ledger->balances($sale));
            $this->assertSame(2, $listing->stocks()->firstOrFail()->reserved);

            // 支払い（決済代行が預かる）。合意額を超える記録はできない
            $sale = $service->transition($sale, Phase::Payment);
            $ledger->recordPayment($sale, 2000, PaymentRoute::Platform, 'pi_test_123');
            $this->assertSame(['customer' => 0, 'provider' => -2000, 'seller' => 1819, 'tax' => 181], $ledger->balances($sale));

            try {
                $ledger->recordPayment($sale, 1, PaymentRoute::Platform);
                $this->fail('合意額を超えて支払いを記録できてしまった');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }

            // 受渡: 配送先は暗号化して保存し、完了で確保分を出庫する
            $sale = $service->transition($sale, Phase::Fulfillment);
            $line = $sale->lines()->where('line_type', LineType::Item)->firstOrFail();
            $fulfillments = app(FulfillmentService::class);
            $shipment = $fulfillments->create($sale, FulfillmentMethod::Shipping, [$line->id => 2], $store, [
                'name' => '佐藤 花子', 'zip' => '150-0001', 'line1' => '渋谷区神宮前 1-1',
            ]);

            $raw = (string) DB::table('fulfillments')->where('id', $shipment->id)->value('address');
            $this->assertStringNotContainsString('佐藤', $raw);
            $this->assertSame('佐藤 花子', $shipment->refresh()->address['name'] ?? null);

            try {
                $fulfillments->create($sale, FulfillmentMethod::Shipping, [$line->id => 1]);
                $this->fail('明細の数量を超えて配送を作れてしまった');
            } catch (TransactionException) {
                $this->addToAssertionCount(1);
            }

            $fulfillments->ship($shipment, 'yamato', '1234-5678-9012');
            $fulfillments->complete($shipment);
            $stock = $listing->stocks()->firstOrFail();
            $this->assertSame([3, 0], [$stock->quantity, $stock->reserved]);

            // 検収 → 精算で完了。手数料を引いて出品者へ送金する
            $sale = $service->transition($sale, Phase::Acceptance);
            $sale = $service->transition($sale, Phase::Settlement);
            $this->assertSame(TransactionStatus::Completed, $sale->status);

            $ledger->recordFee($sale, 100);
            $ledger->recordPayout($sale, 1900, 'tr_test_456');
            $balances = $ledger->balances($sale);
            $this->assertSame(['customer' => 0, 'platform' => 100, 'provider' => -100, 'seller' => -181, 'tax' => 181], $balances);
            $this->assertSame(0, array_sum($balances));

            // 並びにない遷移は拒否
            try {
                $service->transition($sale, Phase::Payment);
                $this->fail('精算から支払いへ戻れてしまった');
            } catch (TransactionException) {
                $this->addToAssertionCount(1);
            }

            // すべての遷移と仕訳がイベントとして残り、連鎖は無傷。購読者にはコミット後に届く
            $types = DomainEvent::query()->where('aggregate_id', $sale->id)->orderBy('sequence')->pluck('type')->all();
            $this->assertSame('transaction.opened', $types[0]);
            $this->assertContains('ledger.journal_posted', $types);
            $this->assertContains('fulfillment.completed', $types);
            $this->assertSame(4, collect($types)->filter(static fn (string $t): bool => $t === 'transaction.phase_changed')->count());
            $this->assertSame(count($types), count($recorded));

            $verification = app(EventStore::class)->verify();
            $this->assertTrue($verification->intact());
            $this->assertSame(count($types), $verification->checked);

            // イベントに個人情報の平文が入っていない
            $this->assertStringNotContainsString('佐藤', (string) DB::table('domain_events')->pluck('payload')->implode(''));
        });
    }

    public function test_cancel_releases_stock_and_reverses_the_agreement(): void
    {
        $this->inTenant('trc', function (): void {
            [$seller, $store, $listing] = $this->product(stock: 3);
            $service = app(TransactionService::class);

            $sale = $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 2, 1000, $store)]);
            $this->assertSame(1, $listing->stocks()->firstOrFail()->available());

            $sale = $service->transition($sale, Phase::Cancel);
            $this->assertSame(TransactionStatus::Cancelled, $sale->status);
            $this->assertNotNull($sale->closed_at);
            $this->assertSame(3, $listing->stocks()->firstOrFail()->available());
            $this->assertSame(['customer' => 0, 'seller' => 0, 'tax' => 0], app(LedgerService::class)->balances($sale));

            $this->expectException(TransactionException::class);
            $service->transition($sale, Phase::Payment);
        });
    }

    public function test_dispute_suspends_and_resumes_and_refund_closes_a_completed_sale(): void
    {
        $this->inTenant('trd', function (): void {
            [$seller, $store, $listing] = $this->product(stock: 1);
            $service = app(TransactionService::class);

            $sale = $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, $store)]);
            $sale = $service->transition($sale, Phase::Dispute);
            $this->assertSame(Phase::Agreement, $sale->suspended_phase);

            try {
                $service->transition($sale, Phase::Payment); // 紛争中は元のフェーズへ戻るか、取消・返金だけ
                $this->fail('紛争中に先へ進めてしまった');
            } catch (TransactionException) {
                $this->addToAssertionCount(1);
            }

            $sale = $service->transition($sale, Phase::Agreement);
            $this->assertNull($sale->suspended_phase);

            foreach ([Phase::Payment, Phase::Fulfillment, Phase::Acceptance, Phase::Settlement, Phase::Refund] as $phase) {
                $sale = $service->transition($sale, $phase);
            }

            $this->assertSame(TransactionStatus::Refunded, $sale->status);
            $this->assertSame(0, array_sum(array_map(abs(...), app(LedgerService::class)->balances($sale))));
        });
    }

    public function test_a_paid_sale_becomes_refunded_only_after_the_refund_is_booked(): void
    {
        // 受け取ったお金が台帳に残ったまま「返金済み」にしない（決済代行での返金と台帳を食い違わせない）
        $this->inTenant('trr', function (): void {
            [$seller, $store, $listing] = $this->product(stock: 1);
            $service = app(TransactionService::class);
            $ledger = app(LedgerService::class);
            $sale = $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, $store)]);
            $ledger->recordPayment($sale, (int) $sale->total_amount, PaymentRoute::Platform, 'pi_test');

            foreach ([Phase::Payment, Phase::Fulfillment, Phase::Acceptance, Phase::Settlement] as $phase) {
                $sale = $service->transition($sale, $phase);
            }

            try {
                $service->transition($sale, Phase::Refund);
                $this->fail('受け取ったお金を返さずに返金済みにした');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('受け取ったお金がまだ', $e->getMessage());
            }
            $this->assertSame(TransactionStatus::Completed, Transaction::query()->findOrFail($sale->id)->status);

            // 一部だけ返しても進めない。全部返したら進める
            $ledger->recordRefund($sale, 100, PaymentRoute::Platform, 're_part');
            $this->expectRefundRefused($service, $sale);
            $ledger->recordRefund($sale, (int) $sale->total_amount - 100, PaymentRoute::Platform, 're_rest');
            $sale = $service->transition(Transaction::query()->findOrFail($sale->id), Phase::Refund);
            $this->assertSame(TransactionStatus::Refunded, $sale->status);
        });
    }

    public function test_a_sale_is_completed_only_after_every_item_is_handed_over(): void
    {
        // 一部の品だけ渡して完了にしない（残りの在庫を押さえたままになる）
        $this->inTenant('trs', function (): void {
            [$seller, $store, $listing] = $this->product(stock: 2);
            $service = app(TransactionService::class);
            $fulfillments = app(FulfillmentService::class);
            $orders = app(SaleOrderService::class);
            $sale = $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 2, 1000, $store)]);
            $line = (string) $sale->lines()->where('line_type', LineType::Item)->value('id');
            $sale = $service->transition($sale, Phase::Payment);

            $first = $fulfillments->create($sale, FulfillmentMethod::Shipping, [$line => 1], $store, ['name' => 'テスト']);
            $orders->ship(Transaction::query()->findOrFail($sale->id), $first->id, 'yamato', '1111', 'test');
            try {
                $orders->complete(Transaction::query()->findOrFail($sale->id), 'test');
                $this->fail('1 点しか渡していないのに完了にした');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('まだ渡していない商品が 1 点', $e->getMessage());
            }

            $second = $fulfillments->create(Transaction::query()->findOrFail($sale->id), FulfillmentMethod::Shipping, [$line => 1], $store, ['name' => 'テスト']);
            $orders->ship(Transaction::query()->findOrFail($sale->id), $second->id, 'yamato', '2222', 'test');
            $done = $orders->complete(Transaction::query()->findOrFail($sale->id), 'test');
            $this->assertSame(TransactionStatus::Completed, $done->status);
        });
    }

    private function expectRefundRefused(TransactionService $service, Transaction $sale): void
    {
        try {
            $service->transition(Transaction::query()->findOrFail($sale->id), Phase::Refund);
            $this->fail('一部しか返していないのに返金済みにした');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_quotes_from_several_sellers_and_reappraisal_like_buyback(): void
    {
        // 買取の形をしたテスト用の流れ: 仮査定 → 合意 → 送付 → 本査定 → 合意 → 精算（お金は店 → 顧客）
        app(TransactionFlows::class)->register(new TransactionFlow(
            kind: 'test_buyback',
            label: 'テスト買取',
            initial: Phase::Quote,
            transitions: [
                Phase::Quote->value => [Phase::Agreement],
                Phase::Agreement->value => [Phase::Fulfillment, Phase::Settlement],
                Phase::Fulfillment->value => [Phase::Quote],
            ],
            completesAt: [Phase::Settlement],
            payer: PartyRole::Seller,
            payee: PartyRole::Customer,
            reservesInventory: false,
        ));

        $this->inTenant('trq', function (): void {
            [$shopA, , $listingA] = $this->product(stock: 0);
            $shopB = Seller::create(['name' => 'B 店', 'slug' => 'b', 'status' => SellerStatus::Active]);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '鈴木 一郎']);
            $service = app(TransactionService::class);
            $ledger = app(LedgerService::class);

            $deal = $service->open('test_buyback', null, $customer);
            $this->assertSame(Phase::Quote, $deal->phase);
            $this->assertNull($deal->seller_id);

            // 他の出品者の出品物を自分の提示に入れられない
            try {
                $service->propose($deal, $shopB, [LineDraft::fromListing($listingA, 1, 0)]);
                $this->fail('他の販売者の出品物で提示できてしまった');
            } catch (TransactionException) {
                $this->addToAssertionCount(1);
            }

            $quoteA = $service->propose($deal, $shopA, [new LineDraft(LineType::Item, 'レコード一式（仮査定）', 1, 4000, 0)]);
            $quoteB = $service->propose($deal, $shopB, [new LineDraft(LineType::Item, 'レコード一式（仮査定）', 1, 5000, 0)]);

            $deal = $service->acceptQuote($quoteB);
            $this->assertSame(Phase::Agreement, $deal->phase);
            $this->assertSame($shopB->id, $deal->seller_id);
            $this->assertSame(QuoteStatus::Rejected, $quoteA->refresh()->status);
            $this->assertSame(['customer' => 5000, 'seller' => -5000], $ledger->balances($deal));

            // 決まった後は別の出品者は提示できない（Quote に戻っても）
            $deal = $service->transition($deal, Phase::Fulfillment);
            $inbound = app(FulfillmentService::class)->create($deal, FulfillmentMethod::Inbound);
            app(FulfillmentService::class)->complete($inbound);
            $deal = $service->transition($deal, Phase::Quote);

            try {
                $service->propose($deal, $shopA, [new LineDraft(LineType::Item, '横取り', 1, 9000, 0)]);
                $this->fail('別の販売者が提示できてしまった');
            } catch (TransactionException) {
                $this->addToAssertionCount(1);
            }

            // 本査定で減額 → 合意のやり直し。前の請求は逆仕訳され、新しい額で立つ
            $final = $service->propose($deal, $shopB, [
                new LineDraft(LineType::Item, 'レコード 12 枚', 1, 5000, 0),
                LineDraft::adjustment('appraisal', '盤面の傷', -500, 0),
            ]);
            $deal = $service->acceptQuote($final);
            $this->assertSame(4500, $deal->total_amount);
            $this->assertSame(['customer' => 4500, 'seller' => -4500], $ledger->balances($deal));

            // 店から顧客へ直接振り込んで精算
            $deal = $service->transition($deal, Phase::Settlement);
            $ledger->recordPayment($deal, 4500, PaymentRoute::Direct, 'bank-20261027-001');
            $this->assertSame(['customer' => 0, 'seller' => 0], $ledger->balances($deal));
            $this->assertSame(TransactionStatus::Completed, $deal->status);
            $this->assertTrue(app(EventStore::class)->verify()->intact());
        });
    }

    public function test_one_unit_cannot_be_reserved_by_two_sales_and_failure_leaves_nothing_behind(): void
    {
        $this->inTenant('tru', function (): void {
            [$seller, $store, $listing] = $this->product(stock: 0);
            $unit = InventoryUnit::create([
                'listing_id' => $listing->id, 'location_id' => $store->id, 'identifier' => 'SN-9', 'status' => UnitStatus::Available,
            ]);
            $service = app(TransactionService::class);

            $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, unit: $unit)]);
            $this->assertSame(UnitStatus::Reserved, $unit->refresh()->status);
            $before = [Transaction::count(), DomainEvent::count()];

            try {
                $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, unit: $unit)]);
                $this->fail('同じ個体を 2 回確保できてしまった');
            } catch (InsufficientStockException) {
                $this->addToAssertionCount(1);
            }

            // 取引・明細・仕訳・イベントのどれも半端に残らない
            $this->assertSame($before, [Transaction::count(), DomainEvent::count()]);
        });
    }

    public function test_ledger_and_events_are_append_only_and_tampering_is_detected(): void
    {
        $this->inTenant('trl', function (): void {
            [$seller, $store, $listing] = $this->product(stock: 1);
            app(TransactionService::class)->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, $store)]);

            foreach (['ledger_postings', 'ledger_journals', 'domain_events'] as $table) {
                foreach ([
                    static fn () => DB::table($table)->limit(1)->delete(),
                    static fn () => DB::statement("UPDATE {$table} SET id = id"),
                ] as $mutate) {
                    try {
                        $mutate();
                        $this->fail("{$table} を書き換えられてしまった");
                    } catch (QueryException $e) {
                        $this->assertStringContainsString('追記専用', $e->getMessage());
                    }
                }
            }

            // 片側だけの仕訳はコミット時に DB が拒否する
            try {
                DB::transaction(static function (): void {
                    $journal = DB::table('ledger_journals')->first();
                    DB::table('ledger_postings')->insert([
                        'id' => strtolower((string) Str::ulid()),
                        'journal_id' => $journal?->id,
                        'transaction_id' => $journal?->transaction_id,
                        'account' => 'customer',
                        'amount' => 1,
                    ]);
                });
                $this->fail('貸借の合わない明細を足せてしまった');
            } catch (PDOException $e) { // 遅延制約はコミット時に PDO から直接上がる
                $this->assertStringContainsString('貸借が一致しません', $e->getMessage());
            }

            // テーブルの所有者ならトリガーを外せる。それでも連鎖の検証で改竄が分かる
            $this->assertTrue(app(EventStore::class)->verify()->intact());
            DB::statement('ALTER TABLE domain_events DISABLE TRIGGER USER');
            DB::table('domain_events')->where('sequence', 2)->update(['payload' => json_encode(['total_amount' => 1])]);
            DB::statement('ALTER TABLE domain_events ENABLE TRIGGER USER');

            $this->assertSame(2, app(EventStore::class)->verify()->brokenAt);
        });
    }

    public function test_flows_reject_cross_cutting_transitions_and_payload_rejects_floats(): void
    {
        try {
            app(TransactionFlows::class)->register(new TransactionFlow(
                'test_bad', '不正', Phase::Agreement, [Phase::Agreement->value => [Phase::Refund]], [], PartyRole::Customer, PartyRole::Seller, false,
            ));
            $this->fail('横断フェーズへの遷移を登録できてしまった');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        app(EventStore::class)->append('test.float', 'transaction', '01J00000000000000000000000', ['amount' => 1.5]);
    }

    /**
     * @param  Closure(): void  $test
     */
    private function inTenant(string $id, Closure $test): void
    {
        $tenant = Tenant::create(['id' => $id, 'name' => "テスト {$id}"]);

        try {
            tenancy()->initialize($tenant);
            $test();
        } finally {
            tenancy()->end();
            $tenant->delete();
        }
    }

    /**
     * 既定の出品者と店舗に、1,100 円の公開中の商品を在庫つきで用意する。
     *
     * @return array{0: Seller, 1: Location, 2: Listing}
     */
    private function product(int $stock): array
    {
        $seller = Seller::query()->where('slug', 'default')->firstOrFail();
        $store = Location::query()->where('seller_id', $seller->id)->where('kind', LocationKind::Store)->firstOrFail();
        $listing = Listing::create([
            'seller_id' => $seller->id,
            'kind' => 'product',
            'title' => '中古レコード',
            'unit_amount' => 1100,
            'status' => ListingStatus::Active,
        ]);

        if ($stock > 0) {
            app(StockService::class)->receive($listing, $store, $stock);
        }

        return [$seller, $store, $listing];
    }
}
