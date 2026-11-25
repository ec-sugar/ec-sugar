<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Section\Models\ShopPage;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OperatorSession;
use Tests\Support\RegistersSampleTheme;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ページの雛形: 自由なページを作るときに選ぶ・ページの編集の画面で「雛形から作り直す」。
 * 4 つの公式テーマのどれでも 7 つとも作れ、テーマの役目（ヒーロー・カテゴリ・商品の並び・安心の一行）はテーマの区画を選ぶ。
 * 作り直しは下書きだけを変え（公開するまでショップは変わらない）、外枠が読む区画（お知らせの帯・フッター）を残し、ほかの店に触れない。
 * 閲覧の段階の人は使えない。知らない雛形・古い改訂・決まった並びのページ・1 つも置けない雛形は断る
 */
final class PageTemplatesAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use RegistersSampleTheme;
    use WithTenants;

    private const array TEMPLATES = ['shop_top', 'single_product', 'brand_story', 'seasonal', 'faq', 'how_to', 'service'];

    /** テーマ => [トップの先頭に残す区画, トップの最後に残す区画, 自由なページの「商品の並び」の役目の種類] */
    private const array FRAMES = [
        'default' => [['announcement'], ['footer'], 'products'],
        'kinari' => [['announcement'], ['footer'], 'new_arrivals'],
        'fashion' => [['announcement'], [], 'favorites'],
        'botanica' => [['announcement'], [], 'favorites'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        return ['default' => ['default'], 'kinari' => ['kinari'], 'fashion' => ['fashion'], 'botanica' => ['botanica']];
    }

    #[DataProvider('themes')]
    public function test_every_template_builds_a_free_page_and_rebuilds_the_top_on_every_official_theme(string $theme): void
    {
        $shop = "pt{$theme[0]}";
        $this->tenant($shop)->forceFill(['theme' => $theme])->save();
        $this->enrolledOperator();
        [$head, $tail, $products] = self::FRAMES[$theme];

        // 作る画面の雛形: 7 つ。ヒーロー・商品の並びはテーマの区画（役目）、新しい区画は核の区画
        $list = $this->send('GET', "/api/tenants/{$shop}/shop-pages")->assertOk();
        $this->assertSame(self::TEMPLATES, array_column($list->json('templates'), 'name'));
        $catalog = array_column($list->json('templates'), null, 'name');
        $this->assertSame(['hero', 'theme'], [$catalog['shop_top']['rows'][0]['type'], $catalog['shop_top']['rows'][0]['from']]);
        $this->assertSame([$products, 'theme'], [$catalog['shop_top']['rows'][2]['type'], $catalog['shop_top']['rows'][2]['from']]);
        $this->assertContains('basic_table', array_column($catalog['single_product']['rows'], 'type'));
        $this->assertContains('basic_numbers', array_column($catalog['brand_story']['rows'], 'type'));
        $this->assertContains('basic_steps', array_column($catalog['how_to']['rows'], 'type'));
        $this->assertContains('basic_pricing', array_column($catalog['service']['rows'], 'type'));

        // 雛形を選んで作る: 雛形の並びが下書きに入る（ページは下書きのまま。ショップには出ない）
        foreach (self::TEMPLATES as $name) {
            $slug = str_replace('_', '-', $name);
            $created = $this->send('POST', "/api/tenants/{$shop}/shop-pages", ['title' => $catalog[$name]['label'], 'slug' => $slug, 'template' => $name])
                ->assertCreated()
                ->assertJsonPath('created.status', 'draft');
            $page = $this->send('GET', "/api/tenants/{$shop}/themes/{$theme}/pages/{$created->json('created.name')}")->assertOk();
            $this->assertSame(array_column($catalog[$name]['rows'], 'type'), array_column($page->json('sections'), 'type'), "{$theme} {$name}");
            $this->assertSame(array_column($catalog[$name]['rows'], 'pattern'), array_column($page->json('sections'), 'pattern'), "{$theme} {$name}");
            $this->assertSame([], $catalog[$name]['skipped'], "{$theme} {$name}: 公式テーマでは飛ばすセクションが無い");
            $this->get("http://{$shop}.localhost/pages/{$slug}")->assertNotFound();
            tenancy()->end();
        }

        // 見本の文が入っている（写真は無い）。1 つの商品は選ぶまで空
        $faq = $this->send('GET', "/api/tenants/{$shop}/themes/{$theme}/pages/".$this->pageName($shop, 'faq'))->assertOk();
        $this->assertSame('よくある質問', $faq->json('sections.0.values.heading'));
        $this->assertSame('支払いの方法は何がありますか？', $faq->json('sections.1.blocks.2.values.question'));

        // トップを作り直す: お知らせの帯（とフッター）は残り、その間に雛形の並び
        foreach (self::TEMPLATES as $name) {
            $home = $this->send('GET', "/api/tenants/{$shop}/themes/{$theme}/pages/home")->assertOk();
            $rows = array_column(array_column($home->json('templates'), null, 'name')[$name]['rows'], 'type');
            $rebuilt = $this->send('POST', "/api/tenants/{$shop}/themes/{$theme}/pages/home/template", [
                'version_id' => $home->json('version.id'), 'revision' => $home->json('version.revision'), 'template' => $name,
            ])->assertOk();
            $this->assertSame([...$head, ...$rows, ...$tail], array_column($rebuilt->json('sections'), 'type'), "{$theme} {$name}");
        }

        // トップの「トップ（雑貨の店）」: 4 つの役目はどれもテーマの区画
        $home = $this->send('GET', "/api/tenants/{$shop}/themes/{$theme}/pages/home")->assertOk();
        $top = array_column($home->json('templates'), null, 'name')['shop_top']['rows'];
        $this->assertSame(['theme', 'theme', 'theme', 'core', 'core', 'theme'], array_column($top, 'from'));
    }

    /**
     * 作り直すのは下書きだけ: 公開した値とショップは変わらず、「公開していない変更」になる。公開するとショップに出る。ほかの店には触れない
     */
    public function test_rebuilding_changes_only_the_draft_of_this_shop(): void
    {
        $this->tenant('ptd')->forceFill(['theme' => 'default'])->save();
        $this->tenant('pte')->forceFill(['theme' => 'default'])->save();
        $this->enrolledOperator();

        // ほかの店にも中身がある
        $this->send('PUT', '/api/tenants/pte/themes/default/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'hero', 'pattern' => 'default', 'values' => ['heading' => 'ほかの店の見出し']],
        ]])->assertOk();
        $otherBefore = $this->inside('pte', static fn (): array => ThemeVersion::query()->firstOrFail()->only(['draft', 'revision', 'published']));

        $saved = $this->send('PUT', '/api/tenants/ptd/themes/default/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'announcement', 'values' => ['text' => '送料のお知らせ']],
            ['type' => 'hero', 'pattern' => 'default', 'values' => ['heading' => '公開中の見出し']],
            ['type' => 'footer', 'pattern' => 'line'],
        ]])->assertOk();
        $published = $this->send('POST', '/api/tenants/ptd/themes/default/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => $saved->json('version.revision')])
            ->assertOk()
            ->assertJsonPath('version.unpublished_changes', false);

        $rebuilt = $this->send('POST', '/api/tenants/ptd/themes/default/pages/home/template', [
            'version_id' => $published->json('version.id'), 'revision' => $published->json('version.revision'), 'template' => 'faq',
        ])->assertOk()->assertJsonPath('version.unpublished_changes', true);
        $this->assertSame(['announcement', 'basic_text', 'basic_faq', 'basic_cta', 'footer'], array_column($rebuilt->json('sections'), 'type'));
        // 残した区画は値とパターンのまま
        $this->assertSame('送料のお知らせ', $rebuilt->json('sections.0.values.text'));
        $this->assertSame('line', $rebuilt->json('sections.4.pattern'));

        // ショップはまだ前のまま
        $shop = (string) $this->get('http://ptd.localhost/')->assertOk()->getContent();
        tenancy()->end();
        $this->assertStringContainsString('公開中の見出し', $shop);
        $this->assertStringNotContainsString('sugar-faq', $shop);
        $this->inside('ptd', function (): void {
            $schema = app(ThemeRegistry::class)->get('default')->schema();
            $this->assertSame(['announcement', 'hero', 'footer'], array_column(app(ThemeContentService::class)->publishedPage('default', $schema, 'home'), 'type'));
        });

        // 公開するとショップに出る
        $this->send('POST', '/api/tenants/ptd/themes/default/pages/home/publish', ['version_id' => $rebuilt->json('version.id'), 'revision' => $rebuilt->json('version.revision')])->assertOk();
        $shop = (string) $this->get('http://ptd.localhost/')->assertOk()->getContent();
        tenancy()->end();
        $this->assertStringContainsString('sugar-faq', $shop);
        $this->assertStringContainsString('送料はいくらですか？', $shop);
        $this->assertStringNotContainsString('公開中の見出し', $shop);

        // ほかの店はそのまま
        $this->assertSame($otherBefore, $this->inside('pte', static fn (): array => ThemeVersion::query()->firstOrFail()->only(['draft', 'revision', 'published'])));
    }

    /**
     * 閲覧の段階の人は雛形を見られるが、作る・作り直すはできない（何も変わらない）
     */
    public function test_viewers_cannot_build_from_a_template(): void
    {
        $this->tenant('ptv')->forceFill(['theme' => 'default'])->save();
        [$staff] = $this->enrolledOperator('viewer@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'ptv', 'read', 'test');

        $this->send('GET', '/api/tenants/ptv/shop-pages')->assertOk()->assertJsonCount(count(self::TEMPLATES), 'templates');
        $home = $this->send('GET', '/api/tenants/ptv/themes/default/pages/home')->assertOk()->assertJsonCount(count(self::TEMPLATES), 'templates');
        $this->send('POST', '/api/tenants/ptv/themes/default/pages/home/template', ['version_id' => null, 'revision' => 0, 'template' => 'faq'])->assertForbidden();
        $this->send('POST', '/api/tenants/ptv/shop-pages', ['title' => 'よくある質問', 'slug' => 'faq', 'template' => 'faq'])->assertForbidden();
        $this->assertNull($home->json('version'));

        $this->inside('ptv', function (): void {
            $this->assertSame(0, ThemeVersion::query()->count());
            $this->assertSame(0, ShopPage::withTrashed()->count());
        });
    }

    /**
     * 断る: 知らない雛形（作るときはページも作らない）・古い改訂（409）・決まった並びのページ・1 つも置けない雛形。
     * 置けない区画は飛ばして、置ける区画だけで作る（テスト用のテーマの自由なページは見出しと文・ヒーロー・商品の並びだけ）
     */
    public function test_unknown_stale_fixed_and_unplaceable_templates_are_refused(): void
    {
        $this->registerSampleTheme();
        $this->registerLegacyTheme();
        $this->tenant('ptr')->forceFill(['theme' => 'default'])->save();
        $this->tenant('pts')->forceFill(['theme' => 'sample'])->save();
        $this->tenant('ptl')->forceFill(['theme' => 'legacy'])->save();
        $this->enrolledOperator();

        $this->send('POST', '/api/tenants/ptr/shop-pages', ['title' => 'x', 'slug' => 'x', 'template' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('template');
        $this->send('POST', '/api/tenants/ptr/themes/default/pages/home/template', ['version_id' => null, 'revision' => 0, 'template' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('template');
        $this->inside('ptr', fn () => $this->assertSame(0, ShopPage::withTrashed()->count()));

        // 古い改訂
        $first = $this->send('POST', '/api/tenants/ptr/themes/default/pages/home/template', ['version_id' => null, 'revision' => 0, 'template' => 'faq'])->assertOk();
        $this->send('POST', '/api/tenants/ptr/themes/default/pages/home/template', ['version_id' => $first->json('version.id'), 'revision' => 0, 'template' => 'how_to'])->assertConflict();
        $this->send('GET', '/api/tenants/ptr/themes/default/pages/home')->assertOk()->assertJsonPath('sections.2.type', 'basic_faq');

        // 決まった並びのページ（今の書き方のテーマ）は作り直せない。雛形も出さない
        $this->send('GET', '/api/tenants/ptl/themes/legacy/pages/home')->assertOk()->assertJsonPath('templates', []);
        $this->send('POST', '/api/tenants/ptl/themes/legacy/pages/home/template', ['version_id' => null, 'revision' => 0, 'template' => 'faq'])->assertUnprocessable();
        $this->inside('ptl', fn () => $this->assertSame(0, ThemeVersion::query()->count()));

        // 役目を書いていないテーマ: 置けない区画は飛ばし（名前を知らせる）、置ける区画だけで作る。1 つも置けなければ断り、ページも作らない
        $catalog = array_column($this->send('GET', '/api/tenants/pts/shop-pages')->assertOk()->json('templates'), null, 'name');
        $this->assertSame(['basic_text'], array_column($catalog['faq']['rows'], 'type'));
        $this->assertSame(['よくある質問', 'CTA バナー'], $catalog['faq']['skipped']);
        $this->assertSame([], $catalog['single_product']['rows']);
        $created = $this->send('POST', '/api/tenants/pts/shop-pages', ['title' => 'よくある質問', 'slug' => 'faq', 'template' => 'faq'])->assertCreated();
        $this->send('GET', "/api/tenants/pts/themes/sample/pages/{$created->json('created.name')}")->assertOk()->assertJsonPath('sections.0.type', 'basic_text')->assertJsonCount(1, 'sections');
        $this->send('POST', '/api/tenants/pts/shop-pages', ['title' => '商品', 'slug' => 'item', 'template' => 'single_product'])->assertUnprocessable()->assertJsonValidationErrors('template');
        $this->inside('pts', fn () => $this->assertSame(['faq'], ShopPage::query()->pluck('slug')->all()));
    }

    private function pageName(string $shop, string $slug): string
    {
        return $this->inside($shop, static fn (): string => ShopPage::query()->where('slug', $slug)->firstOrFail()->pageName());
    }
}
