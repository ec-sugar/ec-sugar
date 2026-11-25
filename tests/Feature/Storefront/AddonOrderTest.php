<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Addons\AddonSelection;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\AddonService;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\TransactionLine;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartException;
use App\Storefront\Cart\CartLine;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 商品のオプション（名入れ・ギフト包装。ADR 0036）。額はいつも定義から計算し、入れた文字は暗号化して持ち、記録と名前に出さない。
 */
final class AddonOrderTest extends TestCase
{
    use DatabaseTruncation;

    private const string NAME = '山田 花子';

    private Tenant $tenant;

    private string $mug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['id' => 'ado', 'name' => '店']);
        $this->mug = $this->inside(function (): string {
            $seller = Seller::query()->firstOrFail();
            $mug = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'マグ', 'unit_amount' => 2000, 'status' => 'active']);
            app(StockService::class)->receive($mug, Location::query()->firstOrFail(), 3);
            app(AddonService::class)->define($mug, 1, [
                ['kind' => 'choice', 'name' => 'ギフト包装', 'required' => true, 'choices' => [['label' => 'なし', 'amount' => 0], ['label' => 'あり', 'amount' => 330]]],
                ['kind' => 'text', 'name' => '名入れ', 'amount' => 500, 'max_length' => 10],
                ['kind' => 'toggle', 'name' => '保証の延長', 'amount' => 1000],
            ]);
            app(ShopSettingsService::class)->setShipping(['fee' => 0, 'free_over' => null]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);

            return $mug->id;
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        parent::tearDown();
    }

    public function test_the_amount_comes_from_the_definition_and_the_input_is_checked(): void
    {
        $this->inside(function (): void {
            $mug = Listing::query()->findOrFail($this->mug);
            [$gift, $name, $warranty] = $this->addons();

            $selection = AddonSelection::resolve($mug, [$gift => '1', $name => self::NAME, $warranty => '1']);
            $this->assertSame(330 + 500 + 1000, $selection->amount());
            $this->assertSame('ギフト包装：あり、名入れ：入力あり、保証の延長：あり', $selection->summary());
            // 同じ選び方は同じ印、文字が違えば違う印。印に平文は出ない
            $this->assertSame($selection->key(), AddonSelection::resolve($mug, [$gift => '1', $name => self::NAME, $warranty => '1'])->key());
            $this->assertNotSame($selection->key(), AddonSelection::resolve($mug, [$gift => '1', $name => '山田 太郎', $warranty => '1'])->key());
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $selection->key());

            foreach ([
                '必須の抜け' => [$name => self::NAME],
                '無い選択肢' => [$gift => '9'],
                '長すぎる文字' => [$gift => '0', $name => str_repeat('あ', 11)],
                '向きを変える印' => [$gift => '0', $name => "山田\u{202E}花子"],
                '改行' => [$gift => '0', $name => "山田\n花子"],
                '別の商品のオプション' => [$gift => '0', strtolower((string) Str::ulid()) => '1'],
            ] as $case => $input) {
                try {
                    AddonSelection::resolve($mug, $input);
                    $this->fail("断られなかった: {$case}");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
    }

    public function test_definitions_are_checked_by_the_service_and_the_database(): void
    {
        $this->inside(function (): void {
            $mug = Listing::query()->findOrFail($this->mug);

            foreach ([
                '形が違う' => [['kind' => 'free', 'name' => 'x']],
                '名前が重なる' => [['kind' => 'toggle', 'name' => '包装', 'amount' => 0], ['kind' => 'toggle', 'name' => '包装', 'amount' => 0]],
                '選択肢が無い' => [['kind' => 'choice', 'name' => '包装', 'choices' => []]],
                '負の額' => [['kind' => 'toggle', 'name' => '包装', 'amount' => -1]],
                '文字数が範囲外' => [['kind' => 'text', 'name' => '名入れ', 'max_length' => 101]],
                '11 個' => array_map(static fn (int $i): array => ['kind' => 'toggle', 'name' => "o{$i}", 'amount' => 0], range(1, 11)),
            ] as $case => $addons) {
                try {
                    app(AddonService::class)->define($mug, $mug->version, $addons);
                    $this->fail("断られなかった: {$case}");
                } catch (ValidationException) {
                    $this->assertSame(3, ListingAddon::query()->where('listing_id', $mug->id)->count(), $case);
                }
            }

            // サービスを通らない書き込みも DB が形を守る（選ぶなのに選択肢が無い）
            try {
                DB::transaction(fn () => ListingAddon::create(['listing_id' => $mug->id, 'position' => 9, 'kind' => 'choice', 'name' => '壊れた']));
                $this->fail('DB が形の違うオプションを受け付けた');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        });
    }

    public function test_the_cart_keeps_lines_per_selection_encrypts_them_and_shares_the_stock(): void
    {
        $this->inside(function (): void {
            $mug = Listing::query()->findOrFail($this->mug);
            $variant = $mug->variants()->firstOrFail()->id;
            [$gift, $name] = $this->addons();
            $carts = app(CartService::class);
            [$cart] = $carts->create();

            $carts->add($cart, $variant, 1, AddonSelection::resolve($mug, [$gift => '1', $name => self::NAME]));
            $carts->add($cart, $variant, 1, AddonSelection::resolve($mug, [$gift => '1', $name => self::NAME]));
            $carts->add($cart, $variant, 1, AddonSelection::resolve($mug, [$gift => '0']));
            $this->assertSame([2, 1], CartLine::query()->where('cart_id', $cart->id)->orderBy('created_at')->orderBy('id')->pluck('quantity')->all());

            // 在庫 3 点は行をまたいで数える
            $this->expectsCart(fn () => $carts->add($cart, $variant, 1, AddonSelection::resolve($mug, [$gift => '0', $name => '別の名前'])), '売り切れ');

            // 選んだものは暗号化して持つ（平文の名前は DB に無い）
            foreach (DB::table('cart_lines')->where('cart_id', $cart->id)->get(['addons']) as $row) {
                $this->assertTrue($row->addons === null || str_starts_with((string) $row->addons, TenantCipher::PREFIX));
                $this->assertStringNotContainsString(self::NAME, (string) $row->addons);
            }

            $view = $carts->view($cart);
            $this->assertSame((2000 + 330 + 500) * 2 + 2000, $view->subtotal);
            $this->assertSame('マグ／ギフト包装：あり、名入れ：入力あり', $view->lines[0]['title']);

            // 定義が変わったら、その行は注文できない（知らせが出る）
            app(AddonService::class)->define($mug->refresh(), $mug->version, [['kind' => 'toggle', 'name' => '保証の延長', 'amount' => 1000]]);
            $view = $carts->view($cart);
            $this->assertFalse($view->lines[0]['purchasable']);
            $this->assertSame('オプションの内容が変わりました。カートから削除して、選び直してください。', $view->lines[0]['notice']);
        });
    }

    public function test_the_order_has_addon_lines_with_the_text_encrypted_and_never_in_the_record(): void
    {
        $this->inside(function (): void {
            $mug = Listing::query()->findOrFail($this->mug);
            [$gift, $name] = $this->addons();
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, $mug->variants()->firstOrFail()->id, 2, AddonSelection::resolve($mug, [$gift => '1', $name => self::NAME]));

            $sale = $this->place($cart);
            $lines = TransactionLine::query()->where('transaction_id', $sale)->orderBy('position')->get();

            $this->assertSame(['マグ', 'ギフト包装：あり（×2）', '名入れ（×2）'], $lines->pluck('description')->all());
            $this->assertSame([4000, 660, 1000], $lines->pluck('amount')->all());
            $this->assertSame([null, 1, 1], $lines->pluck('parent_position')->all());
            $this->assertSame(self::NAME, $lines[2]->addon_text);
            $this->assertArrayNotHasKey('addon_text', $lines[2]->toArray());
            $this->assertSame(5660, (int) DB::table('transactions')->where('id', $sale)->value('total_amount'));

            // 入れた文字は暗号文だけ。記録（イベント）・名前・カートの外には平文で残らない
            $raw = (string) DB::table('transaction_lines')->where('transaction_id', $sale)->where('position', 3)->value('addon_text');
            $this->assertStringStartsWith(TenantCipher::PREFIX, $raw);
            $this->assertSame(0, DB::table('transaction_lines')->where('description', 'like', '%山田%')->count());
            $this->assertSame(0, DomainEvent::query()->whereRaw('payload::text LIKE ?', ['%山田%'])->count());
        });
    }

    public function test_an_order_stops_when_the_addons_changed_after_the_cart(): void
    {
        $this->inside(function (): void {
            $mug = Listing::query()->findOrFail($this->mug);
            [$gift] = $this->addons();
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, $mug->variants()->firstOrFail()->id, 1, AddonSelection::resolve($mug, [$gift => '1']));
            $fingerprint = CheckoutSummary::fingerprintOf($carts->view($cart));

            // 必須のオプションを足した（カートの選び方では足りない）
            app(AddonService::class)->define($mug->refresh(), $mug->version, [['kind' => 'text', 'name' => '名入れ', 'required' => true, 'amount' => 500, 'max_length' => 10]]);

            try {
                app(PlaceOrder::class)->place($cart, $this->input(), $this->token(), $fingerprint);
                $this->fail('定義が変わったのに注文できてしまった');
            } catch (OrderException) {
                $this->assertSame(0, DB::table('transactions')->count());
            }
        });
    }

    public function test_same_variant_lines_share_stock_across_locations_and_the_free_limit(): void
    {
        $this->inside(function (): void {
            $mug = Listing::query()->findOrFail($this->mug);
            $variant = $mug->variants()->firstOrFail();
            [$gift, $name, $warranty] = $this->addons();
            // 拠点 A に 3 点（setUp）、拠点 B に 1 点。名入れ違いで 2 点ずつ 2 行
            $second = Location::create(['seller_id' => $mug->seller_id, 'name' => '第二倉庫', 'kind' => 'warehouse']);
            app(StockService::class)->receive($variant, $second, 1);
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, $variant->id, 2, AddonSelection::resolve($mug, [$gift => '0', $name => 'たろう']));
            $carts->add($cart, $variant->id, 2, AddonSelection::resolve($mug, [$gift => '0', $name => 'はなこ']));

            $sale = $this->place($cart);
            $items = TransactionLine::query()->where('transaction_id', $sale)->where('line_type', 'item')->orderBy('position')->get();
            $this->assertSame(4, (int) $items->sum('quantity'));
            $this->assertSame(2, $items->pluck('location_id')->unique()->count());
            // 0 円の選ぶ（包装「なし」）は行にしない。名入れは 0 円でなくても文字を持つ行
            $this->assertSame(0, TransactionLine::query()->where('transaction_id', $sale)->where('description', 'like', 'ギフト包装%')->count());

            // 「付ける」は '1' だけ
            try {
                AddonSelection::resolve($mug, [$gift => '0', $warranty => '0']);
                $this->fail('付けるに 0 を送って付いてしまった');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        });
    }

    public function test_the_free_limit_counts_lines_with_different_addons(): void
    {
        $this->inside(function (): void {
            app(ShopSettingsService::class)->setSellFreeItems(true);
            $seller = Seller::query()->firstOrFail();
            $sample = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '試供品', 'unit_amount' => 0, 'status' => 'active']);
            app(StockService::class)->receive($sample, Location::query()->firstOrFail(), 5);
            app(AddonService::class)->define($sample, 1, [['kind' => 'text', 'name' => '名入れ', 'amount' => 0, 'max_length' => 10]]);
            $name = (string) ListingAddon::query()->where('listing_id', $sample->id)->value('id');
            $variant = $sample->variants()->firstOrFail()->id;
            $carts = app(CartService::class);
            [$cart] = $carts->create();

            $carts->add($cart, $variant, 1, AddonSelection::resolve($sample->refresh(), [$name => 'あ']));
            $this->expectsCart(fn () => $carts->add($cart, $variant, 1, AddonSelection::resolve($sample, [$name => 'い'])), '1 点まで');
        });
    }

    public function test_only_addon_lines_can_hold_entered_text(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // オプションの行でない行は、入れた文字を持てない
        new LineDraft(LineType::Adjustment, '包装', 1, 0, 1000, 'gift', addonText: self::NAME);
    }

    /**
     * @return list<string> ギフト包装・名入れ・保証の延長の id
     */
    private function addons(): array
    {
        return ListingAddon::query()->where('listing_id', $this->mug)->orderBy('position')->pluck('id')->all();
    }

    private function place(Cart $cart): string
    {
        $carts = app(CartService::class);

        return app(PlaceOrder::class)->place($cart, $this->input(), $this->token(), CheckoutSummary::fingerprintOf($carts->view($cart)))->id;
    }

    private function input(): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '注文 太郎', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
        $this->assertNotNull($input);

        return $input;
    }

    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @param  Closure(): mixed  $work
     */
    private function expectsCart(Closure $work, string $message): void
    {
        try {
            $work();
            $this->fail("断られなかった: {$message}");
        } catch (CartException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(Closure $work): mixed
    {
        tenancy()->initialize($this->tenant);

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }
}
