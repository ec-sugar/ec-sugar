<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Discount\Services\CouponService;
use App\Core\Event\Models\DomainEvent;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\OrderCharges;
use App\Core\Order\Services\OrderFields;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Plugin\OrderCharge\ChargeCart;
use App\Core\Plugin\OrderCharge\ChargeRejected;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Models\OrderPoints;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Tax\TaxClass;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutCharges;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PaymentChoices;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Members\Member;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\OrderReader;
use Carbon\CarbonImmutable;
use Closure;
use EcSugar\PluginOrderPrint\OrderSheet;
use EcSugar\PluginShippingCsv\CsvFormat;
use EcSugar\PluginShippingCsv\Parcels;
use EcSugar\PluginShippingCsv\Shipper;
use EcSugar\PluginWrapping\Models\WrappingOrder;
use EcSugar\PluginWrapping\Models\WrappingType;
use EcSugar\PluginWrapping\WrappingCharges;
use EcSugar\PluginWrapping\WrappingTypes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * App「ラッピング・のし」（ADR 0078）と、核の「App が注文に料金の行を足す口」。
 * ラッピングできる商品があるカートだけ注文の入力に欄を出し、額は店の設定で決め、調整の行（標準の率）としてポイントの後・代引き手数料の前に足す。
 * 名入れは暗号化して持ち、記録・ご注文の状況・メールには入れない（納品書・配送の CSV・管理画面の個人情報の入口にだけ）。確かめの後に値段が変われば確定しない
 */
final class WrappingAppTest extends TestCase
{
    use DatabaseTruncation;

    private const string NAME = '鈴木一郎';

    private Tenant $tenant;

    private FakeStripe $stripe;

    private ?string $token = null;

    private string $knit;

    private string $tea;

    private string $book;

    private string $ribbon;

    private string $box;

