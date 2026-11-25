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
use App\Core\Media\Services\ShopMediaService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店頭の商品の詳細。公開中の商品だけ。写真は並び順、パンくずは主カテゴリまで、在庫は具合だけ。
 */
final class ProductDetailTest extends TestCase
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

    public function test_the_detail_of_a_published_product_in_every_theme(): void
    {
        $this->tenant('pda');
        [$id, $first, $second] = $this->inside('pda', function (): array {
            $categories = app(CategoryService::class);
            $tops = $categories->create(['name' => 'トップス', 'slug' => 'tops'])->id;
            $knit = $categories->create(['name' => 'ニット', 'slug' => 'knit', 'parent_id' => $tops])->id;
            $listing = $this->listing('<b>ベージュ</b>のニット', "1 行目の説明\n<script>alert(1)</script>2 行目");
            $categories->assign($listing, [$tops, $knit], $knit);
            $first = $this->photo($listing, '正面');
            $second = $this->photo($listing, null);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 2);

            return [$listing->id, $first, $second];
        });

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('pda')->forceFill(['theme' => $theme])->save();
            $html = (string) $this->page('pda', "/products/{$id}")->assertOk()->getContent();

            // 題・説明・正規の URL（題の形は「商品名 | 店の名前」。ADR 0061）
            $this->assertStringContainsString('<title>&lt;b&gt;ベージュ&lt;/b&gt;のニット | 店 pda</title>', $html, $theme);
            $this->assertStringContainsString("<link rel=\"canonical\" href=\"http://pda.localhost/products/{$id}\">", $html, $theme);
            $this->assertStringContainsString('content="1 行目の説明 &lt;script&gt;alert(1)&lt;/script&gt;2 行目"', $html, $theme);
            // 写真は並び順。代わりの文字が無ければ商品名
            $this->assertLessThan(strpos($html, $second), strpos($html, $first), $theme);
            $this->assertStringContainsString('alt="正面"', $html, $theme);
            $this->assertStringContainsString('alt="&lt;b&gt;ベージュ&lt;/b&gt;のニット"', $html, $theme);
            // パンくずは主カテゴリまで。在庫は具合だけ
            $this->assertStringContainsString('href="/categories/tops"', $html, $theme);
            $this->assertStringContainsString('href="/categories/knit"', $html, $theme);
            $this->assertStringContainsString('残り 2 点', $html, $theme);
            // 説明は改行を保ち、HTML は文字のまま
            $this->assertStringNotContainsString('<script>alert(1)', $html, $theme);
            $this->assertStringContainsString("1 行目の説明\n&lt;script&gt;alert(1)&lt;/script&gt;2 行目", $html, $theme);
            $this->assertStringNotContainsString('<b>ベージュ', $html, $theme);

            // 一覧のカードから詳細へ
            $this->assertStringContainsString("href=\"/products/{$id}\"", (string) $this->page('pda', '/products')->getContent(), $theme);
        }
    }

    /**
     * 説明のブロック・仕様の表・短い説明（ADR 0032）。どのテーマでも核の部品で描き、店の人の文は HTML でなく文字のまま出る
     */
    public function test_the_description_blocks_specs_and_summary_in_every_theme(): void
    {
        $this->tenant('pdd');
        [$id, $photo] = $this->inside('pdd', function (): array {
            $path = (string) tempnam(sys_get_temp_dir(), 'pd');
            file_put_contents($path, $this->jpeg(60, 40));
            $this->files[] = $path;
            $photo = app(ShopMediaService::class)->add($path, '着た<様子>', 'cli:test')->id;
            $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', [
                'title' => 'ニット', 'unit_amount' => 9900, 'status' => ListingStatus::Active,
                'summary' => '<i>ふんわり</i>軽い',
                'description_blocks' => [
                    ['type' => 'heading', 'text' => '<script>alert(1)</script>素材'],
                    ['type' => 'paragraph', 'text' => "<b>綿</b> 100%\n2 行目"],
                    ['type' => 'list', 'style' => 'number', 'items' => ['軽い', '<a href="javascript:x">涼しい</a>']],
                    ['type' => 'image', 'media_id' => $photo, 'caption' => '<em>春</em>の装い'],
                    ['type' => 'table', 'header' => true, 'caption' => '寸法', 'rows' => [['サイズ', '着丈'], ['M', '<td>70']]],
                    ['type' => 'heading', 'level' => 3, 'text' => 'お手入れ'],
                    ['type' => 'note', 'tone' => 'warning', 'text' => '手洗い" onmouseover="alert(1)'],
                ],
                'specs' => [['label' => '原産国', 'value' => "<u>日本</u>\n（縫製）"]],
            ]);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);
            // 写真の置き場から消えた写真を指すブロック（DB を直に書いた場合）は描かない
            $blocks = json_decode((string) DB::table('listings')->where('id', $listing->id)->value('description_blocks'), true);
            $blocks[] = ['type' => 'image', 'media_id' => strtolower((string) Str::ulid()), 'caption' => '無い写真'];
            $blocks[] = ['type' => 'html', 'text' => '<marquee>知らない種類</marquee>'];
            DB::table('listings')->where('id', $listing->id)->update(['description_blocks' => json_encode($blocks)]);

            return [$listing->id, $photo];
        });

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('pdd')->forceFill(['theme' => $theme])->save();
            $html = (string) $this->page('pdd', "/products/{$id}")->assertOk()->getContent();

            // 店の人の文は、どこも文字のまま（タグにならない）
            foreach (['<script>alert(1)', '<b>綿', '<a href="javascript', '<em>春', '<td>70', '" onmouseover', '<u>日本', '<i>ふんわり', '<marquee'] as $raw) {
                $this->assertStringNotContainsString($raw, $html, "{$theme}: {$raw}");
            }
            foreach (['&lt;script&gt;alert(1)&lt;/script&gt;素材', "&lt;b&gt;綿&lt;/b&gt; 100%\n2 行目", '&lt;a href=&quot;javascript:x&quot;&gt;涼しい&lt;/a&gt;', '&lt;em&gt;春&lt;/em&gt;の装い', '&lt;td&gt;70', '手洗い&quot; onmouseover=&quot;alert(1)', "&lt;u&gt;日本&lt;/u&gt;\n（縫製）", '&lt;i&gt;ふんわり&lt;/i&gt;軽い'] as $escaped) {
                $this->assertStringContainsString($escaped, $html, "{$theme}: {$escaped}");
            }

            // ブロックは決まった要素で、並びのとおりに
            $this->assertMatchesRegularExpression('/<h3[^>]*data-block="heading"[^>]*>&lt;script&gt;/', $html, $theme);
            $this->assertMatchesRegularExpression('/<h4[^>]*data-block="heading"[^>]*>お手入れ<\/h4>/', $html, $theme);
            $this->assertMatchesRegularExpression('/<ol[^>]*data-block="list"[^>]*>\s*<li>軽い<\/li>/', $html, $theme);
            $this->assertStringContainsString("src=\"/media/shop/{$photo}/large\"", $html, $theme);
            $this->assertStringContainsString('alt="着た&lt;様子&gt;"', $html, $theme);
            $this->assertMatchesRegularExpression('/<th scope="col">サイズ<\/th>/', $html, $theme);
            $this->assertMatchesRegularExpression('/<caption>寸法<\/caption>/', $html, $theme);
            $this->assertMatchesRegularExpression('/data-block="note"[^>]*data-tone="warning"/', $html, $theme);
            $this->assertMatchesRegularExpression('/<dt>原産国<\/dt>\s*<dd>/', $html, $theme);
            $this->assertLessThan(strpos($html, 'お手入れ'), strpos($html, '&lt;b&gt;綿'), $theme);
            // 置き場に無い写真と知らない種類は描かない
            $this->assertStringNotContainsString('無い写真', $html, $theme);
            $this->assertStringNotContainsString('知らない種類', $html, $theme);
            // 短い説明は検索エンジン向けの説明にも
            $this->assertStringContainsString('content="&lt;i&gt;ふんわり&lt;/i&gt;軽い"', $html, $theme);

            // 一覧のカードにも短い説明
            $list = (string) $this->page('pdd', '/products')->getContent();
            $this->assertStringContainsString('&lt;i&gt;ふんわり&lt;/i&gt;軽い', $list, $theme);
        }
    }

    /**
     * 一覧・トップ・カートのカードは、説明のブロックと仕様の表を読まない（カードに要る列だけ。見直しの指摘 6）
     */
    public function test_cards_do_not_load_the_description_columns(): void
    {
        $this->tenant('pde');
        $this->inside('pde', function (): void {
            $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => 'ニット', 'unit_amount' => 9900, 'status' => ListingStatus::Active,
                'summary' => '短い説明', 'description_blocks' => [['type' => 'paragraph', 'text' => str_repeat('長い説明', 500)]], 'specs' => [['label' => '素材', 'value' => '綿']]]);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 2);
        });

        // 店の接続はリクエストのたびに作り直されるので、接続の記録でなく、読み出しのイベントで集める
        $queries = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->assertStringContainsString('短い説明', (string) $this->page('pde', '/products')->assertOk()->getContent());
        $this->page('pde', '/')->assertOk();

        $listingReads = array_filter($queries, static fn (string $q): bool => str_contains($q, 'from "listings"') && ! str_contains($q, 'count(*)'));
        $this->assertNotEmpty($listingReads);

        foreach ($listingReads as $query) {
            $this->assertStringNotContainsString('description_blocks', $query);
            $this->assertDoesNotMatchRegularExpression('/select \*|"listings"\.\*/', $query, $query);
        }
    }

    public function test_products_that_are_not_public_are_not_found(): void
    {
        $this->tenant('pdb');
        $this->tenant('pdc');
        $other = $this->inside('pdc', fn (): string => $this->listing('別の店の商品')->id);
        [$draft, $archived, $deleted] = $this->inside('pdb', function (): array {
            $deleted = $this->listing('消した商品');
            $deleted->delete();

            return [$this->listing('下書き', null, ListingStatus::Draft)->id, $this->listing('終了', null, ListingStatus::Archived)->id, $deleted->id];
        });

        foreach ([
            '下書き' => "/products/{$draft}",
            '終了' => "/products/{$archived}",
            '消した商品' => "/products/{$deleted}",
            '別の店の商品' => "/products/{$other}",
            '無い ID' => '/products/'.strtolower((string) Str::ulid()),
            '形の違う ID' => '/products/1%20or%201=1',
        ] as $name => $path) {
            $this->assertSame(404, $this->page('pdb', $path)->status(), $name);
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

    private function listing(string $title, ?string $description = null, ListingStatus $status = ListingStatus::Active): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'description' => $description, 'unit_amount' => 9900, 'status' => $status]);
    }

    private function photo(Listing $listing, ?string $alt): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pd');
        file_put_contents($path, $this->jpeg(40, 50));
        $this->files[] = $path;

        return app(ListingImageService::class)->add($listing, $path, $alt)->id;
    }
}
