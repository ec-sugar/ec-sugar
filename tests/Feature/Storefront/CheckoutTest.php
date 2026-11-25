<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Models\Party;
use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Shipping\Regions;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Postal\PostalPrefectures;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 注文の入力と最終確認。入力は検査してセッションに置き、DB には書かない。確認の画面は特商法の項目をそろえる。
 */
final class CheckoutTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private ?string $token = null;

    private string $listingId;

    /** @var array<string, mixed> */
    private array $valid = [
        'buyer' => ['email' => ' Hanako@Example.com ', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
        'same_as_buyer' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->tenant = Tenant::create(['id' => 'cko', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'cko.localhost']);
        $this->listingId = $this->inside(function (): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 9900, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 11000]);
            app(ShopSettingsService::class)->setOrderTerms(['delivery' => '3〜5 日でお届け', 'returns' => '<b>30 日</b>以内']);

            return $listing->id;
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

    public function test_the_checkout_needs_a_cart_and_the_confirmation_needs_the_input(): void
    {
        $this->send('GET', '/checkout')->assertRedirect('http://cko.localhost/cart');
        $this->send('POST', '/checkout', $this->valid)->assertRedirect('http://cko.localhost/cart');
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://cko.localhost/cart');

        $this->addToCart();
        $this->send('GET', '/checkout')->assertOk()->assertSee('¥10,450');
        // 入力していなければ、確認の画面には進めない
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://cko.localhost/checkout');
    }

    public function test_the_input_is_checked_kept_in_the_session_and_shown_for_confirmation_in_every_theme(): void
    {
        $this->addToCart();

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('POST', '/checkout', $this->valid)->assertRedirect('http://cko.localhost/checkout/confirm');

            $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
            // 形をそろえる: メールは小文字、郵便番号と電話は数字だけ（出すときは 150-0001）
            $this->assertStringContainsString('hanako@example.com', $html, $theme);
            $this->assertStringContainsString('〒150-0001 東京都渋谷区神宮前 1-2-3', $html, $theme);
            $this->assertStringContainsString('09012345678', $html, $theme);
            $this->assertStringContainsString('ご依頼主と同じ', $html, $theme);
            // 特商法の最終確認の項目
            foreach (['ニット', '¥9,900', '¥550', '¥10,450', 'お支払いの方法', 'お支払いの時期', '3〜5 日でお届け', '&lt;b&gt;30 日&lt;/b&gt;以内'] as $text) {
                $this->assertStringContainsString($text, $html, "{$theme}: {$text}");
            }

            // 入力に戻ると、入れた値が残っている
            $this->send('GET', '/checkout')->assertOk()->assertSee('value="山田 花子"', false)->assertSee('value="1500001"', false);
        }

        // DB には書かない（お客さんの行も記録も増えない）
        $this->inside(function (): void {
            $this->assertSame(0, Party::query()->count());
            $this->assertSame(0, DomainEvent::query()->where('type', 'like', 'transaction%')->count());
        });
    }

    public function test_malformed_input_is_refused_with_reasons_and_kept_values(): void
    {
        $this->addToCart();

        foreach ([
            'buyer.email' => ['email' => 'not-an-email'],
            'buyer.name' => ['name' => ''],
            'buyer.phone' => ['phone' => '1234'],
            'buyer.postal' => ['postal' => '150'],
            'buyer.prefecture' => ['prefecture' => 'ニュー東京'],
            'buyer.city' => ['city' => str_repeat('あ', 51)],
            'buyer.line1' => ['line1' => "1 行目\n2 行目"],
        ] as $field => $change) {
            $input = $this->valid;
            $input['buyer'] = [...$input['buyer'], ...$change];
            $this->send('POST', '/checkout', $input)->assertRedirect()->assertSessionHasErrors($field);
        }

        // 電話番号は 10 桁か 11 桁（6〜9 桁・12 桁は断る）
        foreach (['012345', '03-1234-567', '090-1234-56789'] as $phone) {
            $input = $this->valid;
            $input['buyer'] = [...$input['buyer'], 'phone' => $phone];
            $this->send('POST', '/checkout', $input)->assertRedirect()->assertSessionHasErrors('buyer.phone');
        }

        // お届け先を分けると、お届け先も必須
        $this->send('POST', '/checkout', [...$this->valid, 'same_as_buyer' => '0'])->assertSessionHasErrors(['recipient.name', 'recipient.postal']);
        $recipient = ['name' => '<script>alert(1)</script>', 'phone' => '03-1234-5678', 'postal' => '0600000', 'prefecture' => '北海道', 'city' => '札幌市中央区', 'line1' => '1', 'line2' => '2 階'];
        $this->send('POST', '/checkout', [...$this->valid, 'same_as_buyer' => '0', 'recipient' => $recipient])->assertRedirect('http://cko.localhost/checkout/confirm');
        $html = (string) $this->send('GET', '/checkout/confirm')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; 様', $html);
        $this->assertStringContainsString('〒060-0000 北海道札幌市中央区1 2 階', $html);

        // 誤りで戻った入力の画面に、理由と入れた値（エスケープして）が出る
        $input = $this->valid;
        $input['buyer'] = [...$input['buyer'], 'email' => 'x', 'name' => '"><b>名前</b>'];
        $this->send('POST', '/checkout', $input);
        $form = (string) $this->send('GET', '/checkout')->getContent();
        $this->assertStringContainsString('data-test="checkout-errors"', $form);
        $this->assertStringContainsString('value="&quot;&gt;&lt;b&gt;名前&lt;/b&gt;"', $form);
        $this->assertStringContainsString('aria-invalid="true"', $form);
    }

    public function test_the_prefecture_must_match_the_postal_code_and_decides_the_shipping(): void
    {
        // 送料は都道府県で決まる（ADR 0064）: 北海道 ¥1,500、ほかは ¥550
        $this->inside(function (): void {
            $table = app(ShippingMethods::class);
            $table->replace(['methods' => [['id' => $table->all()[0]['id'], 'name' => '通常便', 'free_over' => null, 'rates' => [...Regions::flat(550), '北海道' => 1500]]]], 'test');
        });
        $this->addToCart();
        $sapporo = ['name' => '鈴木 一郎', 'phone' => '0111234567', 'postal' => '060-0001', 'prefecture' => '北海道', 'city' => '札幌市中央区', 'line1' => '北一条西 2-1', 'line2' => ''];

        // 札幌の郵便番号で東京都を選ぶ（安い送料で送らせる）: 買う人・お届け先のどちらも断る
        $input = $this->valid;
        $input['buyer'] = [...$sapporo, 'email' => 'hanako@example.com', 'prefecture' => '東京都'];
        $this->send('POST', '/checkout', $input)->assertRedirect()->assertSessionHasErrors(['buyer.postal' => PostalPrefectures::MISMATCH]);
        $this->send('POST', '/checkout', [...$this->valid, 'same_as_buyer' => '0', 'recipient' => [...$sapporo, 'prefecture' => '東京都']])
            ->assertRedirect()->assertSessionHasErrors(['recipient.postal' => PostalPrefectures::MISMATCH])->assertSessionDoesntHaveErrors('buyer.postal');
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://cko.localhost/checkout');

        // 合う組み合わせは通り、送料は郵便番号の県（北海道）の額
        $this->send('POST', '/checkout', [...$this->valid, 'same_as_buyer' => '0', 'recipient' => $sapporo])->assertRedirect('http://cko.localhost/checkout/confirm');
        $this->assertStringContainsString('¥11,400', (string) $this->send('GET', '/checkout/confirm')->getContent());

        // 2 つの県にまたがる上 3 桁（498 は愛知県と三重県）は、どちらも通る。ほかの県は断る
        foreach (['愛知県' => true, '三重県' => true, '東京都' => false] as $prefecture => $passes) {
            $response = $this->send('POST', '/checkout', [...$this->valid, 'same_as_buyer' => '0', 'recipient' => [...$sapporo, 'postal' => '4980801', 'prefecture' => $prefecture]]);
            $passes ? $response->assertSessionDoesntHaveErrors() : $response->assertSessionHasErrors('recipient.postal');
        }

        // 照らせない郵便番号（まだ使われていない上 3 桁。新しく付いた番号など）は断らない。送料は選んだ県で
        $this->send('POST', '/checkout', [...$this->valid, 'same_as_buyer' => '0', 'recipient' => [...$sapporo, 'postal' => '0089999']])->assertRedirect('http://cko.localhost/checkout/confirm');
        $this->assertStringContainsString('¥11,400', (string) $this->send('GET', '/checkout/confirm')->getContent());
    }

    private function addToCart(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->listingId, 'quantity' => '1']);
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

        $url = "http://cko.localhost{$path}";
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
