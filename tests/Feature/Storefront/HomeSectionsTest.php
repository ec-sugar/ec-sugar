<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Models\Category;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * トップの区画（→ ADR 0028 区切り 2c でテーマの区画へ）。カテゴリと商品は公開中のものだけを出す。
 */
final class HomeSectionsTest extends TestCase
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

    public function test_sections_show_only_photos_and_products_of_published_listings(): void
    {
        $this->tenant('hsa')->forceFill(['theme' => 'kinari'])->save();
        $this->tenant('hsb');
        $otherCategory = $this->inside('hsb', function (): string {
            $listing = $this->listing('別の店のコート', ListingStatus::Active);
            $category = $this->category('よそ', 'elsewhere');
            app(CategoryService::class)->assign($listing, [$category]);

            return $category;
        });

        [$knit, $draft, , $hidden, $autumn] = $this->inside('hsa', function () use ($otherCategory): array {
            $knitListing = $this->listing('ニット', ListingStatus::Active);
            $knit = $this->photo($knitListing);
            $draftListing = $this->listing('下書きのシャツ', ListingStatus::Draft);
            $draft = $this->photo($draftListing);
            $knitCategory = $this->category('<i>ニ</i>ット', 'knit');
            $shirtCategory = $this->category('シャツ', 'shirts');
            app(CategoryService::class)->assign($knitListing, [$knitCategory]);
            app(CategoryService::class)->assign($draftListing, [$shirtCategory]);   // 下書きの商品だけ → 出さない
            $favorite = $this->listing('<b>人気</b>のパーカー', ListingStatus::Active);
            $hidden = $this->listing('下書きのパンツ', ListingStatus::Draft);
            $autumn = $this->shopPhoto();

            // 別の店のカテゴリの ID は、この店の DB には無いので保存の口が断る
            try {
                $this->publish('kinari', ['categories' => ['values' => ['list' => [$knitCategory, $otherCategory]]]]);
                $this->fail('ほかの店のカテゴリを保存できてしまった');
            } catch (ValidationException) {
            }

            $this->publish('kinari', [
                'categories' => ['values' => ['list' => [$knitCategory, $shirtCategory]]],
                // 写真の無い組しか無いコーディネートは出さない
                'looks' => ['values' => ['heading' => '着こなし'], 'items' => [['title' => '写真なし']]],
                'favorites' => ['values' => ['products' => ['source' => 'pick', 'ids' => [$hidden->id, $favorite->id]]]],
                'seasonal' => ['values' => ['heading' => '秋のはおりもの', 'highlight' => '20% オフ', 'image' => $autumn]],
            ]);

            // 前からしまってある値（確かめを入れる前に保存した値など）がほかの店の ID を指していても、ショップは出さない
            $version = ThemeVersion::query()->where('theme', 'kinari')->where('current', true)->sole();
            $published = $version->published ?? [];
            foreach ($published['pages']['home'] as $i => $section) {
                if ($section['type'] === 'categories') {
                    $published['pages']['home'][$i]['values']['list'][] = $otherCategory;
                }
            }
            DB::table('theme_versions')->where('id', $version->id)->update(['published' => json_encode($published)]);

            return [$knit->id, $draft->id, $favorite->id, $hidden->id, $autumn];
        });

        $html = (string) $this->get('http://hsa.localhost/')->assertOk()->getContent();
        tenancy()->end();

        $this->assertStringContainsString("/media/listings/{$knit}/medium", $html);
        $this->assertStringContainsString("/media/shop/{$autumn}/medium", $html);
        $this->assertStringNotContainsString($draft, $html);
        $this->assertStringNotContainsString('よそ', $html);
        $this->assertStringNotContainsString('>シャツ<', $html);
        $this->assertStringNotContainsString('<i>ニ</i>', $html);
        $this->assertStringContainsString('&lt;i&gt;ニ&lt;/i&gt;ット', $html);
        $this->assertStringNotContainsString('data-test="looks"', $html);
        $this->assertStringContainsString('data-test="categories"', $html);
        $this->assertStringContainsString('data-test="seasonal"', $html);
        // 選んだ商品は公開中のものだけ、指定した順
        $this->assertStringNotContainsString('下書きのパンツ', $html);
        $this->assertStringNotContainsString($hidden, $html);
        $favorites = substr($html, (int) strpos($html, 'data-test="favorites"'));
        $favorites = substr($favorites, 0, (int) strpos($favorites, '</section>'));
        $this->assertSame(1, substr_count($favorites, 'data-test="product-card"'));
        $this->assertStringContainsString('&lt;b&gt;人気&lt;/b&gt;のパーカー', $favorites);

        // 区画が空なら出さない（既定のテーマも区画を受け取って壊れない）
        $this->inside('hsa', fn () => $this->publish('kinari', []));
        $html = (string) $this->get('http://hsa.localhost/')->assertOk()->getContent();
        tenancy()->end();
        $this->assertStringNotContainsString('data-test="favorites"', $html);
        $this->assertStringNotContainsString('data-test="seasonal"', $html);
        Tenant::query()->findOrFail('hsa')->forceFill(['theme' => null])->save();
        $this->get('http://hsa.localhost/')->assertOk();
    }

    public function test_category_tiles_pick_photos_of_published_products(): void
    {
        $this->tenant('hsd');
        [, $knit, $bottoms, $empty, $knitPhoto, $draftPhoto] = $this->inside('hsd', function (): array {
            $tops = $this->category('トップス', 'tops');
            $knit = $this->category('ニット', 'knit', $tops);
            $bottoms = $this->category('ボトムス', 'bottoms');
            $empty = $this->category('まだ空', 'empty');
            $knitListing = $this->listing('ベージュのニット', ListingStatus::Active);
            $knitPhoto = $this->photo($knitListing);
            app(CategoryService::class)->assign($knitListing, [$knit]);       // トップスには、下の段のニットの商品の写真を使う
            $pants = $this->listing('パンツ', ListingStatus::Active);
            $this->photo($pants);
            app(CategoryService::class)->assign($pants, [$bottoms]);
            $draft = $this->listing('下書き', ListingStatus::Draft);
            $draftPhoto = $this->photo($draft);
            // 代表の写真が下書きの商品のものなら使わない（公開中の商品の写真に替える）
            app(CategoryService::class)->update(Category::query()->findOrFail($bottoms), ['image_id' => $draftPhoto->id]);

            return [$tops, $knit, $bottoms, $empty, $knitPhoto->id, $draftPhoto->id];
        });
        Tenant::query()->findOrFail('hsd')->forceFill(['theme' => 'kinari'])->save();

        // 選んでいなければ、一番上の段を並び順に。商品の無いカテゴリは出さない
        $tiles = $this->tiles('hsd');
        $this->assertSame(['トップス', 'ボトムス'], array_column($tiles, 'label'));
        $this->assertStringContainsString($knitPhoto, $tiles[0]['src']);
        $this->assertStringNotContainsString($draftPhoto, $tiles[1]['src']);

        // 選んだカテゴリを、選んだ順に
        $this->inside('hsd', fn () => $this->publish('kinari', ['categories' => ['values' => ['list' => [$knit, $bottoms, $empty]]]]));
        $this->assertSame(['ニット', 'ボトムス'], array_column($this->tiles('hsd'), 'label'));
    }

    /**
     * @return list<array{label: string, src: string}>
     */
    private function tiles(string $tenant): array
    {
        $html = (string) $this->get("http://{$tenant}.localhost/")->assertOk()->getContent();
        tenancy()->end();
        $section = substr($html, (int) strpos($html, 'data-test="categories"'));
        $section = substr($section, 0, (int) strpos($section, '</section>'));
        preg_match_all('/<img src="([^"]+)".*?<span[^>]*>([^<]+)<\/span>/s', $section, $m, PREG_SET_ORDER);

        return array_map(static fn (array $row): array => ['label' => html_entity_decode($row[2]), 'src' => $row[1]], $m);
    }

    /**
     * テーマの区画の値を下書きに入れて公開する（テナント文脈の中で呼ぶ）
     *
     * @param  array<string, mixed>  $sections
     */
    private function publish(string $theme, array $sections): void
    {
        $service = app(ThemeContentService::class);
        $schema = app(ThemeRegistry::class)->get($theme)->sections;
        $this->assertNotNull($schema);
        $current = $service->current($theme);
        $version = $service->saveDraft($theme, $schema, $current?->id, $current->revision ?? 0, $sections, 'test');
        $service->publish($theme, $schema, $version->id, $version->revision, 'test');
    }

    private function shopPhoto(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sec');
        file_put_contents($path, $this->jpeg(400, 300));
        $this->files[] = $path;

        return app(ShopMediaService::class)->add($path, null, 'test')->id;
    }

    private function category(string $name, string $slug, ?string $parent = null): string
    {
        return app(CategoryService::class)->create(['name' => $name, 'slug' => $slug, 'parent_id' => $parent])->id;
    }

    private function listing(string $title, ListingStatus $status): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => $status]);
    }

    private function photo(Listing $listing): ListingImage
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sec');
        file_put_contents($path, $this->jpeg(40, 50));
        $this->files[] = $path;

        return app(ListingImageService::class)->add($listing, $path);
    }
}
