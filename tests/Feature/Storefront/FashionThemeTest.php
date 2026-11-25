<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Services\CategoryService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\Models\ShopMedia;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 公式テーマ 2（fashion）。テーマ API 2 の区画（theme.json）で描き、店の設定の今の値はコマンドで区画へ写す（ADR 0028 区切り 2b）。
 */
final class FashionThemeTest extends TestCase
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

    public function test_fashion_registers_every_required_page_and_declares_its_sections(): void
    {
        $theme = app(ThemeRegistry::class)->get('fashion');
        $this->assertSame(2, $theme->themeApi);
        $this->assertSame('plugins/theme-fashion/resources/css/theme.css', $theme->stylesheet);
        $this->assertSame(['announcement', 'hero', 'categories', 'new_arrivals', 'looks', 'seasonal', 'favorites', 'promises'], array_keys($theme->sections->sections ?? []));
        // 並びの形（ADR 0039 区切り 4）: トップの最初の並びは今までのトップと同じ順、自由なページの雛形も持つ
        $this->assertSame(['announcement', 'hero', 'categories', 'new_arrivals', 'looks', 'seasonal', 'favorites', 'promises'], array_column($theme->schema()->page('home')->default ?? [], 'type'));
        $this->assertFalse($theme->schema()->page('home')?->fixed);
        $this->assertSame([['type' => 'basic_text', 'pattern' => 'left']], $theme->schema()->page('page-01jbx00000000000000000000a')?->default);
        foreach (['layout', 'home', 'page', 'products', 'product', 'cart', 'checkout', 'checkout-confirm', 'checkout-complete', 'order-lookup', 'order', 'legal', 'error'] as $page) {
            $this->assertTrue(view()->exists($theme->view($page)), $page);
        }

        // composer の記録（プラグインの一覧が読む）も同じバージョン
        $manifest = json_decode((string) file_get_contents(base_path('plugins/theme-fashion/composer.json')), true);
        $this->assertSame(2, $manifest['extra']['ec-sugar']['theme_api'] ?? null);
    }

    public function test_fashion_draws_published_sections_escapes_text_and_hides_empty_ones(): void
    {
        $this->tenant('fsa')->forceFill(['theme' => 'fashion'])->save();
        [$hero, $category] = $this->inside('fsa', function (): array {
            $listing = $this->listing('ワンピース', ListingStatus::Active);
            $this->photo($listing);
            $category = app(CategoryService::class)->create(['name' => '<b>Women</b>', 'slug' => 'women', 'parent_id' => null])->id;
            app(CategoryService::class)->assign($listing, [$category]);

            return [$this->shopPhoto(), $category];
        });

        // 何も入れていない店: 店の名前の見出し、カテゴリは一番上の段、空の区画は出ない
        $html = $this->home('fsa');
        $this->assertStringContainsString('<h1 class="whitespace-pre-line" data-test="hero-heading">店 fsa</h1>', $html);
        $this->assertStringContainsString('&lt;b&gt;Women&lt;/b&gt;', $html);
        foreach (['announcement', 'hero-image', 'looks', 'seasonal', 'favorites', 'promises'] as $hidden) {
            $this->assertStringNotContainsString("data-test=\"{$hidden}\"", $html, $hidden);
        }

        $this->publish('fsa', [
            'announcement' => ['values' => ['text' => '<script>bad</script>']],
            'hero' => ['values' => ['heading' => "秋の\n新作", 'image' => $hero]],
            'categories' => ['values' => ['list' => [$category]]],
            // 写真の無い組・見出しの無い季節の特集は出さない
            'looks' => ['values' => ['heading' => '着こなし'], 'items' => [['title' => '写真なし']]],
            'seasonal' => ['values' => ['heading' => '秋のはおりもの']],
            'promises' => ['items' => [['icon' => 'returns', 'title' => '30 日返品OK'], ['body' => '見出しなし']]],
        ]);

        $html = $this->home('fsa');
        $this->assertStringContainsString('<p class="bg-band-strong px-4 py-2 text-center text-xs text-ink-muted" data-test="announcement">&lt;script&gt;bad&lt;/script&gt;</p>', $html);
        $this->assertStringNotContainsString('<script>bad', $html);
        $this->assertStringContainsString("秋の\n新作", $html);
        $this->assertStringContainsString("/media/shop/{$hero}/large", $html);
        $this->assertStringContainsString('fashion-hero-photo', $html);
        $this->assertStringNotContainsString('data-test="looks"', $html);
        $this->assertStringNotContainsString('data-test="seasonal"', $html);
        $this->assertSame(1, substr_count($html, '<li class="flex items-center gap-4">'));
        $this->assertStringContainsString('30 日返品OK', $html);
        $this->assertLessThan(strpos($html, 'id="new"'), strpos($html, 'data-test="categories"'));

        // 隠したヒーローは写真ごと出ない（見出しは店の名前に戻る）
        $this->publish('fsa', ['hero' => ['hidden' => true, 'values' => ['heading' => '隠した見出し', 'image' => $hero]]]);
        $html = $this->home('fsa');
        $this->assertStringNotContainsString('隠した見出し', $html);
        $this->assertStringNotContainsString('data-test="hero-image"', $html);
    }

    public function test_the_shops_current_settings_are_copied_into_the_sections_once(): void
    {
        $this->tenant('fsb')->forceFill(['theme' => 'fashion'])->save();
        [$favorite, $draft, $coatListing, $look, $knit] = $this->inside('fsb', function (): array {
            // 控えのカテゴリの並びに、後で消したカテゴリが残っていても写しは止まらず、そのカテゴリだけ外す（区画の保存は無いカテゴリを断るので）
            $knit = app(CategoryService::class)->create(['name' => 'ニット', 'slug' => 'knit'], 'cli:test')->id;
            $coat = $this->listing('コート', ListingStatus::Active);
            $coatPhoto = $this->photo($coat);
            $favorite = $this->listing('<b>人気</b>のパーカー', ListingStatus::Active);
            $draft = $this->listing('下書きのパンツ', ListingStatus::Draft);
            // 発売前（下書き）の商品の写真。今のショップには出ていないので、写しでも出さない
            $unreleased = $this->photo($draft);
            $this->legacy([
                'announcement' => "送料\t無料",
                'hero' => ['heading' => "秋の\n新作", 'body' => '届きました'],
                'promises' => [['icon' => 'returns', 'title' => '返品OK', 'body' => null]],
            ], [
                'categories' => [strtolower((string) Str::ulid()), strtoupper($knit)],
                'looks' => ['heading' => '着こなし', 'body' => null, 'items' => [['title' => 'コートの日', 'image_id' => $coatPhoto->id], ['title' => 'コートの夜', 'image_id' => $coatPhoto->id], ['title' => '発売前', 'image_id' => $unreleased->id]]],
                'favorites' => [$draft->id, $favorite->id],
                'seasonal' => ['label' => 'AUTUMN', 'heading' => '秋のはおりもの', 'body' => null, 'highlight' => '20% OFF', 'note' => null, 'image_id' => $unreleased->id],
            ], $this->shopPhoto());

            return [$favorite->id, $draft->id, $coat, $coatPhoto->id, $knit];
        });

        $this->artisan('storefront:import-theme-content', ['tenant' => 'fsb'])->expectsOutputToContain('コピーして公開した')->assertSuccessful();

        $this->inside('fsb', function () use ($favorite, $draft, $look, $knit): void {
            $version = ThemeVersion::query()->sole();
            $this->assertSame(['fashion', ThemeContentService::DEFAULT_NAME, true], [$version->theme, $version->name, $version->current]);
            $this->assertNotNull($version->published_at);
            $published = app(ThemeContentService::class)->published('fashion', app(ThemeRegistry::class)->get('fashion')->schema());
            // タブは空白にして写す（新しい値は制御文字を断るので、黙って消さない）
            $this->assertSame('送料 無料', $published['announcement']['values']['text']);
            $this->assertSame("秋の\n新作", $published['hero']['values']['heading']);
            $this->assertEquals([['icon' => 'returns', 'title' => '返品OK', 'body' => null]], $published['promises']['items']);
            $this->assertSame([$knit], $published['categories']['values']['list']);
            $this->assertEquals(['source' => 'pick', 'category' => null, 'ids' => [$draft, $favorite]], $published['favorites']['values']['products']);
            // 特集の写真は公開中の商品の写真だけを、ショップの写真へ写し直した（同じ写真は 1 枚だけ。元の商品の写真の ID は残らない）
            $copied = array_column($published['looks']['items'], 'image');
            $this->assertNotNull($copied[0]);
            $this->assertSame($copied[0], $copied[1]);
            $this->assertNull($copied[2]);
            $this->assertNotContains($look, $copied);
            $this->assertNull($published['seasonal']['values']['image']);
            // ヒーロー（置き場にあった）と、写した 1 枚だけ
            $this->assertSame(2, ShopMedia::query()->count());
            $this->assertSame(['theme.version_created', 'theme.draft_saved', 'theme.published'], DomainEvent::query()->where('aggregate_type', 'theme_version')->orderBy('sequence')->pluck('type')->all());
        });

        // 2 回目は何もしない（店の人が区画を編集した後も上書きしない）
        $this->artisan('storefront:import-theme-content', ['tenant' => 'fsb'])->expectsOutputToContain('もうバージョンがある')->assertSuccessful();
        $this->assertSame(1, $this->inside('fsb', fn (): int => ThemeVersion::query()->count()));

        // 商品を取り下げても、特集の写真は消えない
        $this->inside('fsb', fn () => $coatListing->forceFill(['status' => ListingStatus::Draft])->save());
        $html = $this->home('fsb');
        $this->assertStringContainsString('data-test="looks"', $html);
        $this->assertStringContainsString('コートの夜', $html);
        $this->assertStringNotContainsString('発売前', $html);
        // 発売前の写真しか無かった季節の特集は、写す前と同じく出ない
        $this->assertStringNotContainsString('data-test="seasonal"', $html);
        $this->assertStringNotContainsString('20% OFF', $html);
        $this->assertStringContainsString('&lt;b&gt;人気&lt;/b&gt;のパーカー', $html);
        $this->assertStringNotContainsString('下書きのパンツ', $html);
        $this->assertStringNotContainsString('/media/listings/', substr($html, (int) strpos($html, 'data-test="looks"'), 2000));
    }

    public function test_switching_to_a_theme_with_sections_copies_the_current_settings_once(): void
    {
        $this->tenant('fsd');
        // 控えのヒーローの写真が置き場から消えていても、写しは止まらず写真だけ外す
        $this->inside('fsd', fn () => $this->legacy(['announcement' => '初めてのお知らせ'], null, strtolower((string) Str::ulid())));

        $this->artisan('storefront:theme', ['tenant' => 'fsd', 'theme' => 'fashion'])->assertSuccessful();
        $this->assertStringContainsString('初めてのお知らせ', $this->home('fsd'));

        // 区画を編集した後に別のテーマへ行って戻っても、上書きしない
        $this->publish('fsd', ['announcement' => ['values' => ['text' => '編集したお知らせ']]]);
        $this->artisan('storefront:theme', ['tenant' => 'fsd', '--reset' => true])->assertSuccessful();
        $this->artisan('storefront:theme', ['tenant' => 'fsd', 'theme' => 'fashion'])->assertSuccessful();
        $this->assertStringContainsString('編集したお知らせ', $this->home('fsd'));
        // 既定のテーマ（角砂糖）へ戻したときはそちらにも写る。fashion のバージョンは 1 つのまま
        $this->assertSame(1, $this->inside('fsd', fn (): int => ThemeVersion::query()->where('theme', 'fashion')->count()));
    }

    public function test_the_import_refuses_unknown_themes_and_skips_themes_without_sections(): void
    {
        app(ThemeRegistry::class)->register(new ThemeDefinition('plain', 'セクションの無いテーマ', ThemeRegistry::API_VERSION, 'theme-sample', 'x.css', manifest: base_path('tests/Support/Themes/plain/theme.json')));
        $this->tenant('fsc')->forceFill(['theme' => 'plain'])->save();
        $this->artisan('storefront:import-theme-content', ['tenant' => 'fsc', '--theme' => 'nope'])->assertFailed();
        $this->artisan('storefront:import-theme-content', ['tenant' => 'nope'])->assertFailed();
        // 区画を宣言していないテーマを使う店は、写すものがあっても写さない
        $this->inside('fsc', fn () => $this->legacy(['announcement' => '控えのお知らせ']));
        $this->artisan('storefront:import-theme-content', ['tenant' => 'fsc'])->expectsOutputToContain('もうバージョンがある')->assertSuccessful();
        $this->assertSame(0, $this->inside('fsc', fn (): int => ThemeVersion::query()->count()));
        // 写すものが無ければバージョンを作らない
        $this->inside('fsc', fn () => DB::table('legacy_storefront_settings')->delete());
        $this->artisan('storefront:import-theme-content', ['tenant' => 'fsc', '--theme' => 'fashion'])->assertSuccessful();
        $this->assertSame(0, $this->inside('fsc', fn (): int => ThemeVersion::query()->where('theme', 'fashion')->count()));
    }

    /**
     * 店の設定にあった見せ方の項目（区切り 2c で控えの表へ移した形）を 1 行置く。テナント文脈の中で呼ぶ
     *
     * @param  array<string, mixed>  $storefront
     * @param  array<string, mixed>|null  $sections
     */
    private function legacy(array $storefront, ?array $sections = null, ?string $heroMediaId = null): void
    {
        DB::table('legacy_storefront_settings')->insert([
            'id' => strtolower((string) Str::ulid()),
            'storefront' => json_encode($storefront),
            'sections' => $sections === null ? null : json_encode($sections),
            'hero_media_id' => $heroMediaId,
            'archived_at' => now(),
        ]);
    }

    private function home(string $tenant): string
    {
        $html = (string) $this->get("http://{$tenant}.localhost/")->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }

    /**
     * @param  array<string, mixed>  $sections
     */
    private function publish(string $tenant, array $sections): void
    {
        $this->inside($tenant, function () use ($sections): void {
            $service = app(ThemeContentService::class);
            $schema = app(ThemeRegistry::class)->get('fashion')->sections;
            $this->assertNotNull($schema);
            $current = $service->current('fashion');
            // 「テーマの中身」の画面と同じく、トップの区画の種類をすべて送る（並びの形では、送らない種類は並びから外れる）
            $sections += array_fill_keys($schema->page('home')->types ?? [], []);
            $version = $service->saveDraft('fashion', $schema, $current?->id, $current->revision ?? 0, $sections, 'test');
            $service->publish('fashion', $schema, $version->id, $version->revision, 'test');
        });
    }

    private function listing(string $title, ListingStatus $status): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => $status]);
    }

    private function photo(Listing $listing): ListingImage
    {
        return app(ListingImageService::class)->add($listing, $this->file(400, 500));
    }

    private function shopPhoto(): string
    {
        return app(ShopMediaService::class)->add($this->file(1600, 900), null, 'test')->id;
    }

    private function file(int $width, int $height): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'fs');
        file_put_contents($path, $this->jpeg($width, $height));
        $this->files[] = $path;

        return $path;
    }
}
