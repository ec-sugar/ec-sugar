<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Services\CategoryService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\VariantService;
use App\Core\Media\Models\ShopMedia;
use App\Core\Plugin\ProductPanel;
use App\Core\Plugin\ProductPanelItem;
use App\Core\Plugin\ProductPanelRating;
use App\Core\Section\Models\ShopPage;
use App\Core\Section\Services\ShopPageService;
use App\Core\Shop\Services\SeoSettingsService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Seo\SeoBuilder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 検索と共有の見え方（ADR 0061）: 題・説明・canonical・OGP・JSON-LD・noindex・robots.txt・サイトマップ。
 * 4 つのテーマで同じ（テーマは核の部品を置くだけ）
 */
final class SeoTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_the_product_page_head_in_every_theme(): void
    {
        $this->tenant('sea', 'Tea & "Co" </script>');
        $id = $this->inside('sea', function (): string {
            $categories = app(CategoryService::class);
            $tops = $categories->create(['name' => 'トップス', 'slug' => 'tops'])->id;
            $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', [
                'title' => 'ニット</script><script>alert(1)</script>', 'unit_amount' => 9900, 'status' => ListingStatus::Active,
                'sku' => 'KNIT-1', 'summary' => 'ふんわり "軽い" ニット',
            ]);
            $categories->assign($listing, [$tops], $tops);
            $this->photo($listing, '正面');
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 2);

            return $listing->id;
        });

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('sea')->forceFill(['theme' => $theme])->save();
            $response = $this->page('sea', "/products/{$id}")->assertOk();
            $html = (string) $response->getContent();

            // 題は「商品名 | 店の名前」、説明は短い説明。文はどれもエスケープされる
            $this->assertSame(1, substr_count($html, '<title>'), $theme);
            $this->assertStringContainsString('<title>ニット&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt; | Tea &amp; &quot;Co&quot; &lt;/script&gt;</title>', $html, $theme);
            $this->assertStringContainsString('<meta name="description" content="ふんわり &quot;軽い&quot; ニット">', $html, $theme);
            $this->assertSame(1, substr_count($html, 'rel="canonical"'), $theme);
            $this->assertStringContainsString("<link rel=\"canonical\" href=\"http://sea.localhost/products/{$id}\">", $html, $theme);
            $this->assertStringNotContainsString('name="robots"', $html, $theme);
            // 共有の見え方（店の名前を付けない題・1 枚目の写真・絶対の URL）
            $this->assertStringContainsString('<meta property="og:type" content="product">', $html, $theme);
            $this->assertStringContainsString('<meta property="og:title" content="ニット&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;">', $html, $theme);
            $this->assertMatchesRegularExpression('#<meta property="og:image" content="http://sea\.localhost/media/listings/[0-9a-z]{26}/large">#', $html, $theme);
            $this->assertStringContainsString('<meta property="og:image:alt" content="正面">', $html, $theme);
            $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html, $theme);
            // 店の人の文に </script> があっても、スクリプトの要素の外へ出ない
            $this->assertStringNotContainsString('<script>alert(1)', $html, $theme);

            $data = $this->jsonLd($html);
            $this->assertSame(['Product', 'BreadcrumbList'], array_column($data, '@type'), $theme);
            [$product, $crumbs] = $data;
            $this->assertSame('ニット</script><script>alert(1)</script>', $product['name']);
            $this->assertSame('KNIT-1', $product['sku']);
            $this->assertSame(['@type' => 'Offer', 'priceCurrency' => 'JPY', 'price' => 9900, 'availability' => 'https://schema.org/LimitedAvailability', 'url' => "http://sea.localhost/products/{$id}"], $product['offers']);
            $this->assertCount(1, $product['image']);
            $this->assertArrayNotHasKey('aggregateRating', $product);
            $this->assertSame(['ホーム', 'トップス', 'ニット</script><script>alert(1)</script>'], array_column($crumbs['itemListElement'], 'name'));
            $this->assertSame('http://sea.localhost/categories/tops', $crumbs['itemListElement'][1]['item']);
        }

        // 店が入れた題と説明が先
        $this->inside('sea', function () use ($id): void {
            $listing = Listing::query()->findOrFail($id);
            app(ListingService::class)->update($listing, $listing->version, ['seo_title' => '  冬のニット  ', 'seo_description' => '検索の説明']);
        });
        $html = (string) $this->page('sea', "/products/{$id}")->getContent();
        $this->assertStringContainsString('<title>冬のニット | Tea &amp; &quot;Co&quot; &lt;/script&gt;</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="検索の説明">', $html);
    }

    public function test_a_sold_out_product_with_varying_prices_has_an_aggregate_offer_and_the_rating_comes_from_the_app(): void
    {
        $this->tenant('seb');
        $this->inside('seb', function (): void {
            $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => 'シャツ', 'unit_amount' => 3000, 'status' => ListingStatus::Active]);
            $variants = app(VariantService::class);
            $listing = $variants->define($listing, $listing->version, [['name' => 'サイズ', 'values' => ['S', 'M', 'L']]]);
            $large = $listing->variants()->get()->first(static fn ($v): bool => $v->label() === 'L');
            $this->assertNotNull($large);
            $variants->update($large, Listing::query()->findOrFail($listing->id)->version, ['unit_amount' => 5000]);

            $product = app(Catalog::class)->product($listing->id);
            $this->assertNotNull($product);
            $panel = new ProductPanel('reviews', 'レビュー', [new ProductPanelItem('よい', 'よいシャツ', 5)], new ProductPanelRating(43, 7));
            $meta = app(SeoBuilder::class)->forPage('product', ['product' => $product, 'productPanels' => [$panel]], 200, '店 seb');
            $item = $meta->data[0];

            $this->assertSame('AggregateOffer', $item['offers']['@type']);
            $this->assertSame([3000, 5000, 3, 'https://schema.org/OutOfStock'], [$item['offers']['lowPrice'], $item['offers']['highPrice'], $item['offers']['offerCount'], $item['offers']['availability']]);
            // 種類ごとの品番は種類を選ぶまで決まらないので、品番は出さない
            $this->assertArrayNotHasKey('sku', $item);
            $this->assertSame(['@type' => 'AggregateRating', 'ratingValue' => '4.3', 'reviewCount' => 7, 'bestRating' => 5, 'worstRating' => 1], $item['aggregateRating']);
            // 写真が無ければ共有の既定の写真（無ければ出さない）
            $this->assertNull($meta->image);
            $this->assertSame('summary', $meta->twitterCard());
        });
    }

    public function test_category_lists_free_pages_and_the_home(): void
    {
        $this->tenant('sec', '雑貨の店');
        [$pageId] = $this->inside('sec', function (): array {
            $categories = app(CategoryService::class);
            $tops = $categories->create(['name' => 'トップス', 'slug' => 'tops']);
            $categories->update($tops, ['seo_title' => 'トップスの一覧', 'seo_description' => 'シャツとニット']);
            $categories->create(['name' => 'ボトムス', 'slug' => 'bottoms']);
            $pages = app(ShopPageService::class);
            $page = $pages->create(['title' => '私たちについて', 'slug' => 'about']);
            $pages->update($page->id, ['status' => ShopPage::PUBLISHED, 'seo_description' => '店の話']);
            $media = ShopMedia::create(['alt' => '店の入口', 'width' => 1200, 'height' => 630, 'checksum' => str_repeat('a', 64), 'bytes' => 100]);
            app(SeoSettingsService::class)->set(['home_title' => '雑貨の店 | 暮らしの道具', 'home_description' => '暮らしの道具の店です。', 'share_image_id' => $media->id], 'cli:test');

            return [$page->id];
        });

        // カテゴリ: 店が入れた題と説明。並び替え・検索を外した canonical、2 ページ目からは番号を残す
        $html = (string) $this->page('sec', '/categories/tops?sort=price_asc')->assertOk()->getContent();
        $this->assertStringContainsString('<title>トップスの一覧 | 雑貨の店</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="シャツとニット">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://sec.localhost/categories/tops">', $html);
        $this->assertSame(['BreadcrumbList'], array_column($this->jsonLd($html), '@type'));
        $this->assertStringContainsString('<link rel="canonical" href="http://sec.localhost/categories/bottoms?page=2">', (string) $this->page('sec', '/categories/bottoms?page=2&sort=new')->getContent());
        // 自動の説明と、共有の既定の写真
        $html = (string) $this->page('sec', '/categories/bottoms')->getContent();
        $this->assertStringContainsString('<meta name="description" content="雑貨の店のボトムスの一覧です。">', $html);
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="http://sec\.localhost/media/shop/[0-9a-z]{26}/large">#', $html);
        // 検索の結果は載せない
        $html = (string) $this->page('sec', '/products?q=%E3%82%B7%E3%83%A3%E3%83%84')->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow"', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertSame([], $this->jsonLd($html));

        // 自由なページ
        $html = (string) $this->page('sec', '/pages/about')->assertOk()->getContent();
        $this->assertStringContainsString('<title>私たちについて | 雑貨の店</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="店の話">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://sec.localhost/pages/about">', $html);
        $this->assertNotSame('', $pageId);

        // トップ: 店が入れた題（店の名前は付けない）と OnlineStore
        $html = (string) $this->page('sec', '/')->assertOk()->getContent();
        $this->assertStringContainsString('<title>雑貨の店 | 暮らしの道具</title>', $html);
        $this->assertStringContainsString('<meta property="og:image:alt" content="店の入口">', $html);
        $store = $this->jsonLd($html)[0];
        $this->assertSame(['OnlineStore', '雑貨の店', 'http://sec.localhost', '暮らしの道具の店です。'], [$store['@type'], $store['name'], $store['url'], $store['description']]);
    }

    public function test_private_pages_and_errors_are_not_indexed_and_a_preparing_shop_is_not_indexed_at_all(): void
    {
        $this->tenant('sed');

        foreach (['/cart', '/checkout', '/orders', '/account/login', '/consent', '/legal/external', '/no-such-page', '/products/'.strtolower((string) Str::ulid())] as $path) {
            $response = $this->page('sed', $path);
            if ($response->isRedirection()) {
                continue; // 別の画面へ送る経路（注文の入力はカートが空ならカートへ）
            }
            $html = (string) $response->getContent();
            $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow"', $html, $path);
            $this->assertStringNotContainsString('application/ld+json', $html, $path);
            $this->assertStringNotContainsString('og:title', $html, $path);
        }

        // 特商法の表記は載せる
        $this->assertStringNotContainsString('name="robots"', (string) $this->page('sed', '/legal/commerce')->getContent());

        // 準備中のショップは、載せる画面でも載せない
        $this->inside('sed', function (): void {
            app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'cli:test');
            $this->assertFalse(app(SeoBuilder::class)->forPage('home', [], 200, '店 sed')->index);
            $this->assertFalse(app(SeoBuilder::class)->forPage('legal', [], 200, '店 sed')->index);
        });
    }

    public function test_robots_txt_and_the_sitemap_follow_the_shop(): void
    {
        $this->tenant('see');
        $this->tenant('sef');
        $this->inside('sef', fn () => $this->listing('別の店の商品'));
        [$shown, $draft] = $this->inside('see', function (): array {
            app(CategoryService::class)->create(['name' => 'トップス', 'slug' => 'tops']);
            $pages = app(ShopPageService::class);
            $pages->update($pages->create(['title' => '公開', 'slug' => 'open-page'])->id, ['status' => ShopPage::PUBLISHED]);
            $pages->create(['title' => '下書き', 'slug' => 'draft-page']);

            return [$this->listing('出す商品')->id, $this->listing('下書きの商品', ListingStatus::Draft)->id];
        });

        $robots = $this->page('see', '/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $text = (string) $robots->getContent();
        $this->assertStringContainsString("User-agent: *\nDisallow: /cart\n", $text);
        $this->assertStringContainsString("Disallow: /p/\n", $text);
        $this->assertStringContainsString('Sitemap: http://see.localhost/sitemap.xml', $text);
        $this->assertStringNotContainsString("Disallow: /\n", $text);

        $xml = (string) $this->page('see', '/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);
        $locs = array_map(static fn ($u): string => (string) $u->loc, iterator_to_array($doc->url, false));
        $this->assertContains('http://see.localhost', $locs);
        $this->assertContains('http://see.localhost/categories/tops', $locs);
        $this->assertContains('http://see.localhost/pages/open-page', $locs);
        $this->assertContains("http://see.localhost/products/{$shown}", $locs);
        $this->assertNotContains("http://see.localhost/products/{$draft}", $locs);
        $this->assertNotContains('http://see.localhost/pages/draft-page', $locs);
        $this->assertSame(1, substr_count($xml, '/products/'.substr($shown, 0, 26)));
        $this->assertStringNotContainsString('sef.localhost', $xml);
        $this->assertCount(6, $locs);

        // 準備中: robots.txt は全部を止め、サイトマップは出さない
        $this->inside('see', static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'cli:test'));
        $this->assertSame("User-agent: *\nDisallow: /\n", (string) $this->page('see', '/robots.txt')->assertOk()->getContent());
        $this->page('see', '/sitemap.xml')->assertNotFound();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(static fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $m[1]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function page(string $tenant, string $path): TestResponse
    {
        $response = $this->get("http://{$tenant}.localhost{$path}");
        tenancy()->end();

        return $response;
    }

    private function listing(string $title, ListingStatus $status = ListingStatus::Active): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 1200, 'status' => $status]);
    }

    private function photo(Listing $listing, ?string $alt): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'seo');
        file_put_contents($path, $this->jpeg(40, 50));
        $this->files[] = $path;

        return app(ListingImageService::class)->add($listing, $path, $alt)->id;
    }
}
