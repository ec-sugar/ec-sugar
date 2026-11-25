<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Shop\Services\ShopSettingsService;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartException;
use App\Storefront\Cart\CartLine;
use App\Storefront\Cart\CartService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 店頭のカートの核（ADR 0021）。公開中の商品だけ、数と種類と在庫の上限、開くたびに計算し直す。
 */
final class CartServiceTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<Tenant> */
    private array $tenants = [];

    private CartService $carts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carts = app(CartService::class);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->tenants as $tenant) {
            $tenant->delete();
        }

        parent::tearDown();
    }

    public function test_adding_changing_and_removing_with_limits(): void
    {
        $this->inside('caa', function (): void {
            [$cart, $token] = $this->carts->create();
            $shirt = $this->listing('白いシャツ', 7700, 5);
            $cap = $this->listing('キャップ', 3300, 30);

            $this->carts->add($cart, $this->variantOf($shirt), 2);
            $this->carts->add($cart, $this->variantOf($shirt), 1);
            $this->carts->add($cart, $this->variantOf($cap), 1);
            $view = $this->carts->view($cart);
            $this->assertSame([3, 1], array_column($view->lines, 'quantity'));
            $this->assertSame(7700 * 3 + 3300, $view->subtotal);
            $this->assertSame(4, $view->count);
            $this->assertSame('¥26,400', $view->subtotalLabel());

            // 在庫（5）を超える数、10 を超える数、0 以下は断る。断ったときは前のまま
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($shirt), 3), '5 点まで');
            $this->assertRefused(fn () => $this->carts->setQuantity($cart, $this->lineOf($cart, $cap), 11), '1〜10');
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($cap), 0), '1〜10');
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($cap), -1), '1〜10');
            $this->carts->setQuantity($cart, $this->lineOf($cart, $cap), 10);
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($cap), 1), '10 点まで');
            $this->assertSame([3, 10], array_column($this->carts->view($cart)->lines, 'quantity'));

            // 0 にすると外れる。外すのは何度でもよい
            $this->carts->setQuantity($cart, $this->lineOf($cart, $shirt), 0);
            $this->carts->remove($cart, $this->lineOf($cart, $shirt));
            $this->assertSame(['キャップ'], array_column($this->carts->view($cart)->lines, 'title'));

            // 台帳（記録の鎖）には何も書かない（ADR 0021）
            $this->assertSame(0, DomainEvent::query()->where('type', 'like', 'cart%')->count());
            $this->assertNotNull($this->carts->find($token));
        });
    }

    public function test_only_published_fixed_price_products_in_stock_can_be_added(): void
    {
        $this->inside('cab', function (): void {
            [$cart] = $this->carts->create();
            $draft = $this->listing('下書き', 1000, 5, ListingStatus::Draft);
            $archived = $this->listing('終了', 1000, 5, ListingStatus::Archived);
            $soldOut = $this->listing('売り切れ', 1000, 0);

            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($draft), 1), '現在カートに入れられません');
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($archived), 1), '現在カートに入れられません');
            $this->assertRefused(fn () => $this->carts->add($cart, strtolower((string) Str::ulid()), 1), '現在カートに入れられません');
            $this->assertRefused(fn () => $this->carts->add($cart, '1 or 1=1', 1), '現在カートに入れられません');
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($soldOut), 1), '売り切れ');

            // 20 種類まで
            for ($i = 0; $i < CartService::MAX_LINES; $i++) {
                $this->carts->add($cart, $this->variantOf($this->listing("商品 {$i}", 100, 3)), 1);
            }
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($this->listing('21 種類目', 100, 3)), 1), '20 種類まで');
            $this->assertSame(CartService::MAX_LINES, $cart->lines()->count());

            // DB でも、数は 1〜10
            $this->assertRejectedByDatabase(fn () => DB::table('cart_lines')->where('cart_id', $cart->id)->limit(1)->update(['quantity' => 11]));
        });
    }

    public function test_the_cart_is_recalculated_and_tells_what_changed(): void
    {
        $this->inside('cac', function (): void {
            [$cart] = $this->carts->create();
            $knit = $this->listing('ニット', 9900, 5);
            $coat = $this->listing('コート', 30000, 3);
            $cap = $this->listing('キャップ', 3300, 2);
            $bag = $this->listing('バッグ', 15000, 1);
            foreach ([[$knit, 1], [$coat, 2], [$cap, 1], [$bag, 1]] as [$listing, $qty]) {
                $this->carts->add($cart, $this->variantOf($listing), $qty);
            }

            // 入れた後に: 値上げ、在庫が減る、販売の終了、売り切れ
            $knit->update(['unit_amount' => 11000]);
            app(StockService::class)->adjust($coat, Location::query()->firstOrFail(), -2);
            $cap->update(['status' => ListingStatus::Archived]);
            app(StockService::class)->adjust($bag, Location::query()->firstOrFail(), -1);

            $lines = $this->carts->view($cart)->lines;
            $this->assertStringContainsString('¥9,900 → ¥11,000', (string) $lines[0]['notice']);
            $this->assertTrue($lines[0]['purchasable']);
            $this->assertStringContainsString('残り 1 点', (string) $lines[1]['notice']);
            $this->assertFalse($lines[1]['purchasable']);
            $this->assertSame('販売を終了した商品', $lines[2]['title']);
            $this->assertFalse($lines[2]['purchasable']);
            $this->assertStringContainsString('売り切れ', (string) $lines[3]['notice']);
            // 合計は、今買える行だけ（新しい価格で）
            $this->assertSame(11000, $this->carts->view($cart)->subtotal);
            $this->assertTrue($this->carts->view($cart)->hasNotices());
        });
    }

    /**
     * 入れた後に在庫が減ったり 0 円になったりして上限を超えた行は、減らす操作だけ受け付ける。
     * 減らしてもまだ超えていれば知らせは残り、増やす操作は今まで通り上限で断る
     */
    public function test_decreasing_is_accepted_even_when_the_line_is_over_the_limit(): void
    {
        $this->inside('cag', function (): void {
            [$cart] = $this->carts->create();
            $location = Location::query()->firstOrFail();
            $shirt = $this->listing('シャツ', 2000, 4);
            $cap = $this->listing('キャップ', 1000, 2);
            $sample = $this->listing('試供品', 500, 5);
            $this->carts->add($cart, $this->variantOf($shirt), 4);
            $this->carts->add($cart, $this->variantOf($cap), 2);
            $this->carts->add($cart, $this->variantOf($sample), 3);

            // 在庫が 4 → 2、2 → 0、試供品は 0 円に（店が 0 円の品を売ると決めた）
            app(StockService::class)->adjust($shirt, $location, -2);
            app(StockService::class)->adjust($cap, $location, -2);
            app(ShopSettingsService::class)->setSellFreeItems(true, 'test');
            $sample->update(['unit_amount' => 0]);

            // 在庫が減った行: 4 → 3 は受け付ける。まだ在庫より多いので、知らせは残って注文できない
            $this->carts->setQuantity($cart, $this->lineOf($cart, $shirt), 3);
            $line = $this->line($cart, $shirt->id);
            $this->assertSame(3, $line['quantity']);
            $this->assertStringContainsString('残り 2 点', (string) $line['notice']);
            $this->assertFalse($line['purchasable']);
            // 同じ数のまま送り直しても断らない（二重送信）。増やすのは在庫で断る
            $this->carts->setQuantity($cart, $this->lineOf($cart, $shirt), 3);
            $this->assertRefused(fn () => $this->carts->setQuantity($cart, $this->lineOf($cart, $shirt), 4), '2 点まで');
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($shirt), 1), '2 点まで');
            // 在庫まで減らすと、知らせが消えて注文できる
            $this->carts->setQuantity($cart, $this->lineOf($cart, $shirt), 2);
            $line = $this->line($cart, $shirt->id);
            $this->assertNull($line['notice']);
            $this->assertTrue($line['purchasable']);
            $this->assertRefused(fn () => $this->carts->setQuantity($cart, $this->lineOf($cart, $shirt), 3), '2 点まで');

            // 売り切れた行: 減らすのは受け付ける（売り切れの知らせは残る）。増やすのは断る
            $this->carts->setQuantity($cart, $this->lineOf($cart, $cap), 1);
            $line = $this->line($cart, $cap->id);
            $this->assertSame(1, $line['quantity']);
            $this->assertStringContainsString('売り切れ', (string) $line['notice']);
            $this->assertRefused(fn () => $this->carts->setQuantity($cart, $this->lineOf($cart, $cap), 2), '売り切れ');
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($cap), 1), '売り切れ');

            // 0 円になった行（1 点まで）: 3 → 2 は受け付け、知らせは残る。1 点で注文できる。増やすのは断る
            $this->carts->setQuantity($cart, $this->lineOf($cart, $sample), 2);
            $line = $this->line($cart, $sample->id);
            $this->assertSame(2, $line['quantity']);
            $this->assertStringContainsString('無料の商品は 1 回のご注文で 1 点まで', (string) $line['notice']);
            $this->assertFalse($line['purchasable']);
            $this->assertRefused(fn () => $this->carts->setQuantity($cart, $this->lineOf($cart, $sample), 3), '1 点まで');
            $this->carts->setQuantity($cart, $this->lineOf($cart, $sample), 1);
            $this->assertTrue($this->line($cart, $sample->id)['purchasable']);
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($sample), 1), '1 点まで');

            // 入れていない商品は「減らす」にならない（在庫の上限で断る）。数を変えるのはカートの行だけ（別のカートの行や無い行は断る。ADR 0036）
            $other = $this->listing('ほかのシャツ', 2000, 1);
            $this->assertRefused(fn () => $this->carts->add($cart, $this->variantOf($other), 2), '1 点まで');
            $this->assertRefused(fn () => $this->carts->setQuantity($cart, $this->lineOf($cart, $other), 1), 'カートにその商品はありません');
        });
    }

    public function test_shipping_is_flat_and_free_over_the_threshold(): void
    {
        $this->inside('caf', function (): void {
            [$cart] = $this->carts->create();
            $settings = app(ShopSettingsService::class);
            $shirt = $this->listing('シャツ', 4000, 10);

            // まだ決めていない店は送料 0。空のカートは送料も 0
            $this->assertSame(0, $this->carts->view($cart)->shipping);
            $settings->setShipping(['fee' => 550, 'free_over' => 11000], 'test');
            $this->assertSame(0, $this->carts->view($cart)->total());

            $this->carts->add($cart, $this->variantOf($shirt), 2);
            $view = $this->carts->view($cart);
            $this->assertSame([8000, 550, 8550], [$view->subtotal, $view->shipping, $view->total()]);
            $this->assertSame('あと ¥3,000 で送料無料', $view->freeShippingNote());

            // ちょうど境目で無料
            $shirt->update(['unit_amount' => 5500]);
            $view = $this->carts->view($cart);
            $this->assertSame([11000, 0], [$view->subtotal, $view->shipping]);
            $this->assertSame('無料', $view->shippingLabel());
            $this->assertNull($view->freeShippingNote());

            // 無料にしない店
            $settings->setShipping(['fee' => 800, 'free_over' => null], 'test');
            $this->assertSame(800, $this->carts->view($cart)->shipping);
            $this->assertNull($this->carts->view($cart)->freeShippingNote());

            // 形の違う送料は断る。変えたことは記録に（同じ値なら記録しない）
            foreach ([['fee' => -1, 'free_over' => null], ['fee' => 100001, 'free_over' => null], ['fee' => 500, 'free_over' => 0], ['fee' => '5e2', 'free_over' => null], ['fee' => 500]] as $input) {
                try {
                    $settings->setShipping($input);
                    $this->fail('断られなかった: '.json_encode($input));
                } catch (ValidationException) {
                    $this->addToAssertionCount(1);
                }
            }
            $settings->setShipping(['fee' => 800, 'free_over' => null], 'test');
            // 送料の表の記録（ADR 0064）。変わった方法と無料の線だけ
            $events = DomainEvent::query()->where('type', 'shop.shipping_methods_updated')->orderBy('sequence')->get();
            $this->assertCount(2, $events);
            $this->assertSame([11000, null], [$events[0]->payload['changed'][0]['free_over'], $events[1]->payload['changed'][0]['free_over']]);
        });
    }

    public function test_tokens_are_secret_expire_and_stay_inside_their_shop(): void
    {
        $token = $this->inside('cad', function (): string {
            [$cart, $token] = $this->carts->create();
            $this->assertSame(43, strlen($token));
            // DB には合い言葉そのものは残らない
            $this->assertSame(0, DB::table('carts')->where('token_hash', $token)->count());
            $this->assertSame(hash('sha256', $token), $cart->token_hash);
            $this->assertSame($cart->id, $this->carts->find($token)?->id);

            foreach ([null, '', 'short', $token.'x', str_repeat('!', 43), substr($token, 0, 42).'.'] as $bad) {
                $this->assertNull($this->carts->find($bad));
            }

            // 期限切れは見つからず、片付けで消える
            [$old, $oldToken] = $this->carts->create();
            Cart::query()->whereKey($old->id)->update(['expires_at' => now()->subMinute()]);
            $this->assertNull($this->carts->find($oldToken));
            $this->assertSame(1, $this->carts->prune());
            $this->assertNotNull($this->carts->find($token));

            return $token;
        });

        // 別の店では、同じ合い言葉でもカートは無い
        $this->inside('cae', fn () => $this->assertNull($this->carts->find($token)));

        // 片付けのコマンドは店ごとに回る
        $this->inside('cad', fn () => Cart::query()->update(['expires_at' => now()->subDay()]));
        $this->artisan('storefront:prune-carts')->expectsOutputToContain('1 件')->assertSuccessful();
    }

    private function variantOf(Listing $listing): string
    {
        return $listing->variants()->firstOrFail()->id;
    }

    /**
     * カートの中のその商品の行（無ければ、まだ無い行の ID）
     */
    private function lineOf(Cart $cart, Listing $listing): string
    {
        return CartLine::query()->where('cart_id', $cart->id)->where('listing_id', $listing->id)->value('id') ?? strtolower((string) Str::ulid());
    }

    private function listing(string $title, int $amount, int $stock, ListingStatus $status = ListingStatus::Active): Listing
    {
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => $status]);

        if ($stock > 0) {
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), $stock);
        }

        return $listing;
    }

    /**
     * カートの表示の 1 行（商品の ID で探す）
     *
     * @return array{card: mixed, title: string, quantity: int, maxQuantity: int, lineTotal: int, purchasable: bool, notice: string|null, listingId: string}
     */
    private function line(Cart $cart, string $listingId): array
    {
        foreach ($this->carts->view($cart)->lines as $line) {
            if ($line['listingId'] === $listingId) {
                return $line;
            }
        }

        $this->fail("カートに無い: {$listingId}");
    }

    private function assertRefused(Closure $action, string $message): void
    {
        try {
            $action();
            $this->fail("断られなかった（{$message}）");
        } catch (CartException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function assertRejectedByDatabase(Closure $write): void
    {
        try {
            DB::transaction(static fn () => $write());
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());

            return;
        }

        $this->fail('DB が書き込みを拒まなかった');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(string $id, Closure $work): mixed
    {
        $tenant = Tenant::query()->find($id);

        if ($tenant === null) {
            $tenant = Tenant::create(['id' => $id, 'name' => "店 {$id}"]);
            $this->tenants[] = $tenant;
        }

        tenancy()->initialize($tenant);

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }
}
