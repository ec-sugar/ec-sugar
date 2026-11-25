<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Models\Category;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\VariantService;
use App\Core\Media\Models\ShopMedia;
use App\Core\Sample\Models\SampleMark;
use App\Core\Sample\SampleSet;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartLine;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ショップで種類（色・サイズ）を選ぶ（ADR 0036 区切り 2）。どのテーマでも核の部品で選び、JavaScript が無くても select で選んでカートに入れられる。
 * デモの店の種類の見本は、2 回流しても増えない。
 */
final class ProductVariantsTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    private ?string $token = null;

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        FakeMedia::fake();
        Cache::store()->flush();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        Tenant::query()->find('demo')?->delete();

        foreach ($this->dirs as $dir) {
            array_map('unlink', glob("{$dir}/*.jpg") ?: []);
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function test_choosing_a_variant_and_adding_it_to_the_cart_in_every_theme(): void
    {
        $this->tenant('pva');
        $ids = $this->inside('pva', function (): array {
            $listing = $this->apron();
            $plain = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => '布巾', 'unit_amount' => 800, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($plain, Location::query()->firstOrFail(), 3);

            return ['apron' => $listing->id, 'plain' => $plain->id] + $this->variantIds($listing);
        });

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->token = null;
            $this->defaultCookies = [];
            Tenant::query()->findOrFail('pva')->forceFill(['theme' => $theme])->save();

            // 詳細: 部品の印と、JavaScript が無くても選べる select。売り切れの種類は選べない
            $html = (string) $this->send('GET', "/products/{$ids['apron']}")->assertOk()->getContent();
            $this->assertStringContainsString('data-variant-picker', $html, $theme);
            $this->assertStringContainsString('data-variants="{', $html, $theme);
            $this->assertStringContainsString('name="variant_id"', $html, $theme);
            $this->assertMatchesRegularExpression('#<option value="'.$ids['墨 / M'].'"\s+selected\s*>墨 / M — ¥4,800</option>#u', $html, $theme);
            $this->assertMatchesRegularExpression('#<option value="'.$ids['からし / L'].'"\s+disabled\s*>からし / L — ¥5,200（売り切れ）</option>#u', $html, $theme);
            // 価格・在庫・写真は核の部品が切り替える印。値段が種類で違えば「〜」
            $this->assertStringContainsString('<span data-variant-price>¥4,800〜</span>', $html, $theme);
            $this->assertStringContainsString('data-variant-availability', $html, $theme);
            $this->assertStringContainsString('¥4,800〜', (string) $this->send('GET', '/products')->getContent(), $theme);

            // 種類の無い商品は今のまま（部品を出さない）
            $plain = (string) $this->send('GET', "/products/{$ids['plain']}")->assertOk()->getContent();
            $this->assertStringNotContainsString('data-variant-picker', $plain, $theme);
            $this->assertStringNotContainsString('name="variant_id"', $plain, $theme);

            // 選んだ種類でカートに入り、行の名前に種類の名前が出る
            $this->send('POST', '/cart/items', ['listing_id' => $ids['apron'], 'variant_id' => $ids['墨 / M'], 'quantity' => '2'])
                ->assertRedirect('http://pva.localhost/cart')->assertSessionHas('cart_message', 'カートに入れました。');
            $cart = (string) $this->send('GET', '/cart')->assertOk()->getContent();
            $this->assertStringContainsString('エプロン（墨 / M）', $cart, $theme);
            $this->assertStringContainsString('¥9,600', $cart, $theme);
        }

        // テーマごとに新しいお客さんなので、カートの行はテーマの数だけ
        $this->assertSame(count(app(ThemeRegistry::class)->names()), $this->inside('pva', fn (): int => CartLine::query()->where('variant_id', $ids['墨 / M'])->count()));
    }

    public function test_another_products_variant_a_stopped_variant_and_no_choice_are_refused(): void
    {
        $this->tenant('pvb');
        $ids = $this->inside('pvb', function (): array {
            $apron = $this->apron();
            $other = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => '布巾', 'unit_amount' => 800, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($other, Location::query()->firstOrFail(), 3);
            $ids = $this->variantIds($apron);
            $stopped = ListingVariant::query()->findOrFail($ids['からし / M']);
            app(VariantService::class)->update($stopped, Listing::query()->findOrFail($apron->id)->version, ['status' => 'stopped']);

            return ['apron' => $apron->id, 'other' => $other->soleVariant()?->id] + $ids;
        });

        foreach ([
            '別の商品の種類' => ['listing_id' => $ids['apron'], 'variant_id' => $ids['other']],
            '止めた種類' => ['listing_id' => $ids['apron'], 'variant_id' => $ids['からし / M']],
        ] as $case => $input) {
            $this->send('POST', '/cart/items', $input + ['quantity' => '1'], 'pvb')
                ->assertSessionHas('cart_error', 'この商品は現在カートに入れられません。');
            $this->assertSame(0, $this->inside('pvb', fn (): int => CartLine::query()->count()), $case);
        }

        $this->send('POST', '/cart/items', ['listing_id' => $ids['apron'], 'quantity' => '1'], 'pvb')->assertSessionHas('cart_error', '種類を選んでください。');

        // 止めた種類は詳細にも出ない
        $html = (string) $this->send('GET', "/products/{$ids['apron']}", tenant: 'pvb')->getContent();
        $this->assertStringNotContainsString($ids['からし / M'], $html);
        $this->assertStringContainsString($ids['墨 / M'], $html);
    }

    public function test_demo_shop_variant_samples_with_photos_do_not_grow_when_seeded_twice(): void
    {
        $dir = sys_get_temp_dir().'/demo-samples-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->dirs[] = $dir;

        foreach (['apron-sumi', 'apron-kinari', 'apron-karashi', 'mug-white', 'mug-blue'] as $name) {
            file_put_contents("{$dir}/{$name}.jpg", $this->jpeg(400, 500));
        }

        app()->instance(DemoTenantSeeder::class, new DemoTenantSeeder($dir));

        $this->seed(DemoTenantSeeder::class);
        $this->seed(DemoTenantSeeder::class);

        $state = Tenant::query()->findOrFail('demo')->run(function (): array {
            $apron = Listing::query()->where('title', 'リネンのエプロン（サンプル）')->sole();
            $mug = Listing::query()->where('title', 'ストーンウェアのマグ（サンプル）')->sole();
            $sold = ListingVariant::query()->where('sku', 'SAMPLE-LINEN-APRON-KARASHI-L')->sole();
            $blue = ListingVariant::query()->where('sku', 'SAMPLE-STONEWARE-MUG-BLUE')->sole();

            return [
                'listings' => Listing::query()->count(),
                'apron' => $apron->variants()->count(),
                'mug' => $mug->variants()->count(),
                'sold' => (int) $sold->stocks()->sum('quantity'),
                'blue' => [$blue->label(), $blue->price($mug)],
                'white' => ListingVariant::query()->where('sku', 'SAMPLE-STONEWARE-MUG-WHITE')->sole()->price($mug),
                'photos' => ListingImage::query()->count(),
                // 種類の写真はその色の写真
                'photo' => ListingImage::query()->findOrFail($blue->image_id)->alt,
                'first' => ListingImage::query()->where('listing_id', $apron->id)->where('position', 0)->sole()->alt,
                // トートの写真は置き場に無いので、商品の写真も説明の写真のブロックも無しで作る。テーマの中身の写真は置き場にある 2 枚（エプロンとマグ）だけ
                'shopPhotos' => ShopMedia::query()->count(),
                'toteBlocks' => array_column(Listing::query()->where('title', '帆布のトートバッグ（サンプル）')->sole()->description_blocks ?? [], 'type'),
            ];
        });

        $this->assertSame(3, $state['listings']);
        $this->assertSame(2, $state['shopPhotos']);
        $this->assertSame('fashion', Tenant::query()->findOrFail('demo')->getAttribute('theme'));
        $this->assertNotContains('image', $state['toteBlocks']);
        $this->assertContains('table', $state['toteBlocks']);
        $this->assertSame(6, $state['apron']);
        $this->assertSame(2, $state['mug']);
        $this->assertSame(0, $state['sold']);
        $this->assertSame(['青', 2600], $state['blue']);
        $this->assertSame(2200, $state['white']);
        $this->assertSame(5, $state['photos']);
        $this->assertSame('青いストーンウェアのマグ', $state['photo']);
        $this->assertSame('墨色のリネンのエプロン', $state['first']);
    }

    public function test_demo_shop_full_sample_uses_every_feature_and_does_not_grow_when_seeded_twice(): void
    {
        $dir = sys_get_temp_dir().'/demo-samples-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->dirs[] = $dir;

        foreach (['apron-sumi', 'apron-kinari', 'apron-karashi', 'mug-white', 'mug-blue', 'tote-kinari', 'tote-sumi', 'tote-camel', 'tote-shoulder', 'tote-detail', 'tote-canvas'] as $name) {
            file_put_contents("{$dir}/{$name}.jpg", $this->jpeg(400, 500));
        }

        app()->instance(DemoTenantSeeder::class, new DemoTenantSeeder($dir));
        $demo = static fn (): Tenant => Tenant::query()->findOrFail('demo');

        $this->seed(DemoTenantSeeder::class);
        $first = $demo()->run(fn (): array => $this->demoCounts());
        $this->seed(DemoTenantSeeder::class);
        $second = $demo()->run(fn (): array => $this->demoCounts());

        $this->assertSame($first, $second, '2 回流しても増えない');
        $this->assertSame(['listings' => 3, 'variants' => 20, 'addons' => 3, 'images' => 10, 'shopPhotos' => 6, 'categories' => 2, 'linked' => 3, 'events' => $first['events']], $first);

        $tote = $demo()->run(function (): array {
            $tote = Listing::query()->where('title', '帆布のトートバッグ（サンプル）')->sole();
            $variant = static fn (string $sku): ListingVariant => ListingVariant::query()->where('sku', $sku)->sole();
            $blocks = $tote->description_blocks ?? [];
            $image = collect($blocks)->firstWhere('type', 'image');

            return [
                'variants' => $tote->variants()->count(),
                'skus' => $tote->variants()->whereNull('sku')->count(),
                'axes' => $tote->options()->pluck('name')->all(),
                'priceM' => $variant('SAMPLE-TOTE-KINARI-M-SHORT')->price($tote),
                'priceL' => $variant('SAMPLE-TOTE-KINARI-L-SHORT')->price($tote),
                'soldOut' => (int) $variant('SAMPLE-TOTE-SUMI-L-SHORT')->stocks()->sum('quantity'),
                'stopped' => ListingVariant::query()->where('listing_id', $tote->id)->where('status', VariantStatus::Stopped)->pluck('sku')->all(),
                'photo' => ListingImage::query()->findOrFail($variant('SAMPLE-TOTE-SUMI-M-LONG')->image_id)->alt,
                'images' => $tote->images()->count(),
                'addons' => ListingAddon::query()->where('listing_id', $tote->id)->orderBy('position')->get()->map(static fn (ListingAddon $a): array => [$a->kind->value, $a->required])->all(),
                'types' => array_values(array_unique(array_column($blocks, 'type'))),
                'image' => is_array($image) && ShopMedia::query()->whereKey($image['media_id'])->exists(),
                'specs' => count($tote->specs ?? []),
                'summary' => $tote->summary !== null,
                'notice' => str_contains((string) $tote->description, SampleSet::NOTE),
                'category' => $tote->categories()->pluck('slug')->all(),
                'kitchen' => Category::query()->where('slug', 'kitchen')->sole()->listings()->count(),
            ];
        });

        $this->assertSame(12, $tote['variants']);
        $this->assertSame(0, $tote['skus'], '品番は全部の種類に');
        $this->assertSame(['色', 'サイズ', '持ち手'], $tote['axes']);
        $this->assertSame([5800, 6800], [$tote['priceM'], $tote['priceL']]);
        $this->assertSame(0, $tote['soldOut']);
        $this->assertSame(['SAMPLE-TOTE-CAMEL-L-LONG'], $tote['stopped']);
        $this->assertSame('墨色の帆布のトートバッグ', $tote['photo']);
        $this->assertSame(5, $tote['images']);
        $this->assertSame([['choice', true], ['text', false], ['toggle', false]], $tote['addons']);
        $this->assertEqualsCanonicalizing(['paragraph', 'heading', 'list', 'image', 'table', 'note'], $tote['types']);
        $this->assertTrue($tote['image'], '説明の写真はショップの写真を指す');
        $this->assertSame(6, $tote['specs']);
        $this->assertTrue($tote['summary']);
        $this->assertTrue($tote['notice']);
        $this->assertSame(['bags'], $tote['category']);
        $this->assertSame(2, $tote['kitchen']);

        // 中身は入れたときのサンプル「布ものと道具のお店」と同じ（印つき・テーマの中身も公開）
        $this->assertSame(3 + 6 + 2 + 1 + 1, $demo()->run(static fn (): int => SampleMark::query()->active()->count()), '商品 3・写真 6・カテゴリ 2・テーマの中身・表記');
        $this->assertTrue($demo()->run(static fn (): bool => ThemeVersion::query()->where('theme', 'fashion')->whereNotNull('published_at')->exists()));

        // 前の形（印の無い見本の商品）の demo の店には入れない（品番の重なりと二重を避ける）
        $demo()->run(fn () => SampleMark::query()->delete());
        $this->seed(DemoTenantSeeder::class);
        $this->assertSame(3, $demo()->run(static fn (): int => Listing::query()->count()));
    }

    /**
     * デモの店の数（2 回流して比べる）
     *
     * @return array<string, int>
     */
    private function demoCounts(): array
    {
        return [
            'listings' => Listing::query()->count(),
            'variants' => ListingVariant::query()->count(),
            'addons' => ListingAddon::query()->count(),
            'images' => ListingImage::query()->count(),
            'shopPhotos' => ShopMedia::query()->count(),
            'categories' => Category::query()->count(),
            'linked' => DB::table('listing_categories')->count(),
            'events' => DB::table('domain_events')->count(),
        ];
    }

    /**
     * 色（墨・からし）× サイズ（M・L）のエプロン。4,800 円、からしは 5,200 円。からしの L は在庫 0
     */
    private function apron(): Listing
    {
        $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => 'エプロン', 'unit_amount' => 4800, 'status' => ListingStatus::Active]);
        $service = app(VariantService::class);
        $listing = $service->define($listing, $listing->version, [['name' => '色', 'values' => ['墨', 'からし']], ['name' => 'サイズ', 'values' => ['M', 'L']]]);
        $location = Location::query()->firstOrFail();

        foreach ($listing->variants()->get() as $variant) {
            if (str_starts_with($variant->label(), 'からし')) {
                $service->update($variant, Listing::query()->findOrFail($listing->id)->version, ['unit_amount' => 5200]);
            }

            if ($variant->label() !== 'からし / L') {
                app(StockService::class)->receive($variant, $location, 5);
            }
        }

        return $listing;
    }

    /**
     * @return array<string, string> 種類の名前 => ID
     */
    private function variantIds(Listing $listing): array
    {
        return $listing->variants()->get()->mapWithKeys(static fn (ListingVariant $v): array => [$v->label() => $v->id])->all();
    }

    /**
     * @param  array<string, string>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = [], string $tenant = 'pva'): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $url = "http://{$tenant}.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        return $response;
    }
}
