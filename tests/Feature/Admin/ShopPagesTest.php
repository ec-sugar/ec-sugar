<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Section\Models\ShopPage;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\RegistersSampleTheme;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 自由なページ（ADR 0039 区切り 3）: 作る → 区画を積む → 公開するとショップの /pages/{slug} に出てメニューに載る。
 * 下書きのページと下書きの中身は出ない。slug は予約語と重なりを断る（アプリと DB の両方）。テーマが置けない種類は断る。
 * 準備中のショップでは関所の内側
 */
final class ShopPagesTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use RegistersSampleTheme;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->registerSampleTheme();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_page_is_built_from_sections_and_shows_on_the_shop_once_published(): void
    {
        $this->tenant('fpa')->forceFill(['theme' => 'sample'])->save();
        $this->enrolledOperator();

        // 作る: 最初は下書き（お客さんには出ない）
        $created = $this->send('POST', '/api/tenants/fpa/shop-pages', ['title' => '私たちについて', 'slug' => 'about', 'in_nav' => true])
            ->assertCreated()
            ->assertJsonPath('created.status', 'draft')
            ->assertJsonPath('created.slug', 'about')
            ->assertJsonPath('created.url', StorefrontUrls::base('fpa.localhost').'/pages/about')
            ->assertJsonCount(1, 'pages');
        $name = (string) $created->json('created.name');
        $id = (string) $created->json('created.id');
        $this->assertSame('page-'.$id, $name);
        $this->shop('fpa', '/pages/about')->assertNotFound();
        $this->shop('fpa', '/')->assertOk()->assertDontSee('data-test="nav-pages"', false);

        // 編集の画面の API: テーマの雛形（pages.page）の最初の並び、置ける種類にはテーマの区画と核の汎用の区画
        $page = $this->send('GET', "/api/tenants/fpa/themes/sample/pages/{$name}")->assertOk()
            ->assertJsonPath('page.free', true)
            ->assertJsonPath('page.label', '私たちについて')
            ->assertJsonPath('page.types', ['hero', 'rail', 'basic_text'])
            ->assertJsonPath('schema.basic_text.label', '見出しと文')
            ->assertJsonPath('shop_page.slug', 'about')
            ->assertJsonPath('sections.0.type', 'hero')
            ->assertJsonPath('sections.0.pattern', 'split');
        $this->assertNull($page->json('version'));

        // 区画を積む（核の汎用の区画とテーマの区画）。下書きはまだショップに出ない
        $saved = $this->send('PUT', "/api/tenants/fpa/themes/sample/pages/{$name}/draft", ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'basic_text', 'pattern' => 'center', 'values' => ['heading' => '店の歩み', 'body' => "小さな店です。\n二行目"]],
            ['id' => 'hero', 'type' => 'hero', 'pattern' => 'split', 'values' => ['heading' => 'ようこそ']],
        ]])->assertOk()->assertJsonPath('sections.0.type', 'basic_text');
        $versionId = (string) $saved->json('version.id');
        $this->shop('fpa', '/pages/about')->assertNotFound();
        // トップの並びは変わらない（ページごとに並びを持つ）
        $this->send('GET', '/api/tenants/fpa/themes/sample/pages/home')->assertOk()->assertJsonPath('sections.0.type', 'announcement');

        // 下書きのプレビューは、公開していないページも下書きの中身で開ける
        $preview = (string) $this->send('POST', "/api/tenants/fpa/themes/sample/pages/{$name}/preview")->assertOk()->json('url');
        $this->assertStringStartsWith(StorefrontUrls::base('fpa.localhost')."/preview/themes/sample/{$name}?expires=", $preview);
        $this->shop('fpa', $preview)->assertOk()->assertSee('店の歩み')->assertSee('<h1 data-test="sample-page-title">私たちについて</h1>', false);

        // 公開する: 中身とページの公開の状態が一緒に変わる
        $this->send('POST', "/api/tenants/fpa/themes/sample/pages/{$name}/publish", ['version_id' => $versionId, 'revision' => 1])->assertOk()
            ->assertJsonPath('shop_page.status', 'published')
            ->assertJsonPath('version.unpublished_changes', false);
        $html = (string) $this->shop('fpa', '/pages/about')->assertOk()
            ->assertSee('<h1 data-test="sample-page-title">私たちについて</h1>', false)
            ->assertSee('店の歩み')
            ->assertSee('<a href="/pages/about"  aria-current="page" >私たちについて</a>', false)
            ->getContent();
        // 公開した順に、核の部品（basic_text）→ テーマの部品（hero の左右）
        $this->assertMatchesRegularExpression('/data-section-type="basic_text" data-pattern="center".*data-test="hero" data-pattern="split"/s', $html);
        $this->assertStringContainsString('data-section="hero"', $html);
        // ほかの画面のメニューにも載る
        $this->shop('fpa', '/')->assertOk()->assertSee('<a href="/pages/about" >私たちについて</a>', false)->assertDontSee('aria-current', false);

        // また変えても、公開するまでは前の中身のまま
        $this->send('PUT', "/api/tenants/fpa/themes/sample/pages/{$name}/draft", ['version_id' => $versionId, 'revision' => 2, 'sections' => [
            ['type' => 'basic_text', 'values' => ['heading' => 'まだ秘密の見出し']],
        ]])->assertOk();
        $this->shop('fpa', '/pages/about')->assertOk()->assertSee('店の歩み')->assertDontSee('まだ秘密の見出し');

        // 非公開に戻すと出ない。メニューからも消える
        $this->send('PATCH', "/api/tenants/fpa/shop-pages/{$id}", ['status' => 'draft'])->assertOk()->assertJsonPath('pages.0.status', 'draft');
        $this->shop('fpa', '/pages/about')->assertNotFound();
        $this->shop('fpa', '/')->assertOk()->assertDontSee('data-test="nav-pages"', false);

        // ゴミ箱へ入れると、編集の画面の API も 404。戻せる
        $this->send('PATCH', "/api/tenants/fpa/shop-pages/{$id}", ['status' => 'published'])->assertOk();
        $this->send('DELETE', "/api/tenants/fpa/shop-pages/{$id}")->assertOk()->assertJsonCount(0, 'pages')->assertJsonCount(1, 'trash');
        $this->shop('fpa', '/pages/about')->assertNotFound();
        $this->send('GET', "/api/tenants/fpa/themes/sample/pages/{$name}")->assertNotFound();
        $this->send('POST', "/api/tenants/fpa/shop-pages/{$id}/restore")->assertOk()->assertJsonCount(1, 'pages');
        $this->shop('fpa', '/pages/about')->assertOk()->assertSee('店の歩み');

        // 記録には題（店の人の自由な文）を入れない
        $events = $this->inside('fpa', fn (): array => DomainEvent::query()->where('aggregate_type', 'shop_page')->orderBy('sequence')->get(['type', 'payload'])->toArray());
        $this->assertSame(['shop_page.created', 'shop_page.updated', 'shop_page.updated', 'shop_page.updated', 'shop_page.trashed', 'shop_page.restored'], array_column($events, 'type'));
        $this->assertSame(['slug' => 'about'], $events[0]['payload']);
        $this->assertStringNotContainsString('私たちについて', (string) json_encode($events, JSON_UNESCAPED_UNICODE));
    }

    public function test_reserved_badly_formed_and_taken_slugs_are_refused(): void
    {
        $this->tenant('fpb');
        $this->enrolledOperator();

        foreach (['products', 'cart', 'checkout', 'orders', 'password', 'preview', 'media', 'apps', 'legal', 'pages', 'categories', 'consent'] as $reserved) {
            $this->create('fpb', $reserved)->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        }

        foreach (['About', 'a--b', '-about', 'about-', 'a b', 'a_b', 'について', str_repeat('a', 61), ''] as $bad) {
            $this->create('fpb', $bad)->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        }

        $this->create('fpb', "line\nbreak", "題\n二行")->assertUnprocessable()->assertJsonValidationErrors(['title', 'slug']);

        // 店の中で重ならない（作る・変える）
        $about = (string) $this->create('fpb', 'about')->assertCreated()->json('created.id');
        $this->create('fpb', 'about')->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $campaign = (string) $this->create('fpb', 'campaign-2026')->assertCreated()->json('created.id');
        $this->send('PATCH', "/api/tenants/fpb/shop-pages/{$campaign}", ['slug' => 'about'])->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->send('PATCH', "/api/tenants/fpb/shop-pages/{$campaign}", ['slug' => 'cart'])->assertUnprocessable()->assertJsonValidationErrors(['slug']);

        // ゴミ箱のページは数えない。戻すときに重なっていれば断る
        $this->send('DELETE', "/api/tenants/fpb/shop-pages/{$about}")->assertOk();
        $this->send('PATCH', "/api/tenants/fpb/shop-pages/{$campaign}", ['slug' => 'about'])->assertOk();
        $this->send('POST', "/api/tenants/fpb/shop-pages/{$about}/restore")->assertUnprocessable()->assertJsonValidationErrors(['slug']);

        // DB でも断る（アプリの検査をすり抜けても）: 予約語と、ゴミ箱でないページの重なり
        $this->inside('fpb', function (): void {
            foreach (['cart', 'About', 'a--b'] as $slug) {
                try {
                    DB::table('shop_pages')->insert(['id' => strtolower((string) str()->ulid()), 'slug' => $slug, 'title' => 'x', 'created_at' => now(), 'updated_at' => now()]);
                    $this->fail("DB が slug「{$slug}」を通した");
                } catch (QueryException $e) {
                    $this->assertStringContainsString('shop_pages_slug_', $e->getMessage());
                }
            }

            $this->expectException(QueryException::class);
            DB::table('shop_pages')->insert(['id' => strtolower((string) str()->ulid()), 'slug' => 'about', 'title' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function test_sections_the_page_cannot_hold_are_refused_and_themes_without_a_template_use_the_core_sections(): void
    {
        $this->tenant('fpc')->forceFill(['theme' => 'sample'])->save();
        $this->registerLegacyTheme();
        $this->tenant('fpd')->forceFill(['theme' => 'legacy'])->save();
        $this->enrolledOperator();

        // 見本のテーマの雛形（hero・rail・basic_text）に無い種類は断る
        $sample = (string) $this->create('fpc', 'news')->json('created.name');
        $this->send('PUT', "/api/tenants/fpc/themes/sample/pages/{$sample}/draft", ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'features', 'values' => []],
            ['type' => 'basic_image', 'values' => []],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['sections.0.type', 'sections.1.type']);
        // 無いページ・形の違う名前は 404
        $this->send('GET', '/api/tenants/fpc/themes/sample/pages/page-'.strtolower((string) str()->ulid()))->assertNotFound();
        $this->send('GET', '/api/tenants/fpc/themes/sample/pages/about')->assertNotFound();

        // pages.page を宣言しないテーマ（今の書き方のテスト用のテーマ）: 核の汎用の区画だけを置ける。テーマの区画（ヒーロー）は断る
        $default = (string) $this->create('fpd', 'contact')->json('created.name');
        $this->send('GET', "/api/tenants/fpd/themes/legacy/pages/{$default}")->assertOk()
            ->assertJsonPath('page.types', ['basic_text', 'basic_image_text', 'basic_image', 'basic_button', 'basic_products',
                'basic_testimonials', 'basic_faq', 'basic_features', 'basic_cta', 'basic_gallery', 'basic_product', 'basic_entries', 'basic_story',
                'basic_numbers', 'basic_logos', 'basic_steps', 'basic_table', 'basic_pricing', 'basic_spacer', 'basic_html', 'basic_richtext'])
            ->assertJsonPath('page.fixed', false)
            ->assertJsonPath('schema.basic_button.patterns', ['center' => '中央', 'left' => '左寄せ'])
            // 核の汎用の区画の見本（足した区画に入る、見本と分かる短い文。写真は空）
            ->assertJsonPath('schema.basic_button.example.values', ['label' => 'くわしく見る', 'link' => '/products'])
            ->assertJsonPath('schema.basic_image_text.example.values.image', null);
        $this->send('PUT', "/api/tenants/fpd/themes/legacy/pages/{$default}/draft", ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'hero', 'values' => []],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['sections.0.type']);
        $versionId = (string) $this->send('PUT', "/api/tenants/fpd/themes/legacy/pages/{$default}/draft", ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'basic_text', 'values' => ['heading' => 'お問い合わせ']],
            ['type' => 'basic_button', 'pattern' => 'left', 'values' => ['label' => 'メールで問い合わせる', 'link' => 'https://example.com/contact']],
        ]])->assertOk()->json('version.id');
        $this->send('POST', "/api/tenants/fpd/themes/legacy/pages/{$default}/publish", ['version_id' => $versionId, 'revision' => 1])->assertOk();

        // テーマに page の画面が無いので、核の既定の画面で描く（テーマの外枠の中に）
        $this->shop('fpd', '/pages/contact')->assertOk()
            ->assertSee('<h1 class="sugar-free-page__title" data-test="free-page-title">contact の題</h1>', false)
            ->assertSee('お問い合わせ')
            ->assertSee('<a class="sugar-section__button" href="https://example.com/contact">メールで問い合わせる</a>', false);
    }

    public function test_pages_of_a_preparing_shop_stay_behind_the_gate(): void
    {
        $this->tenant('fpe');
        $this->enrolledOperator();
        $id = (string) $this->create('fpe', 'about')->json('created.id');
        $this->send('PATCH', "/api/tenants/fpe/shop-pages/{$id}", ['status' => 'published'])->assertOk();
        $this->shop('fpe', '/pages/about')->assertOk();

        $this->inside('fpe', static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'cli:test'));
        $this->shop('fpe', '/pages/about')->assertRedirect('http://fpe.localhost/password');
        // 無いページも、ある・無いを見せずに同じく関所へ
        $this->shop('fpe', '/pages/nope')->assertRedirect('http://fpe.localhost/password');
        $this->assertSame(1, $this->inside('fpe', static fn (): int => ShopPage::query()->count()));
    }

    public function test_switching_themes_copies_the_basic_sections_warns_about_the_rest_and_switching_back_restores(): void
    {
        $this->tenant('fpf');
        $this->enrolledOperator();
        $about = (string) $this->create('fpf', 'about')->json('created.name');
        $empty = (string) $this->create('fpf', 'empty')->json('created.name');

        // 既定のテーマ（核の汎用の区画）で中身を作る。見本のテーマの雛形には basic_text しか置けない
        $this->send('PUT', "/api/tenants/fpf/themes/default/pages/{$about}/draft", ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'basic_text', 'values' => ['heading' => '店の歩み']],
            ['type' => 'basic_image', 'pattern' => 'narrow', 'values' => ['caption' => '工房']],
            ['type' => 'basic_button', 'values' => ['label' => '見る', 'link' => '/products']],
        ]])->assertOk();

        // 替える前の知らせ: 写せない区画のあるページだけ（中身を保存していないページは出ない）
        $this->send('GET', '/api/tenants/fpf/shop-settings/theme/impact?theme=sample')->assertOk()
            ->assertJsonCount(1, 'pages')
            ->assertJsonPath('pages.0.slug', 'about')
            ->assertJsonPath('pages.0.dropped', 2)
            ->assertJsonPath('pages.0.copied', 1);
        $this->send('GET', '/api/tenants/fpf/shop-settings/theme/impact?theme=default')->assertOk()->assertJsonCount(0, 'pages');

        // 替えると、写せる区画（見出しと文）だけが新しいテーマの下書きへ（ID と値はそのまま）。公開はしない
        $this->send('PUT', '/api/tenants/fpf/shop-settings/theme', ['theme' => 'sample'])->assertOk();
        $copied = $this->send('GET', "/api/tenants/fpf/themes/sample/pages/{$about}")->assertOk()
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.type', 'basic_text')
            ->assertJsonPath('sections.0.values.heading', '店の歩み')
            ->assertJsonPath('version.unpublished_changes', true);
        // 中身の無いページは写さない（新しいテーマの最初の並び）
        $this->send('GET', "/api/tenants/fpf/themes/sample/pages/{$empty}")->assertOk()->assertJsonPath('sections.0.type', 'hero');

        // 新しいテーマでテーマ固有の区画を足す。戻す前の知らせには、そのヒーローが写せない区画として出る
        $version = (string) $copied->json('version.id');
        $this->send('PUT', "/api/tenants/fpf/themes/sample/pages/{$about}/draft", ['version_id' => $version, 'revision' => (int) $copied->json('version.revision'), 'sections' => [
            ...$copied->json('sections'),
            ['type' => 'hero', 'values' => ['heading' => 'ようこそ']],
        ]])->assertOk();
        // 前のテーマには中身があるので、戻しても写さない（知らせも無い）
        $this->send('GET', '/api/tenants/fpf/shop-settings/theme/impact?theme=')->assertOk()->assertJsonCount(0, 'pages');

        // 前のテーマに戻すと、前の値（3 つの区画）がそのまま戻る
        $this->send('PUT', '/api/tenants/fpf/shop-settings/theme', ['theme' => null])->assertOk();
        $back = $this->send('GET', "/api/tenants/fpf/themes/default/pages/{$about}")->assertOk()->json('sections');
        $this->assertSame(['basic_text', 'basic_image', 'basic_button'], array_column($back, 'type'));

        // 記録にはページの名前だけ（文言は入れない）
        $events = $this->inside('fpf', fn (): array => DomainEvent::query()->where('type', 'theme.free_pages_copied')->pluck('payload')->all());
        $this->assertCount(1, $events);
        $this->assertSame([$about], $events[0]['pages']);
        $this->assertStringNotContainsString('店の歩み', (string) json_encode($events, JSON_UNESCAPED_UNICODE));
    }

    public function test_purging_a_trashed_page_frees_its_slot_and_forgets_its_content_in_every_theme(): void
    {
        $this->tenant('fpg')->forceFill(['theme' => 'sample'])->save();
        $this->enrolledOperator();
        $created = $this->create('fpg', 'about')->assertCreated();
        $id = (string) $created->json('created.id');
        $name = (string) $created->json('created.name');

        // 2 つのテーマに中身（片方は公開も）
        foreach (['sample', 'default'] as $theme) {
            $saved = $this->send('PUT', "/api/tenants/fpg/themes/{$theme}/pages/{$name}/draft", ['version_id' => null, 'revision' => 0, 'sections' => [
                ['type' => 'basic_text', 'values' => ['heading' => '消える見出し']],
            ]])->assertOk();
        }
        $this->send('POST', "/api/tenants/fpg/themes/default/pages/{$name}/publish", ['version_id' => $saved->json('version.id'), 'revision' => 1])->assertOk();

        // ゴミ箱でないページは完全に消せない
        $this->send('POST', "/api/tenants/fpg/shop-pages/{$id}/purge")->assertUnprocessable()->assertJsonValidationErrors(['page']);

        // 枠をいっぱいにする（ゴミ箱も数える）
        $this->inside('fpg', static function (): void {
            for ($i = 1; $i < ShopPage::MAX_PAGES; $i++) {
                DB::table('shop_pages')->insert(['id' => strtolower((string) str()->ulid()), 'slug' => "filler-{$i}", 'title' => 'x', 'position' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->send('DELETE', "/api/tenants/fpg/shop-pages/{$id}")->assertOk();
        $this->create('fpg', 'one-more')->assertUnprocessable()->assertJsonValidationErrors(['title']);

        $this->send('POST', "/api/tenants/fpg/shop-pages/{$id}/purge")->assertOk()->assertJsonCount(0, 'trash');
        $this->create('fpg', 'one-more')->assertCreated();

        // すべてのテーマ・バージョンの下書きと公開の値から消える。記録には slug だけ
        $this->inside('fpg', function () use ($id, $name): void {
            $this->assertNull(ShopPage::withTrashed()->find($id));
            $documents = DB::table('theme_versions')->get(['draft', 'published']);
            $this->assertCount(2, $documents);
            foreach ($documents as $row) {
                $this->assertStringNotContainsString($name, (string) $row->draft.(string) $row->published);
            }
            $purged = DomainEvent::query()->where('type', 'shop_page.purged')->firstOrFail();
            $this->assertSame(['slug' => 'about'], $purged->payload);
        });
    }

    /**
     * @return TestResponse<Response>
     */
    private function create(string $shop, string $slug, ?string $title = null): TestResponse
    {
        return $this->send('POST', "/api/tenants/{$shop}/shop-pages", ['title' => $title ?? "{$slug} の題", 'slug' => $slug]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $shop, string $pathOrUrl): TestResponse
    {
        $response = $this->get(str_starts_with($pathOrUrl, 'http') ? $pathOrUrl : "http://{$shop}.localhost{$pathOrUrl}");
        tenancy()->end();

        return $response;
    }
}
