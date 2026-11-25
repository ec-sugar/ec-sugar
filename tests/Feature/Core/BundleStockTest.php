<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Event\EventStore;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\ComponentStock;
use App\Core\Inventory\Services\InsufficientStockException;
use App\Core\Inventory\Services\LineStock;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Plugin\Contracts\SaleHoldProvider;
use App\Core\Plugin\Contracts\StockComponentsProvider;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Plugin\Services\StockComponentsUnavailable;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TaxRounding;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Services\TransactionService;
use ArrayObject;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * セット商品の在庫（StockComponentsProvider・LineStock）。
 * セットは自分の在庫を持たず、構成する品から引く。押さえる・解く・出庫するの 3 つが同じ判断を見ることと、
 * **1 つでも足りなければ丸ごと断る**（部分的に押さえた状態を残さない）ことを確かめる
 */
final class BundleStockTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private string $bundle;

    /** @var array<string, string> 品の名前 => 種類の ID */
    private array $parts = [];

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant('bun');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_a_bundle_draws_stock_from_its_parts_and_gives_it_back_when_cancelled(): void
    {
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 10, 'cup' => 4]);
            $this->useBundle(['coffee' => 2, 'cup' => 1]);

            $transaction = $this->order(quantity: 2);

            // セットそのものの在庫は動かず、構成する品から引かれる（コーヒー 2×2、カップ 1×2）
            $this->assertSame(4, $this->reserved('coffee'));
            $this->assertSame(2, $this->reserved('cup'));
            // セットそのものには在庫の行ができない（引いていないので触らない）
            $this->assertFalse(InventoryStock::query()->where('variant_id', $this->bundleVariant())->exists(), 'セットそのものの在庫を触っている');

            // 取り消すと構成する品の確保が戻る
            app(TransactionService::class)->transition($transaction, Phase::Cancel, 'test');
            $this->assertSame(0, $this->reserved('coffee'));
            $this->assertSame(0, $this->reserved('cup'));
        });
    }

    public function test_one_short_part_refuses_the_whole_bundle_and_leaves_nothing_reserved(): void
    {
        $this->inside('bun', function (): void {
            // カップは 1 つしかない。セットは 1 つにカップ 2 つ使う
            $this->build(['coffee' => 10, 'cup' => 1]);
            $this->useBundle(['coffee' => 1, 'cup' => 2]);

            try {
                $this->order(quantity: 1);
                $this->fail('在庫が足りないのに通った');
            } catch (InsufficientStockException) {
                // 先に押さえたコーヒーの分も残っていないこと（部分的に押さえた状態を作らない）
                $this->assertSame(0, $this->reserved('coffee'), 'コーヒーの確保が残っている');
                $this->assertSame(0, $this->reserved('cup'));
                $this->assertSame(0, Transaction::query()->count(), '取引が残っている');
            }
        });
    }

    public function test_two_buyers_cannot_both_take_the_last_bundle(): void
    {
        $this->inside('bun', function (): void {
            // 2 セット分しかない（カップ 2 個、1 セットにカップ 2 個）
            $this->build(['coffee' => 10, 'cup' => 2]);
            $this->useBundle(['coffee' => 1, 'cup' => 2]);

            $this->order(quantity: 1);
            $this->assertSame(2, $this->reserved('cup'));

            // 2 人目は取れない
            try {
                $this->order(quantity: 1);
                $this->fail('最後の 1 セットを 2 人が取れてしまった');
            } catch (InsufficientStockException) {
                $this->assertSame(2, $this->reserved('cup'), '確保が増えている');
            }
        });
    }

    public function test_a_plain_listing_still_uses_its_own_stock(): void
    {
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 5]);
            // セットの App は使うが、ふつうの商品には構成する品が無い
            $this->useBundle([]);

            $line = new LineDraft(
                lineType: LineType::Item, description: 'コーヒー', quantity: 2, unitAmount: 1000, taxRateBp: 1000,
                kind: 'product', listingId: $this->listingOf('coffee'), locationId: $this->location->id, variantId: $this->parts['coffee'],
            );
            app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), null, [$line], actor: 'test', taxRounding: TaxRounding::Floor);

            $this->assertSame(2, $this->reserved('coffee'));
        });
    }

    public function test_the_order_stops_when_the_app_cannot_say_what_the_parts_are(): void
    {
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 10, 'cup' => 4]);
            // 壊れた App。在庫を引かずに売るより止める方が安全
            app(PluginRegistry::class)->register(new PluginDefinition(
                name: 'broken-bundle', title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
                stockComponents: new class implements StockComponentsProvider
                {
                    public function stockComponents(array $listingIds): array
                    {
                        throw new RuntimeException('壊れた');
                    }
                },
            ));
            app(PluginStates::class)->set('broken-bundle', true, 'test');

            try {
                $this->order(quantity: 1);
                $this->fail('壊れた App で売れてしまった');
            } catch (StockComponentsUnavailable) {
                $this->assertSame(0, $this->reserved('coffee'));
                $this->assertSame(0, Transaction::query()->count());
            }
        });
    }

    public function test_a_count_of_zero_or_less_stops_the_order(): void
    {
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 10]);
            $this->useBundle([], raw: [$this->parts['coffee'] => 0]);

            try {
                $this->order(quantity: 1);
                $this->fail('0 個の構成で通った');
            } catch (StockComponentsUnavailable) {
                $this->assertSame(0, $this->reserved('coffee'));
                $this->assertSame(0, Transaction::query()->count());
            }
        });
    }

    public function test_a_malformed_variant_id_is_dropped_so_the_bundle_has_no_stock_of_its_own(): void
    {
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 10]);
            // 形の違う種類の ID は核が捨てる → 構成する品が無い扱いになり、セットそのものの在庫を見る。
            // セットには在庫を入れていないので、売れずに断られる（勝手に売れてしまわない）
            $this->useBundle([], raw: ['../../etc/passwd' => 1, 'NOT-A-ULID' => 2]);

            try {
                $this->order(quantity: 1);
                $this->fail('形の違う構成で通った');
            } catch (InsufficientStockException) {
                $this->assertSame(0, $this->reserved('coffee'), 'コーヒーを引いている');
                $this->assertSame(0, Transaction::query()->count());
            }
        });
    }

    public function test_a_shop_that_does_not_use_the_app_sells_the_bundle_by_its_own_stock(): void
    {
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 10, 'cup' => 4]);
            $this->useBundle(['coffee' => 2, 'cup' => 1]);
            // この店では App を止める（登録簿には残るが、聞いてはいけない）
            app(PluginStates::class)->set('bundle-sample', false, 'test');

            // 構成する品を引かない＝セットそのものの在庫を見るので、在庫が無くて断られる
            try {
                $this->order(quantity: 1);
                $this->fail('App を止めた店で構成する品を引いた');
            } catch (InsufficientStockException) {
                $this->assertSame(0, $this->reserved('coffee'), '止めた App の構成でコーヒーを引いている');
                $this->assertSame(0, $this->reserved('cup'));
            }

            // セットそのものに在庫を入れれば、ふつうの商品として売れる
            app(StockService::class)->receive(Listing::query()->findOrFail($this->bundle), $this->location, 3);
            $this->order(quantity: 1);
            $this->assertSame(1, (int) InventoryStock::query()->where('variant_id', $this->bundleVariant())->sum('reserved'));
            $this->assertSame(0, $this->reserved('coffee'));
        });
    }

    public function test_changing_the_bundle_after_an_order_does_not_change_what_that_order_holds(): void
    {
        // 押さえた時の構成を行に控え、解く・出庫するはその控えから動かす（聞き直さない）。
        // 聞き直すと、確保が残り続ける・「確保していない数」の例外で取消や発送が通らない・ほかの注文の確保を食って売りすぎる
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 20, 'cup' => 10, 'spoon' => 10]);
            $this->useBundle(['coffee' => 2, 'cup' => 1]);

            $first = $this->order(quantity: 2);    // コーヒー 4・カップ 2 を押さえる
            $this->assertSame([4, 2, 0], [$this->reserved('coffee'), $this->reserved('cup'), $this->reserved('spoon')]);

            // 中身を変える（コーヒーを増やし、カップを外し、スプーンを足す）。前の注文が押さえた数は変わらない
            $this->changeBundle(['coffee' => 3, 'spoon' => 1]);
            $second = $this->order(quantity: 1);   // 新しい中身で コーヒー 3・スプーン 1
            $this->assertSame([7, 2, 1], [$this->reserved('coffee'), $this->reserved('cup'), $this->reserved('spoon')]);

            // 前の注文を取り消すと、押さえた時の中身（コーヒー 4・カップ 2）だけが戻る。後の注文の確保（コーヒー 3・スプーン 1）は食わない
            app(TransactionService::class)->transition($first, Phase::Cancel, 'test');
            $this->assertSame([3, 0, 1], [$this->reserved('coffee'), $this->reserved('cup'), $this->reserved('spoon')]);

            // 後の注文は、今の中身ではなく自分が押さえた時の中身で出庫する（この間にまた中身を変えても）
            $this->changeBundle(['cup' => 5]);
            $this->locked(fn () => $this->commitLine($second, 1));
            $this->assertSame([0, 0, 0], [$this->reserved('coffee'), $this->reserved('cup'), $this->reserved('spoon')]);
            $this->assertSame([17, 10, 9], [$this->onHand('coffee'), $this->onHand('cup'), $this->onHand('spoon')]);
        });
    }

    public function test_dropping_the_bundle_or_stopping_the_app_still_lets_open_orders_cancel_and_ship(): void
    {
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 20, 'cup' => 10]);
            $this->useBundle(['coffee' => 2, 'cup' => 1]);
            $cancelled = $this->order(quantity: 1);
            $shipped = $this->order(quantity: 1);
            $stopped = $this->order(quantity: 1);
            $this->assertSame([6, 3], [$this->reserved('coffee'), $this->reserved('cup')]);

            // セットをやめる（構成が空になる）。前は、ここからセットそのものの在庫を解こうとして例外になり、構成の品の確保が残った
            $this->changeBundle([]);
            app(TransactionService::class)->transition($cancelled, Phase::Cancel, 'test');
            $this->assertSame([4, 2], [$this->reserved('coffee'), $this->reserved('cup')]);
            $this->locked(fn () => $this->commitLine($shipped, 1));
            $this->assertSame([2, 1], [$this->reserved('coffee'), $this->reserved('cup')]);
            $this->assertSame([18, 9], [$this->onHand('coffee'), $this->onHand('cup')]);

            // App を止めても同じ（止めた App には聞かない＝構成なし、になる）
            $this->changeBundle(['coffee' => 2, 'cup' => 1]);
            app(PluginStates::class)->set('bundle-sample', false, 'test');
            app(TransactionService::class)->transition($stopped, Phase::Cancel, 'test');
            $this->assertSame([0, 0], [$this->reserved('coffee'), $this->reserved('cup')]);
            // セットそのものの在庫は、どの場面でも触っていない
            $this->assertFalse(InventoryStock::query()->where('variant_id', $this->bundleVariant())->exists(), 'セットそのものの在庫を触っている');
        });
    }

    public function test_an_order_taken_while_the_app_was_off_keeps_using_the_bundles_own_stock(): void
    {
        // 止めた間に売れたセットは、セットそのものの在庫を押さえている。App を使うに戻しても、その注文は自分が押さえた先を解く
        // （前は、戻した後の取消が構成の品の確保を解こうとして、ほかの注文の確保を食った）
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 20, 'cup' => 10]);
            $this->useBundle(['coffee' => 2, 'cup' => 1]);
            $other = $this->order(quantity: 1);
            app(PluginStates::class)->set('bundle-sample', false, 'test');
            app(StockService::class)->receive(Listing::query()->findOrFail($this->bundle), $this->location, 3);
            $whileOff = $this->order(quantity: 1);
            $own = fn (): int => (int) InventoryStock::query()->where('variant_id', $this->bundleVariant())->sum('reserved');
            $this->assertSame([1, 2, 1], [$own(), $this->reserved('coffee'), $this->reserved('cup')]);

            app(PluginStates::class)->set('bundle-sample', true, 'test');
            app(TransactionService::class)->transition($whileOff, Phase::Cancel, 'test');
            // 解いたのはセットそのもの。ほかの注文が押さえた構成の品はそのまま
            $this->assertSame([0, 2, 1], [$own(), $this->reserved('coffee'), $this->reserved('cup')]);
            app(TransactionService::class)->transition($other, Phase::Cancel, 'test');
            $this->assertSame([0, 0], [$this->reserved('coffee'), $this->reserved('cup')]);
        });
    }

    public function test_a_returned_bundle_goes_back_to_its_parts_and_not_to_the_bundle_itself(): void
    {
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 20, 'cup' => 10]);
            $this->useBundle(['coffee' => 2, 'cup' => 1]);
            $sale = $this->order(quantity: 2);
            $this->locked(fn () => $this->commitLine($sale, 2));
            $this->assertSame([16, 8], [$this->onHand('coffee'), $this->onHand('cup')]);

            // 返品で 1 セット戻す。中身を変えた後でも、売った時の中身（コーヒー 2・カップ 1）へ戻る
            $this->changeBundle(['coffee' => 9]);
            $this->locked(function () use ($sale): void {
                $line = $this->line($sale);
                $back = app(LineStock::class)->receive($line->id, $line->listing_id, (string) $line->variant_id, (string) $line->location_id, 1);
                $this->assertCount(2, $back);
            });
            $this->assertSame([18, 9], [$this->onHand('coffee'), $this->onHand('cup')]);
            $this->assertFalse(InventoryStock::query()->where('variant_id', $this->bundleVariant())->exists(), 'セットそのものの在庫に戻している');
        });
    }

    public function test_a_part_held_by_another_app_takes_the_bundle_off_sale(): void
    {
        // 抽選の受付中・予約販売のように、ほかの App が売り方を押さえている品は、セットの中身としても売らない。
        // セットの行は「セットの商品」として注文に入るので、押さえる側の関所（行の商品を見る）を素通りしてしまう
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 20, 'cup' => 10]);
            $this->useBundle(['coffee' => 2, 'cup' => 1]);
            $components = [$this->bundle => [$this->parts['coffee'] => 2, $this->parts['cup'] => 1]];
            $this->assertSame([$this->bundle => 10], app(ComponentStock::class)->totals($components));

            $held = $this->listingOf('coffee');
            app(PluginRegistry::class)->register(new PluginDefinition(
                name: 'hold-sample', title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
                saleHold: new class($held) implements SaleHoldProvider
                {
                    public function __construct(private readonly string $held) {}

                    public function holds(array $listingIds): array
                    {
                        return in_array($this->held, $listingIds, true) ? [$this->held => '抽選の受付中です'] : [];
                    }
                },
            ));
            app(PluginStates::class)->set('hold-sample', true, 'test');
            $this->assertSame([$this->bundle => 0], app(ComponentStock::class)->totals($components));

            // 押さえが外れれば（App を止めれば）また売れる
            app(PluginStates::class)->set('hold-sample', false, 'test');
            $this->assertSame([$this->bundle => 10], app(ComponentStock::class)->totals($components));
        });
    }

    public function test_what_an_order_holds_cannot_be_rewritten_afterwards(): void
    {
        $this->inside('bun', function (): void {
            $this->build(['coffee' => 20, 'cup' => 10]);
            $this->useBundle(['coffee' => 2, 'cup' => 1]);
            $line = $this->line($this->order(quantity: 1));
            $this->assertSame(2, DB::table('transaction_line_components')->where('line_id', $line->id)->count());

            // 控えは押さえた事実の記録。書き換えも削除も DB が拒む
            foreach ([
                fn () => DB::table('transaction_line_components')->where('line_id', $line->id)->update(['each' => 1]),
                fn () => DB::table('transaction_line_components')->where('line_id', $line->id)->delete(),
            ] as $tamper) {
                try {
                    DB::transaction($tamper);
                    $this->fail('控えを変えられた');
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
    }

    /**
     * 品と、セットの商品を作る
     *
     * @param  array<string, int>  $parts  品の名前 => 入庫する数
     */
    private function build(array $parts): void
    {
        $seller = Seller::query()->firstOrFail();
        $this->location = Location::query()->orderBy('created_at')->firstOrFail();

        foreach ($parts as $name => $quantity) {
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => $name, 'unit_amount' => 1000, 'status' => 'active']);
            app(StockService::class)->receive($listing, $this->location, $quantity);
            $this->parts[$name] = (string) ListingVariant::query()->where('listing_id', $listing->id)->value('id');
            $this->listings[$name] = $listing->id;
        }

        $bundle = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'お得セット', 'unit_amount' => 2500, 'status' => 'active']);
        $this->bundle = $bundle->id;
    }

    /** @var array<string, string> */
    private array $listings = [];

    /** @var ArrayObject<string, int> 見本の App が返す今の構成（種類の ID => 1 セットあたりの数） */
    private ArrayObject $composition;

    /**
     * セットの中身を今から変える（空ならセットをやめる）
     *
     * @param  array<string, int>  $parts  品の名前 => 1 セットあたりの数
     */
    private function changeBundle(array $parts): void
    {
        $map = [];

        foreach ($parts as $name => $each) {
            $map[$this->parts[$name]] = $each;
        }

        $this->composition->exchangeArray($map);
    }

    private function line(Transaction $transaction): TransactionLine
    {
        return TransactionLine::query()->where('transaction_id', $transaction->id)->where('line_type', LineType::Item)->firstOrFail();
    }

    private function commitLine(Transaction $transaction, int $quantity): void
    {
        $line = $this->line($transaction);
        app(LineStock::class)->commit($line->id, $line->listing_id, (string) $line->variant_id, (string) $line->location_id, $quantity);
    }

    private function onHand(string $name): int
    {
        return (int) InventoryStock::query()->where('variant_id', $this->parts[$name])->sum('quantity');
    }

    /**
     * 連鎖のロックを持つトランザクションの中で（LineStock を直に呼ぶ時の決まり）
     */
    private function locked(Closure $work): void
    {
        DB::transaction(function () use ($work): void {
            app(EventStore::class)->lock();
            $work();
        });
    }

    private function listingOf(string $name): string
    {
        return $this->listings[$name];
    }

    private function bundleVariant(): string
    {
        return (string) ListingVariant::query()->where('listing_id', $this->bundle)->value('id');
    }

    /**
     * セットの App を登録して有効にする
     *
     * @param  array<string, int>  $parts  品の名前 => 1 セットあたりの数
     * @param  array<string, int>|null  $raw  そのまま返す答え（形の確かめ用）
     */
    private function useBundle(array $parts, ?array $raw = null): void
    {
        $map = $raw ?? [];

        if ($raw === null) {
            foreach ($parts as $name => $each) {
                $map[$this->parts[$name]] = $each;
            }
        }

        $bundle = $this->bundle;
        // 後から中身を変えられるように、答えは共有の入れ物から読む（セットの中身を変えた・やめた場面の確かめ）
        $this->composition = new ArrayObject($map);
        $map = $this->composition;
        app(PluginRegistry::class)->register(new PluginDefinition(
            // 名前は 'bundle' にしない（本物の App「セット商品」が同じ名前で登録簿に載っているので、二重の登録になる）
            name: 'bundle-sample', title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
            stockComponents: new class($bundle, $map) implements StockComponentsProvider
            {
                /** @param ArrayObject<string, int> $map */
                public function __construct(private readonly string $bundle, private readonly ArrayObject $map) {}

                public function stockComponents(array $listingIds): array
                {
                    $now = $this->map->getArrayCopy();

                    return in_array($this->bundle, $listingIds, true) && $now !== [] ? [$this->bundle => $now] : [];
                }
            },
        ));
        app(PluginStates::class)->set('bundle-sample', true, 'test');
    }

    private function order(int $quantity): Transaction
    {
        $line = new LineDraft(
            lineType: LineType::Item, description: 'お得セット', quantity: $quantity, unitAmount: 2500, taxRateBp: 1000,
            kind: 'product', listingId: $this->bundle, locationId: $this->location->id, variantId: $this->bundleVariant(),
        );

        return app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), null, [$line], actor: 'test', taxRounding: TaxRounding::Floor);
    }

    private function reserved(string $name): int
    {
        return (int) InventoryStock::query()->where('variant_id', $this->parts[$name])->sum('reserved');
    }
}