    /** @var array<string, mixed> */
    private array $valid = [
        'buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
        'same_as_buyer' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Queue::fake();
        $this->stripe = FakeStripe::install();
        $this->travelTo(new CarbonImmutable('2026-11-05 10:00:00', 'Asia/Tokyo'));
        $this->tenant = Tenant::create(['id' => 'wrp', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'wrp.localhost']);
        $this->inside(function (): void {
            $seller = Seller::query()->value('id');
            $location = Location::query()->orderBy('id')->firstOrFail();
            $knit = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $tea = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => '紅茶', 'unit_amount' => 1080, 'status' => 'active', 'tax_class' => TaxClass::Reduced]);
            $book = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => '本', 'unit_amount' => 1100, 'status' => 'active']);

            foreach ([$knit, $tea, $book] as $listing) {
                app(StockService::class)->receive($listing, $location, 10);
            }

            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => null]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(PluginStates::class)->set('wrapping', true, 'test');
            app(PluginSettings::class)->update('wrapping', ['noshi' => true, 'noshi_fee' => '110', 'noshi_name' => true, 'noshi_name_max' => '6'], 'test');
            $types = app(WrappingTypes::class);
            $this->ribbon = $types->create(['name' => 'リボン', 'price' => '330', 'active' => true], 'test');
            $this->box = $types->create(['name' => 'ギフトボックス', 'price' => '550', 'active' => false], 'test');
            // ニットはラッピングとのし、紅茶はラッピングだけ、本はどちらもできない
            $types->saveListing($knit, true, true, 'test');
            $types->saveListing($tea, true, false, 'test');
            [$this->knit, $this->tea, $this->book] = [$knit->id, $tea->id, $book->id];
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->app['auth']->forgetGuards();
        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_fields_show_only_when_the_cart_has_items_that_can_be_wrapped(): void
    {
        // ラッピングできない商品だけのカートには欄を出さない
        $this->send('POST', '/cart/items', ['listing_id' => $this->book, 'quantity' => '1'])->assertRedirect();
        $this->assertStringNotContainsString('data-test="charge-fields"', (string) $this->send('GET', '/checkout')->assertOk()->getContent());

        // ラッピングできる商品が入ると、4 つのテーマとも欄を出す（使う種類だけ・値段つき・選べない品の数の一言）
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', '/checkout')->assertOk()
                ->assertSee('data-test="charge-fields"', false)
                ->assertSee('ラッピング・のし')
                ->assertSee('リボン（¥330）')
                ->assertDontSee('ギフトボックス')
                ->assertSee('のしの料金は ¥110 です。')
                ->assertSee('ラッピングできない商品（1 点）は、そのままお送りします。')
                ->assertSee('name="charges[noshi_name]"', false)
                ->assertSee('maxlength="6"', false);
        }

        // App を使うのをやめた店では出さない
        $this->inside(fn () => app(PluginStates::class)->set('wrapping', false, 'test'));
        $this->assertStringNotContainsString('data-test="charge-fields"', (string) $this->send('GET', '/checkout')->assertOk()->getContent());
    }

    public function test_a_wrapped_order_with_noshi_adds_standard_rate_lines_and_keeps_the_name_encrypted_and_private(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['wrapping' => $this->ribbon, 'noshi' => '御祝', 'noshi_style' => 'inner', 'noshi_name' => ' '.self::NAME.' ', 'amount' => '1']])
            ->assertRedirect('http://wrp.localhost/checkout/confirm');

        // 確かめの画面: 料金の行（合計の欄）と選んだ内容（本人の画面なので名入れも）。合計は 4,400 + 送料 550 + 330 + 110
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', '/checkout/confirm')->assertOk()
                ->assertSee('data-test="charges-used"', false)
                ->assertSeeInOrder(['ラッピング（リボン）', '¥330', 'のし（御祝・内のし・名入れあり）', '¥110'])
                ->assertSee('data-test="charges-summary"', false)
                ->assertSee(self::NAME)
                ->assertSee('¥5,390');
        }

        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect();

        $this->inside(function (): void {
            $sale = Transaction::query()->sole();
            $lines = $sale->lines()->orderBy('position')->get();
            $this->assertSame([null, 'shipping', 'wrapping', 'noshi'], $lines->pluck('kind')->all());
            $this->assertSame([4400, 550, 330, 110], $lines->pluck('amount')->map(static fn (mixed $a): int => (int) $a)->all());
            // 包装の料金は商品の率によらず標準の率（10%）。行の名前に名入れの文字は入らない
            $this->assertSame([1000, 1000], $lines->whereIn('kind', ['wrapping', 'noshi'])->pluck('tax_rate_bp')->map(static fn (mixed $r): int => (int) $r)->values()->all());
            $this->assertSame(5390, (int) $sale->total_amount);
            $this->assertStringNotContainsString(self::NAME, $lines->pluck('description')->implode(' '));

            // 名入れは店の鍵で暗号化して置く（DB には暗号文だけ）。種類の名前と額・表書き・掛け方は注文の時の写し
            $raw = DB::table('wrapping_orders')->where('transaction_id', $sale->id)->sole();
            $this->assertStringStartsWith('te1:', (string) $raw->noshi_name);
            $this->assertStringNotContainsString(self::NAME, json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '');
            $order = WrappingOrder::query()->findOrFail($sale->id);
            $this->assertSame([$this->ribbon, 'リボン', 330, '御祝', 'inner', self::NAME, 110],
                [$order->wrapping_type_id, $order->wrapping_name, $order->wrapping_amount, $order->noshi_title, $order->noshi_style, $order->noshi_name, $order->noshi_amount]);

            // 記録（イベント）には名入れを入れない
            $this->assertEquals(['wrapping_type_id' => $this->ribbon, 'noshi' => true, 'noshi_style' => 'inner', 'noshi_name' => true],
                DomainEvent::query()->where('type', 'wrapping.recorded')->sole()->payload);
            $this->assertStringNotContainsString(self::NAME, DomainEvent::query()->get()->map(static fn (DomainEvent $e): string => json_encode($e->payload, JSON_UNESCAPED_UNICODE) ?: '')->implode(' '));

            // ご注文の状況とメール: 料金の行（名前と額）は出し、名入れの文字は出さない（打ち間違えたメールの持ち主にも届きうる）
            $view = app(OrderReader::class)->read($sale);
            $this->assertSame([['title' => 'ラッピング（リボン）', 'amountLabel' => '¥330'], ['title' => 'のし（御祝・内のし・名入れあり）', 'amountLabel' => '¥110']], $view->charges);
            $mail = new OrderMail('order_paid', '店', $view, 'https://wrp.localhost/orders/x');
            $mail->assertSeeInText('のし（御祝・内のし・名入れあり）');
            $mail->assertDontSeeInText(self::NAME);
            $mail->assertDontSeeInHtml(self::NAME);

            // 備考: 店の人の書類（個人情報あり）には名入れの文字、ほかは「名入れあり」だけ
            $this->assertSame([['name' => 'ラッピング', 'value' => 'リボン'], ['name' => 'のし', 'value' => '御祝・内のし・名入れ '.self::NAME]], app(OrderCharges::class)->notes($sale->id, true));
            $this->assertSame('ラッピング: リボン／のし: 御祝・内のし・名入れあり', OrderCharges::line(app(OrderCharges::class)->notes($sale->id, false)));
            $this->assertSame('ラッピング: リボン／のし: 御祝・内のし・名入れ '.self::NAME, OrderCharges::line(OrderSheet::of($sale, true)->notes));
            $this->assertSame([], OrderSheet::of($sale, false)->notes);

            // 払われたら配送の CSV の備考（B2 クラウドの「記事」・ゆうプリR の「備考」）にも載る
            $this->pay($sale);
            [$parcels] = Parcels::pick('all', []);
            $this->assertCount(1, $parcels);
            $note = 'ラッピング: リボン／のし: 御祝・内のし・名入れ '.self::NAME;
            $this->assertSame($note, $parcels[0]->note);
            $this->assertSame($note, CsvFormat::YamatoB2->csv($parcels, Shipper::current(), [], CarbonImmutable::now())->rows[0][32]);
            $yupri = CsvFormat::JapanPostYupri->csv($parcels, Shipper::current(), [], CarbonImmutable::now())->rows[0];
            $this->assertSame($note, $yupri[count($yupri) - 1]);
        });
    }

    public function test_the_shop_decides_the_amount_and_names_and_choices_outside_the_rules_are_refused(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $refused = [
            'charges.noshi_name' => [['noshi' => '御祝', 'noshi_name' => '鈴木一郎太郎吉'], ['noshi' => '御祝', 'noshi_name' => '鈴木<b>'], ['noshi' => '御祝', 'noshi_name' => "鈴木\u{202E}一郎"],
                ['noshi' => '御祝', 'noshi_name' => "鈴木\n一郎"], ['noshi' => '御祝', 'noshi_name' => "鈴\u{200B}木"], ['noshi_name' => self::NAME],
                // 空白に見える文字・異体字セレクタ・結合文字だけ（前後にあるものは Laravel が先に取り除くので、間に挟む）
                ['noshi' => '御祝', 'noshi_name' => "鈴\u{3164}木"], ['noshi' => '御祝', 'noshi_name' => "鈴\u{115F}木"], ['noshi' => '御祝', 'noshi_name' => "鈴\u{1160}木"],
                ['noshi' => '御祝', 'noshi_name' => "鈴\u{FFA0}木"], ['noshi' => '御祝', 'noshi_name' => "鈴\u{2800}木"], ['noshi' => '御祝', 'noshi_name' => "鈴\u{FE0F}木"],
                ['noshi' => '御祝', 'noshi_name' => "鈴\u{E0100}木"], ['noshi' => '御祝', 'noshi_name' => "\u{0301}\u{0302}"]],
            'charges.noshi' => [['noshi' => '御霊前']],
            'charges.noshi_style' => [['noshi' => '御祝', 'noshi_style' => 'middle']],
            // 使わない種類・知らない ID
            'charges.wrapping' => [['wrapping' => $this->box], ['wrapping' => '01JBQ0000000000000000000ZZ'], ['wrapping' => '../../etc']],
        ];

        foreach ($refused as $field => $cases) {
            foreach ($cases as $charges) {
                $this->send('POST', '/checkout', [...$this->valid, 'charges' => $charges])->assertRedirect()->assertSessionHasErrors($field);
            }
        }

        // 欄の下に理由（文字のまま）
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['noshi' => '御祝', 'noshi_name' => '鈴木<b>']]);
        $this->send('GET', '/checkout')->assertOk()->assertSee('名入れに使えない文字')->assertDontSee('<b>', false);

        // App の口に直に渡った時も、前後の見えない文字だけの名前を断る（受け口の空白の取り除きに頼らない）
        $this->inside(function (): void {
            $cart = new ChargeCart([['listingId' => $this->knit, 'title' => 'ニット', 'quantity' => 1]]);
            foreach (["\u{3164}", "\u{FFA0}\u{FFA0}", "\u{2800}", "鈴木\u{FE0F}", "鈴木\u{E0100}", "\u{115F}"] as $name) {
                $this->assertArrayHasKey('noshi_name', $this->refusal(fn () => app(OrderCharges::class)->quote($cart, ['noshi' => '御祝', 'noshi_name' => $name])), bin2hex($name));
            }
        });

        // Shift_JIS で表せない字（𠮷）は断らない（送り状で「〓」になりうることを欄の一言で知らせる）
        $this->send('GET', '/checkout')->assertOk()->assertSee(WrappingCharges::GLYPH_NOTE);
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['noshi' => '御祝', 'noshi_name' => '𠮷田']])->assertRedirect('http://wrp.localhost/checkout/confirm')->assertSessionHasNoErrors();

        // 長さの上限ちょうどは通る（見える文字で 6 文字）
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['noshi' => '御祝', 'noshi_name' => '鈴木一郎太郎']])->assertRedirect('http://wrp.localhost/checkout/confirm')->assertSessionHasNoErrors();

        // 店が名入れの欄を出していない時に送られた名入れは使わない（出した欄の値だけを受ける）。App に直に渡っても断る
        $this->inside(fn () => app(PluginSettings::class)->update('wrapping', ['noshi' => true, 'noshi_fee' => '110', 'noshi_name' => false], 'test'));
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['noshi' => '御祝', 'noshi_name' => self::NAME]])->assertRedirect('http://wrp.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertSee('のし（御祝・外のし）')->assertDontSee(self::NAME)->assertDontSee('名入れあり');
        $this->inside(function (): void {
            $cart = new ChargeCart([['listingId' => $this->knit, 'title' => 'ニット', 'quantity' => 1]]);
            $this->assertSame(['noshi_name' => '名入れはお受けしていません。空にしてください。'], $this->refusal(fn () => app(OrderCharges::class)->quote($cart, ['noshi' => '御祝', 'noshi_name' => self::NAME])));
        });
    }

    public function test_a_cart_without_wrappable_items_ignores_posted_choices(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->book, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['wrapping' => $this->ribbon, 'noshi' => '御祝']])->assertRedirect('http://wrp.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertDontSee('data-test="charges-used"', false)->assertSee('¥1,650');
        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect();

        $this->inside(function (): void {
            $sale = Transaction::query()->sole();
            $this->assertSame(0, $sale->lines()->whereIn('kind', ['wrapping', 'noshi'])->count());
            $this->assertSame(0, WrappingOrder::query()->count());
            $this->assertSame(1650, (int) $sale->total_amount);
        });

        // のしを付けられない商品（紅茶）だけのカートでは、のしの欄を出さず、送られたのしは使わない（ラッピングだけ）
        $this->token = null;
        $this->send('POST', '/cart/items', ['listing_id' => $this->tea, 'quantity' => '1'])->assertRedirect();
        $this->send('GET', '/checkout')->assertOk()->assertSee('data-test="charge-field-wrapping"', false)->assertDontSee('data-test="charge-field-noshi"', false);
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['wrapping' => $this->ribbon, 'noshi' => '御祝']])->assertRedirect('http://wrp.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertSee('ラッピング（リボン）')->assertDontSee('のし（御祝');

        // App の口に直に渡っても、選べない商品のラッピング・のしは断る（守りの 2 枚目）
        $this->inside(function (): void {
            $book = new ChargeCart([['listingId' => $this->book, 'title' => '本', 'quantity' => 1]]);
            $tea = new ChargeCart([['listingId' => $this->tea, 'title' => '紅茶', 'quantity' => 1]]);
            $this->assertArrayHasKey('wrapping', $this->refusal(fn () => app(OrderCharges::class)->quote($book, ['wrapping' => $this->ribbon])));
            $this->assertArrayHasKey('noshi', $this->refusal(fn () => app(OrderCharges::class)->quote($tea, ['noshi' => '御祝'])));
            $this->assertNull(app(OrderCharges::class)->form($book));
        });
    }

    public function test_a_price_or_rule_changed_after_the_confirmation_is_not_placed(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['wrapping' => $this->ribbon, 'noshi' => '御祝']])->assertRedirect();
        $shown = $this->confirm();

        // 確かめの後に店が値段を変えた: 確定せず、確かめの画面へ（新しい値段を見てもらう）
        $this->inside(fn () => app(WrappingTypes::class)->update($this->ribbon, ['name' => 'リボン', 'price' => '440', 'active' => true], 'test'));
        $this->send('POST', '/checkout/confirm', $shown)->assertRedirect('http://wrp.localhost/checkout/confirm')->assertSessionHas('cart_error');
        $this->assertFalse($this->inside(fn (): bool => Transaction::query()->exists()));

        // のしの料金を変えた（ラッピングの額はそのまま）も同じ
        $shown = $this->confirm();
        $this->inside(fn () => app(PluginSettings::class)->update('wrapping', ['noshi' => true, 'noshi_fee' => '220', 'noshi_name' => true, 'noshi_name_max' => '6'], 'test'));
        $this->send('POST', '/checkout/confirm', $shown)->assertRedirect('http://wrp.localhost/checkout/confirm');
        $this->assertFalse($this->inside(fn (): bool => Transaction::query()->exists()));

        // 額の変わらない変更（種類の名前・表書きの選択肢の並び）も、見せた内容と違うので確定しない（合計と税は同じでも、指紋の料金の要約が変わる）
        $shown = $this->confirm();
        $this->inside(fn () => app(WrappingTypes::class)->update($this->ribbon, ['name' => 'サテンのリボン', 'price' => '440', 'active' => true], 'test'));
        $this->send('POST', '/checkout/confirm', $shown)->assertRedirect('http://wrp.localhost/checkout/confirm');
        $shown = $this->confirm();
        $this->inside(fn () => app(PluginSettings::class)->update('wrapping', ['noshi' => true, 'noshi_fee' => '220', 'noshi_name' => true, 'noshi_name_max' => '6', 'noshi_titles' => '御祝、内祝'], 'test'));
        $this->send('POST', '/checkout/confirm', $shown)->assertRedirect('http://wrp.localhost/checkout/confirm');
        $this->assertFalse($this->inside(fn (): bool => Transaction::query()->exists()));

        // 種類を使わなくした（ほかの種類は残る）: 確かめの画面から入力の画面へ戻し、欄の下に理由
        $this->inside(fn () => app(WrappingTypes::class)->update($this->box, ['name' => 'ギフトボックス', 'price' => '550', 'active' => true], 'test'));
        $shown = $this->confirm();
        $this->inside(fn () => app(WrappingTypes::class)->update($this->ribbon, ['name' => 'リボン', 'price' => '440', 'active' => false], 'test'));
        $this->send('POST', '/checkout/confirm', $shown)->assertRedirect('http://wrp.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://wrp.localhost/checkout')->assertSessionHasErrors('charges.wrapping');
        $this->assertFalse($this->inside(fn (): bool => Transaction::query()->exists()));

        // 種類を全部消して欄が無くなったが、のしの欄は残る: 選んでいたラッピングが消えたことを入力の画面で知らせる
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['wrapping' => $this->box, 'noshi' => '御祝']])->assertRedirect();
        $this->confirm();
        $this->inside(function (): void {
            app(WrappingTypes::class)->delete($this->ribbon, 'test');
            app(WrappingTypes::class)->delete($this->box, 'test');
        });
        // （セッションを確かめると次の画面で誤りが消えるので、画面で確かめる）
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://wrp.localhost/checkout');
        $this->send('GET', '/checkout')->assertOk()->assertSee('data-test="charge-fields-error"', false)->assertSee(CheckoutCharges::CHANGED);
        $this->assertFalse($this->inside(fn (): bool => Transaction::query()->exists()));

        // 選び直して、見せた内容のままなら確定する
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['noshi' => '内祝']])->assertRedirect('http://wrp.localhost/checkout/confirm');
        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect();
        $this->assertSame('のし（内祝・外のし）', $this->inside(fn (): string => (string) TransactionLine::query()->where('kind', 'noshi')->sole()->description));
    }

    public function test_another_shops_wrapping_cannot_be_chosen(): void
    {
        $other = Tenant::create(['id' => 'wrq', 'name' => '別の店']);
        $other->domains()->create(['domain' => 'wrq.localhost']);

        try {
            tenancy()->initialize($other);
            $knit = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($knit, Location::query()->orderBy('id')->firstOrFail(), 5);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(PluginStates::class)->set('wrapping', true, 'test');
            app(WrappingTypes::class)->create(['name' => '紙袋', 'price' => '0', 'active' => true], 'test');
            app(WrappingTypes::class)->saveListing($knit, true, false, 'test');
            tenancy()->end();

            // ほかの店（wrp）の種類の ID は、この店の表に無いので選べない
            $response = $this->withCookie(CartCookie::NAME, '')->post('http://wrq.localhost/cart/items', ['listing_id' => $knit->id, 'quantity' => '1']);
            tenancy()->end();
            $token = (string) $response->getCookie(CartCookie::NAME)?->getValue();
            $this->withCookie(CartCookie::NAME, $token)->post('http://wrq.localhost/checkout', [...$this->valid, 'charges' => ['wrapping' => $this->ribbon]])
                ->assertRedirect()->assertSessionHasErrors('charges.wrapping');
            tenancy()->end();
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $other->delete();
        }
    }

    public function test_points_down_to_zero_with_a_small_charge_is_refused(): void
    {
        $this->inside(function (): void {
            app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 100], 'test');
            $member = $this->member();
            app(PointService::class)->adjust($member, 100000, 'テスト', 'test');
            $small = app(WrappingTypes::class)->create(['name' => '紙袋', 'price' => '30', 'active' => true], 'test');
            $cart = $this->cart([$this->knit => 1]);

            // ポイントで 0 円にして 30 円の料金を足すと、お支払いが 30 円（決済代行で払えない）: 確かめの画面と確定の両方で断る
            $input = $this->input(['wrapping' => $small], points: PHP_INT_MAX);
            $view = app(CartService::class)->view($cart, $input->destination(), 'hanako@example.com', $input->pointsRequested());
            $this->assertSame(0, $view->total());
            $this->assertSame(['_form' => CheckoutCharges::BELOW_MINIMUM], $this->refusal(fn () => app(CheckoutCharges::class)->apply($view, $input)));

            try {
                app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'x');
                $this->fail('1〜49 円のお支払いの注文を作った');
            } catch (OrderException $e) {
                $this->assertSame(CheckoutCharges::BELOW_MINIMUM, $e->getMessage());
            }
            $this->assertFalse(Transaction::query()->exists());

            // ポイントを減らして 50 円以上が残れば注文できる（4,400 + 550 − 4,000 + 30 = 980）
            $sale = $this->place($cart, $this->input(['wrapping' => $small], points: 4000));
            $this->assertSame(980, (int) $sale->total_amount);

            // 料金まで払えるギフトカードがあれば、ポイントで 0 円にしてもお支払い 0 円で注文できる
            $card = app(GiftCardService::class)->issue(1000, 180, null, 'test')['card'];
            $free = $this->place($this->cart([$this->knit => 1]), $this->input(['wrapping' => $small], points: PHP_INT_MAX, giftCard: $card->id));
            $this->assertSame(30, (int) $free->total_amount);
            $this->assertSame(970, app(GiftCardService::class)->balance($card->id));
        });
    }

    public function test_numeric_titles_keep_their_values_and_a_free_noshi_stays_its_own_stripe_line(): void
    {
        $this->inside(fn () => app(PluginSettings::class)->update('wrapping', ['noshi' => true, 'noshi_fee' => '0', 'noshi_titles' => '100、御祝'], 'test'));
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();

        // 数字だけの表書きも、値が振り直されずにそのまま（「100」は 100 のまま）
        $this->send('GET', '/checkout')->assertOk()->assertSee('<option value="100" >100</option>', false)->assertDontSee('<option value="0"', false);
        $this->send('POST', '/checkout', [...$this->valid, 'charges' => ['wrapping' => $this->ribbon, 'noshi' => '100']])->assertRedirect('http://wrp.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertSee('のし（100・外のし）')->assertSee('無料');
        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect();

        // 決済代行の明細: 0 円ののしの行は飛ばし、ほかは行ごと（まとめの 1 行に落ちない）
        $items = $this->stripe->lastParams()['line_items'];
        $this->assertSame(['ニット', '送料', 'ラッピング（リボン）'], array_map(static fn (array $i): string => $i['price_data']['product_data']['name'], $items));
        $this->assertSame([4400, 550, 330], array_map(static fn (array $i): int => $i['price_data']['unit_amount'] * $i['quantity'], $items));
    }

    public function test_charges_come_after_coupons_and_points_before_the_cod_fee_with_reduced_rates_kept_apart(): void
    {
        $this->inside(function (): void {
            app(CouponService::class)->create(['code' => 'OFF100', 'kind' => 'fixed', 'value' => 100, 'name' => 'テスト'], 'test');
            app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 100], 'test');
            app(DirectMethodSettings::class)->update(['bank_transfer' => false, 'bank_days' => 7, 'cod' => true, 'cod_fees' => DirectMethodSettings::DEFAULT_COD_FEES, 'cod_limit' => 300000], 'test');
            $member = $this->member();
            app(PointService::class)->adjust($member, 500, 'テスト', 'test');

            // 紅茶 2 点（8%）とニット（10%）。クーポン −100・ポイント 500・ラッピング 330・のし 110・代引き
            $cart = $this->cart([$this->tea => 2, $this->knit => 1]);
            app(CartService::class)->applyCoupon($cart, 'OFF100');
            $input = $this->input(['wrapping' => $this->ribbon, 'noshi' => '御祝'], points: 500, payment: 'cod');
            $plain = app(CartService::class)->view($cart, $input->destination(), 'hanako@example.com', 500);
            $sale = $this->place($cart, $input);

            $lines = $sale->lines()->orderBy('position')->get();
            // 値引きは送料の前、ポイントは送料の後、App の料金はポイントの後、代引き手数料はいちばん後
            $kinds = $lines->map(static fn (TransactionLine $l): string => $l->line_type === LineType::Item ? 'item' : (string) $l->kind)->all();
            $this->assertSame(['item', 'item', 'discount', 'discount', 'shipping', 'points', 'points', 'wrapping', 'noshi', 'cod_fee'], $kinds);
            $this->assertSame([1000, 1000, 1000], $lines->whereIn('kind', ['wrapping', 'noshi', 'cod_fee'])->pluck('tax_rate_bp')->map(static fn (mixed $r): int => (int) $r)->values()->all());

            // 合計 = 商品 6,560 − 100 + 送料 550 − 500 + 330 + 110 + 代引き手数料（手数料の前の合計 6,950 の段 → 330）
            $before = 2160 + 4400 - 100 + 550 - 500 + 330 + 110;
            $fee = app(DirectMethodSettings::class)->codFee($before);
            $this->assertSame(330, $fee);
            $this->assertSame($before + $fee, (int) $sale->total_amount);
            $this->assertSame($before + $fee, (int) PaymentAttempt::query()->where('transaction_id', $sale->id)->sole()->amount);

            // 税率ごと: 8% の分は紅茶の値引きとポイントの後の額だけ。料金と代引き手数料は 10% に入る
            $byRate = $lines->groupBy('tax_rate_bp')->map(static fn ($l): int => (int) $l->sum('amount'))->all();
            $this->assertSame($plain->taxAmounts[800], $byRate[800]);
            $this->assertSame($plain->taxAmounts[1000] + 330 + 110 + $fee, $byRate[1000]);

            // 付くポイントの元は商品の代金だけ（ラッピング・のし・手数料は入らない）
            $this->assertSame($plain->points->earnBase, OrderPoints::query()->findOrFail($sale->id)->earn_base);
            $this->assertSame(500, OrderPoints::query()->findOrFail($sale->id)->spent);
        });
    }

    public function test_a_gift_card_covers_the_charges_and_a_cod_order_keeps_the_card(): void
    {
        $this->inside(function (): void {
            // 残高が合計より多いカード: ラッピングまで全部をカードで払い、支払いは要らない
            $card = app(GiftCardService::class)->issue(10000, 180, null, 'test')['card'];
            $sale = $this->place($this->cart([$this->knit => 1]), $this->input(['wrapping' => $this->ribbon], giftCard: $card->id));
            $this->assertSame(4400 + 550 + 330, (int) $sale->total_amount);
            $this->assertSame(['amount' => 0, 'gift_card' => 5280], DomainEvent::query()->where('type', 'payment.not_required')->where('aggregate_id', $sale->id)->sole()->payload);

            // 代引きと一部のギフトカード: カードは使い、残り（手数料を含む）を代引きで受け取る
            app(DirectMethodSettings::class)->update(['bank_transfer' => false, 'bank_days' => 7, 'cod' => true, 'cod_fees' => DirectMethodSettings::DEFAULT_COD_FEES, 'cod_limit' => 300000], 'test');
            $small = app(GiftCardService::class)->issue(1000, 180, null, 'test')['card'];
            $cod = $this->place($this->cart([$this->knit => 1]), $this->input(['wrapping' => $this->ribbon], giftCard: $small->id, payment: 'cod'));
            $total = 4400 + 550 + 330 + 330;
            $this->assertSame($total, (int) $cod->total_amount);
            $this->assertSame(0, app(GiftCardService::class)->balance($small->id));
            $this->assertSame($total - 1000, (int) PaymentAttempt::query()->where('transaction_id', $cod->id)->where('method', 'cod')->sole()->amount);
        });
    }

    public function test_a_gift_order_can_be_wrapped(): void
    {
        $this->inside(function (): void {
            app(PluginStates::class)->set('gift', true, 'test');
            $sale = $this->place($this->cart([$this->knit => 1]), $this->input(['wrapping' => $this->ribbon, 'noshi' => '御祝'], gift: true));
            $this->assertSame(['wrapping', 'noshi'], $sale->lines()->whereIn('kind', ['wrapping', 'noshi'])->orderBy('position')->pluck('kind')->all());
            // お届け先は受け取る人が入れる（ラッピングはお届け先の入力を待つ受渡のまま）
            $this->assertTrue($sale->fulfillments()->sole()->awaitingAddress());
            $this->assertSame(1, DomainEvent::query()->where('type', 'order.gift_placed')->where('aggregate_id', $sale->id)->count());
            $this->assertSame(1, WrappingOrder::query()->whereKey($sale->id)->count());
        });
    }

    public function test_the_order_rows_are_append_only(): void
    {
        $this->inside(function (): void {
            $sale = $this->place($this->cart([$this->knit => 1]), $this->input(['wrapping' => $this->ribbon]));

            foreach ([
                static fn () => DB::table('wrapping_orders')->where('transaction_id', $sale->id)->update(['wrapping_amount' => 0]),
                static fn () => DB::table('wrapping_orders')->where('transaction_id', $sale->id)->delete(),
            ] as $write) {
                try {
                    DB::transaction($write);
                    $this->fail('DB が書き込みを断らなかった');
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }

            // 表の形: 名入れは暗号文だけ
            try {
                DB::transaction(static fn () => DB::table('wrapping_orders')->insert(['transaction_id' => Transaction::query()->value('id'), 'noshi_title' => '御祝', 'noshi_style' => 'outer', 'noshi_name' => self::NAME, 'created_at' => now()]));
                $this->fail('平文の名入れを入れた');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame(1, WrappingType::query()->where('active', true)->count());
        });
    }

    /**
     * 断った理由（欄の鍵 => 理由）。断らなければ落とす
     *
     * @return array<string, string>
     */
    private function refusal(Closure $work): array
    {
        try {
            $work();
        } catch (ChargeRejected $e) {
            return $e->errors;
        }

        $this->fail('断らなかった');
    }

    /**
     * 確かめの画面と同じ決め方で指紋を作り、確定する（店の文脈の中で）
     */
    private function place(Cart $cart, CheckoutInput $input): Transaction
    {
        $view = app(CartService::class)->view($cart, $input->destination(), 'hanako@example.com', $input->pointsRequested(), $input->giftCardId);
        [$view, $quote] = app(CheckoutCharges::class)->apply($view, $input);
        $offer = app(PaymentChoices::class)->offer($view, $input);
        $choice = $view->due() === 0 ? CheckoutInput::PAY_STRIPE : $input->payment;
        $view = $view->withPaymentFee($choice === 'cod' ? $offer->fee('cod') : 0);
        $methods = app(PaymentMethodSettings::class)->forCheckout($view->due(), false, $input->datedDelivery());
        $fingerprint = CheckoutSummary::fingerprintOf($view, $methods, $input->deliveryChoice(), app(OrderFields::class)->digest([]),
            CheckoutSummary::paymentKey($choice, $offer->bankDays, $view->paymentFee), $quote->fingerprint());

        return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);
    }

    /**
     * @param  array<string, string>  $charges
     */
    private function input(array $charges, int $points = 0, ?string $giftCard = null, string $payment = 'stripe', bool $gift = false): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null,
            'gift' => $gift ? ['sender' => 'はなこ', 'message' => 'おめでとう'] : null,
            'points' => match (true) {
                $points === PHP_INT_MAX => ['mode' => 'all'],
                $points > 0 => ['mode' => 'some', 'amount' => $points],
                default => null,
            }, 'gift_card_id' => $giftCard, 'payment' => $payment, 'charges' => $charges]);
        $this->assertNotNull($input);

        return $input;
    }

    /**
     * @param  array<string, int>  $items
     */
    private function cart(array $items): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();

        foreach ($items as $listing => $quantity) {
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $listing)->value('id'), $quantity);
        }

        return $cart;
    }

    private function member(): CustomerAccount
    {
        $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'test');
        CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);
        $account = CustomerAccount::query()->findOrFail($account->id);
        Auth::guard('customer')->setUser(new Member($account, 'wrp'));

        return $account;
    }

    private function pay(Transaction $sale): void
    {
        $attempt = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole();
        $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$sale->id, 'checkout.session.completed', $attempt->provider_ref, $sale->id, (int) $sale->total_amount, 'jpy', 'pi_'.$sale->id));
    }

    /**
     * @return array{order_key: string, fingerprint: string}
     */
    private function confirm(): array
    {
        $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);

        return ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''];
    }

    /**
     * Cookie（カートの合い言葉とセッション）を持ち越して送る
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $url = "http://wrp.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        return $response;
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
