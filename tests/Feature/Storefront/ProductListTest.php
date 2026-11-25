<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Services\CategoryService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\SellerStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\VariantService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Catalog\Catalog;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店頭の商品の一覧。公開中の商品だけを、カテゴリ・検索語・並べ替えで絞ってページに分ける。
 */
final class ProductListTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private string $hiddenListing = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_list_shows_only_published_products_of_this_shop_by_category(): void
    {
        $this->tenant('pla');
        $this->tenant('plb');
        $this->inside('plb', fn () => $this->listing('別の店のニット', 1000));
        $this->inside('pla', function (): void {
            $categories = app(CategoryService::class);
            $tops = $categories->create(['name' => 'トップス', 'slug' => 'tops'])->id;
            $knit = $categories->create(['name' => 'ニット', 'slug' => 'knit', 'parent_id' => $tops])->id;
            $categories->create(['name' => 'ボトムス', 'slug' => 'bottoms']);

            $categories->assign($this->listing('ベージュのニット', 9900), [$knit]);
            $categories->assign($this->listing('白いシャツ', 7700), [$tops]);
            $categories->assign($this->listing('下書きのニット', 5000, ListingStatus::Draft), [$knit]);
            $categories->assign($this->listing('終了したニット', 5000, ListingStatus::Archived), [$knit]);
            $this->listing('パンツ', 12000);
            // 止まった出品者の商品は、公開のままでも出さない（注文を作るときに断られる）
            $suspended = Seller::create(['name' => '止まった販売者', 'slug' => 'stopped', 'status' => SellerStatus::Suspended]);
            $hidden = Listing::create(['seller_id' => $suspended->id, 'kind' => 'product', 'title' => '止まった販売者のニット', 'unit_amount' => 3000, 'status' => ListingStatus::Active]);
            $categories->assign($hidden, [$knit]);
            $this->hiddenListing = $hidden->id;
        });

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->theme('pla', $theme);

            $all = $this->page('pla', '/products')->assertOk();
            $this->assertSame(['パンツ', '白いシャツ', 'ベージュのニット'], $this->titles($all), $theme);

            // カテゴリは下の段も含む。パンくずと下の段への道
            $tops = $this->page('pla', '/categories/tops')->assertOk();
            $this->assertSame(['白いシャツ', 'ベージュのニット'], $this->titles($tops), $theme);
            $tops->assertSee('href="/categories/knit"', false);
            $knit = $this->page('pla', '/categories/knit')->assertOk();
            $this->assertSame(['ベージュのニット'], $this->titles($knit), $theme);
            $knit->assertSee('href="/categories/tops"', false);

            $this->page('pla', '/categories/bottoms')->assertOk()->assertSee('data-test="list-empty"', false);
            $this->page('pla', "/products/{$this->hiddenListing}")->assertNotFound();
            // 無いカテゴリ、形の違う slug は「見つからない」
            $this->page('pla', '/categories/nope')->assertNotFound()->assertSee('data-test="error-page"', false);
            $this->page('pla', '/categories/Tops')->assertNotFound();
            $this->page('pla', '/categories/..%2F..%2Fetc')->assertNotFound();
        }
    }

    public function test_search_sort_and_pages(): void
    {
        $this->tenant('plc');
        $this->inside('plc', function (): void {
            for ($i = 1; $i <= Catalog::PER_PAGE + 2; $i++) {
                $this->listing("シャツ {$i}", 1000 * $i);
            }
            $this->listing('100%綿のタオル', 500);
            $this->listing('a_b のタオル', 600);
        });
        $this->theme('plc', 'kinari');

        // ページ送り: 24 点ずつ。並べ替えと検索語はページの URL に残る
        $first = $this->page('plc', '/products?sort=price_desc')->assertOk();
        $this->assertCount(Catalog::PER_PAGE, $this->titles($first));
        $this->assertSame('シャツ 26', $this->titles($first)[0]);
        $first->assertSee('href="/products?sort=price_desc&amp;page=2"', false);
        $second = $this->page('plc', '/products?sort=price_desc&page=2')->assertOk();
        $this->assertSame(['シャツ 2', 'シャツ 1', 'a_b のタオル', '100%綿のタオル'], $this->titles($second));
        $this->assertSame('100%綿のタオル', $this->titles($this->page('plc', '/products?sort=price_asc'))[0]);

        // 知らない並べ替え・数字でないページはゆるく受ける。ページの上限を超えたら「見つからない」
        $this->assertSame($this->titles($this->page('plc', '/products')), $this->titles($this->page('plc', '/products?sort=title;drop&page=abc')));
        $this->page('plc', '/products?page=0')->assertOk();
        $this->page('plc', '/products?page='.(Catalog::MAX_PAGE + 1))->assertNotFound();
        $this->page('plc', '/products?page[]=1&sort[]=x')->assertOk();

        // 検索は商品名。LIKE の記号は文字として
        $this->assertSame(['100%綿のタオル'], $this->titles($this->page('plc', '/products?q='.rawurlencode('%'))));
        $this->assertSame(['a_b のタオル'], $this->titles($this->page('plc', '/products?q=_')));
        $this->assertSame(['100%綿のタオル', 'a_b のタオル'], $this->titles($this->page('plc', '/products?q='.rawurlencode('タオル').'&sort=price_asc')));
        $this->page('plc', '/products?q='.rawurlencode('無い言葉'))->assertOk()->assertSee('見つかりませんでした');
        // 長すぎる検索語は 100 字で切る（断らない）
        $this->page('plc', '/products?q='.str_repeat('a', 500))->assertOk();
    }

    public function test_price_sort_uses_the_cheapest_sellable_variant(): void
    {
        $this->tenant('ple');
        $this->inside('ple', function (): void {
            $categories = app(CategoryService::class);
            $kitchen = $categories->create(['name' => 'キッチン', 'slug' => 'kitchen'])->id;
            // 商品の価格より安い種類がある（並びは 2,000 円）。種類の価格が空の種類は商品の価格（5,000 円）
            $categories->assign($this->variantListing('エプロン', 5000, ['墨' => 2000, 'からし' => null]), [$kitchen]);
            // どの種類も商品の価格より高い（並びは 4,000 円）
            $categories->assign($this->variantListing('陶器のマグ', 2500, ['白' => 4000, '青' => 6000]), [$kitchen]);
            // 止めた種類の安い価格は数えない。残りの種類の価格が空なので商品の価格（3,500 円）
            $bowl = $this->variantListing('陶器のボウル', 3500, ['小' => 500, '大' => null]);
            $small = $bowl->variants()->get()->first(static fn (ListingVariant $v): bool => $v->label() === '小');
            app(VariantService::class)->update($small, Listing::query()->findOrFail($bowl->id)->version, ['status' => 'stopped']);
            $categories->assign($bowl, [$kitchen]);
            // 種類の無い商品は商品の価格（3,000 円）
            $this->listing('布巾', 3000);
            // ページ送りと合わせて確かめるための、高い商品
            for ($i = 1; $i <= Catalog::PER_PAGE - 2; $i++) {
                $this->listing("器 {$i}", 10000);
            }
        });
        $this->theme('ple', 'kinari');

        $this->assertSame(['エプロン', '布巾', '陶器のボウル', '陶器のマグ'], array_slice($this->titles($this->page('ple', '/products?sort=price_asc')), 0, 4));
        // 高い順も同じ値（いちばん安い種類）で。高い商品の後、2 ページ目に続く
        $first = $this->titles($this->page('ple', '/products?sort=price_desc'));
        $this->assertCount(Catalog::PER_PAGE, $first);
        $this->assertSame(['陶器のマグ', '陶器のボウル'], array_slice($first, -2));
        $this->assertSame(['布巾', 'エプロン'], $this->titles($this->page('ple', '/products?sort=price_desc&page=2')));

        // カテゴリと検索語の中でも同じ値で
        $this->assertSame(['エプロン', '陶器のボウル', '陶器のマグ'], $this->titles($this->page('ple', '/categories/kitchen?sort=price_asc')));
        $this->assertSame(['陶器のマグ', '陶器のボウル', 'エプロン'], $this->titles($this->page('ple', '/categories/kitchen?sort=price_desc')));
        $this->assertSame(['陶器のマグ', '陶器のボウル'], $this->titles($this->page('ple', '/products?q='.rawurlencode('陶器').'&sort=price_desc')));
        $this->assertSame(['陶器のボウル', '陶器のマグ'], $this->titles($this->page('ple', '/products?q='.rawurlencode('陶器').'&sort=price_asc')));
    }

    public function test_the_search_word_is_escaped_in_every_theme(): void
    {
        $this->tenant('pld');
        $this->inside('pld', fn () => $this->listing('<img src=x onerror=alert(1)>シャツ', 1000));
        $q = rawurlencode('"><script>alert(1)</script>');

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->theme('pld', $theme);
            $html = (string) $this->page('pld', "/products?q={$q}")->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $html, $theme);
            $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html, $theme);

            $html = (string) $this->page('pld', '/products')->getContent();
            $this->assertStringNotContainsString('<img src=x', $html, $theme);
            $this->assertSame(config('storefront.csp'), $this->page('pld', '/products')->headers->get('Content-Security-Policy'));
        }
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

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function titles(TestResponse $response): array
    {
        preg_match_all('/data-test="product-card".*?<span class="[^"]*leading-normal[^"]*">([^<]+)<\/span>/s', (string) $response->getContent(), $m);

        return array_map(static fn (string $t): string => html_entity_decode($t), $m[1]);
    }

    private function theme(string $tenant, string $theme): void
    {
        Tenant::query()->findOrFail($tenant)->forceFill(['theme' => $theme])->save();
    }

    private function listing(string $title, int $amount, ListingStatus $status = ListingStatus::Active): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => $status]);
    }

    /**
     * 種類が 1 つの軸で分かれる商品
     *
     * @param  array<string, int|null>  $prices  種類の名前 => 価格（null は商品の価格）
     */
    private function variantListing(string $title, int $amount, array $prices): Listing
    {
        $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => $title, 'unit_amount' => $amount, 'status' => ListingStatus::Active]);
        $service = app(VariantService::class);
        $listing = $service->define($listing, $listing->version, [['name' => '種類', 'values' => array_map(strval(...), array_keys($prices))]]);

        foreach ($listing->variants()->get() as $variant) {
            if ($prices[$variant->label()] !== null) {
                $service->update($variant, Listing::query()->findOrFail($listing->id)->version, ['unit_amount' => $prices[$variant->label()]]);
            }
        }

        return $listing;
    }
}
