<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Event\Models\DomainEvent;
use App\Core\Section\PageSpec;
use App\Core\Section\SectionHtml;
use App\Core\Section\Services\ShopPageService;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\ThemePreviewLink;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * カスタム区画: 「自由な HTML」（basic_html）と「見たまま書ける文」（basic_richtext）。
 * 店のスタッフが書いた HTML がお客さんの画面に出る境界なので、保存のとき・描くとき・下書きのプレビュー・試し見のどれも同じ整え方を通り、
 * 決まったタグと属性だけが出ることを確かめる。DB を直に書き換えても、描くときの整えで止まる
 */
final class CustomSectionsTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string ATTACK = '<p onclick="alert(1)">残る文<strong>太字</strong></p><script>alert(1)</script><img src="https://evil.example/a.png" onerror="alert(1)">'
        .'<a href="javascript:alert(1)">悪いリンク</a><a href="/products">良いリンク</a><iframe src="https://evil.example/"></iframe><div class="sugar-note evil" style="position:fixed">箱</div>';

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

    public function test_saving_cleans_the_html_and_the_shop_shows_only_what_remains(): void
    {
        $this->tenant('csa')->forceFill(['theme' => 'default'])->save();
        $schema = app(ThemeRegistry::class)->get('default')->schema();

        $stored = $this->inside('csa', function () use ($schema): array {
            $content = app(ThemeContentService::class);
            $version = $content->savePage('default', $schema, 'home', null, 0, [
                ['type' => 'basic_html', 'pattern' => 'wide', 'values' => ['html' => self::ATTACK]],
                ['type' => 'basic_richtext', 'pattern' => 'narrow', 'values' => ['body' => '<h2>見出し</h2><ul><li onmouseover="alert(1)">一つ目</li></ul>']],
            ], 'test');
            $content->publish('default', $schema, $version->id, $version->revision, 'test');

            return $content->publishedPage('default', $schema, 'home');
        });

        // しまったのは整えた形
        $html = $stored[0]['values']['html'];
        $this->assertSame('<p>残る文<strong>太字</strong></p><a rel="noopener noreferrer">悪いリンク</a><a href="/products" rel="noopener noreferrer">良いリンク</a><div class="sugar-note">箱</div>', $html);
        $this->assertSame('<h2>見出し</h2><ul><li>一つ目</li></ul>', $stored[1]['values']['body']);

        $page = $this->html('http://csa.localhost/');
        $document = HTMLDocument::createFromString($page, LIBXML_NOERROR);
        $custom = $document->querySelector('main [data-section-type="basic_html"]');
        $this->assertNotNull($custom);
        $this->assertSame('wide', $custom->getAttribute('data-pattern'));
        $this->assertSame('残る文太字', $custom->querySelector('.sugar-html p')?->textContent);
        $this->assertSame('/products', $custom->querySelector('.sugar-html a[href]')?->getAttribute('href'));
        $this->assertNotNull($document->querySelector('main [data-section-type="basic_richtext"][data-pattern="narrow"] .sugar-html ul li'));
        $this->assertNoScriptIn($custom->innerHTML.$document->querySelector('main [data-section-type="basic_richtext"]')?->innerHTML);
    }

    /**
     * 大きすぎる・深すぎる・文字でない値は保存で断る（道筋つき）
     *
     * @return array<string, array{mixed, string}>
     */
    public static function refusals(): array
    {
        return [
            '2 万字を越える' => ['<p>'.str_repeat('あ', SectionHtml::MAX_CHARS).'</p>', '20000 字まで'],
            // 整えると記号が文字の参照（&#34; など）になって長くなる。しまう形が 8 万字を越えるものは、保存で断る（描くときに黙って消えない）
            '" の連続でしまう形が 8 万字を越える' => [str_repeat('"', SectionHtml::MAX_CHARS), 'ショップに表示する形に変換すると 80000 字を超えます'],
            '全角の記号でしまう形が 8 万字を越える' => ['<p>'.str_repeat('お＋値＝', 4900).'</p>', 'ショップに表示する形に変換すると 80000 字を超えます'],
            '入れ子が深い' => [str_repeat('<div>', 21).'文', '入れ子が深すぎます'],
            '文字でない' => [['<p>文</p>'], '文字で入力してください'],
            '制御文字' => ["<p>a\x07b</p>", '制御文字'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_too_large_too_deep_or_broken_html_is_refused_on_save(mixed $value, string $message): void
    {
        $this->tenant('csr')->forceFill(['theme' => 'default'])->save();
        $schema = app(ThemeRegistry::class)->get('default')->schema();

        $this->inside('csr', function () use ($schema, $value, $message): void {
            try {
                app(ThemeContentService::class)->savePage('default', $schema, 'home', null, 0, [['type' => 'basic_html', 'values' => ['html' => $value]]], 'test');
                $this->fail('断る');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, (string) ($e->errors()['sections.0.values.html'][0] ?? ''));
            }

            $this->assertSame(0, ThemeVersion::query()->count());
        });
    }

    /**
     * 描くときにも整える: 保存の確かめをすり抜けた値（DB を直に書き換えた・決まりを後で狭めた）が公開の値に入っていても、ショップには出ない
     */
    public function test_the_shop_cleans_again_when_drawing_what_is_stored(): void
    {
        $this->tenant('csd')->forceFill(['theme' => 'kinari'])->save();
        $schema = app(ThemeRegistry::class)->get('kinari')->schema();

        $this->inside('csd', function () use ($schema): void {
            $content = app(ThemeContentService::class);
            $version = $content->savePage('kinari', $schema, 'home', null, 0, [
                ['id' => 'custom', 'type' => 'basic_html', 'values' => ['html' => '<p>前の文</p>']],
                ['id' => 'rich', 'type' => 'basic_richtext', 'values' => ['body' => '<p>前の文</p>']],
            ], 'test');
            $content->publish('kinari', $schema, $version->id, $version->revision, 'test');
            $this->tamper(self::ATTACK);
        });

        $page = $this->html('http://csd.localhost/');
        $document = HTMLDocument::createFromString($page, LIBXML_NOERROR);
        foreach (['custom', 'rich'] as $id) {
            $section = $document->querySelector("main [data-section=\"{$id}\"] .sugar-html");
            $this->assertNotNull($section, $id);
            $this->assertStringContainsString('残る文', (string) $section->textContent);
            $this->assertNoScriptIn($section->innerHTML);
            $this->assertNull($section->querySelector('img'));
            $this->assertNull($section->querySelector('[style]'));
            $this->assertSame('sugar-note', $section->querySelector('div')?->getAttribute('class'));
        }

        // 深すぎる値は何も出さない（ページは落ちない）
        $this->inside('csd', fn () => $this->tamper(str_repeat('<div>', 30).'深い'));
        $this->assertStringNotContainsString('深い', $this->html('http://csd.localhost/'));
    }

    /**
     * 編集の画面の下書きのプレビューと、「＋ 区画を足す」の試し見も同じ整え方を通る
     */
    public function test_the_draft_preview_and_the_trial_go_through_the_same_cleaning(): void
    {
        $tenant = $this->tenant('csp');
        $tenant->forceFill(['theme' => 'fashion'])->save();
        $schema = app(ThemeRegistry::class)->get('fashion')->schema();

        $this->inside('csp', function () use ($schema): void {
            app(ThemeContentService::class)->savePage('fashion', $schema, 'home', null, 0, [['id' => 'custom', 'type' => 'basic_html', 'values' => ['html' => '<p>下書き</p>']]], 'test');
            $this->tamper(self::ATTACK, 'draft');
        });

        $link = ThemePreviewLink::issue(Tenant::query()->findOrFail('csp'), 'fashion', 'home');
        $this->assertNotNull($link);
        $preview = HTMLDocument::createFromString($this->html($link['url']), LIBXML_NOERROR);
        $section = $preview->querySelector('main [data-section="custom"] .sugar-html');
        $this->assertNotNull($section);
        $this->assertStringContainsString('残る文', (string) $section->textContent);
        $this->assertNoScriptIn($section->innerHTML);

        // 試し見: 見本の値（整えた後の形）をそのまま描く。2 つのパターンのどちらも
        $path = ThemePreviewLink::trialPath('csp', 'fashion', 'home', now()->addMinutes(5)->getTimestamp());
        foreach (['narrow', 'wide'] as $pattern) {
            $fragment = $this->html("http://csp.localhost{$path}&type=basic_html&pattern={$pattern}");
            $this->assertStringContainsString('<div class="sugar-cols-2">', $fragment);
            $this->assertStringContainsString("data-pattern=\"{$pattern}\"", $fragment);
            $rich = $this->html("http://csp.localhost{$path}&type=basic_richtext&pattern={$pattern}");
            $this->assertStringContainsString('<ul><li>伝えたいことの 1 つ目</li>', $rich);
            $this->assertStringContainsString("data-pattern=\"{$pattern}\"", $rich);
        }
    }

    /**
     * 公式テーマ 4 つのトップと自由なページに置け、2 つのパターンで描ける（テーマの変数で見た目を合わせる核の部品）
     *
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        return ['default' => ['default'], 'kinari' => ['kinari'], 'fashion' => ['fashion'], 'botanica' => ['botanica']];
    }

    #[DataProvider('themes')]
    public function test_both_sections_draw_on_the_top_and_a_free_page_of_every_official_theme(string $theme): void
    {
        $tenant = "csf{$theme[0]}";
        $this->tenant($tenant)->forceFill(['theme' => $theme])->save();
        $schema = app(ThemeRegistry::class)->get($theme)->schema();
        $this->assertContains('basic_html', $schema->page('home')->types ?? []);
        $this->assertContains('basic_richtext', $schema->freePage()->types);

        $this->inside($tenant, function () use ($schema, $theme): void {
            $sections = [];
            foreach (['basic_html' => 'html', 'basic_richtext' => 'body'] as $type => $field) {
                foreach (['narrow', 'wide'] as $pattern) {
                    $sections[] = ['id' => "{$type}-{$pattern}", 'type' => $type, 'pattern' => $pattern, 'values' => [$field => "<h2>{$type} {$pattern}</h2><p class=\"sugar-lead\">文</p>"]];
                }
            }

            $content = app(ThemeContentService::class);
            $version = $content->savePage($theme, $schema, 'home', null, 0, $sections, 'test');
            $page = app(ShopPageService::class)->create(['title' => '私たちについて', 'slug' => 'about'], 'test');
            $version = $content->savePage($theme, $schema, PageSpec::freeName($page->id), $version->id, $version->revision, $sections, 'test');
            $content->publish($theme, $schema, $version->id, $version->revision, 'test');
            app(ShopPageService::class)->publish($page->id, 'test');
        });

        foreach (['/', '/pages/about'] as $path) {
            $document = HTMLDocument::createFromString($this->html("http://{$tenant}.localhost{$path}"), LIBXML_NOERROR);
            foreach (['basic_html', 'basic_richtext'] as $type) {
                foreach (['narrow', 'wide'] as $pattern) {
                    $section = $document->querySelectorAll("main [data-section=\"{$type}-{$pattern}\"]");
                    $this->assertCount(1, $section, "{$theme} {$path} {$type} {$pattern}");
                    $this->assertSame($pattern, $section->item(0)->getAttribute('data-pattern'));
                    $this->assertSame("{$type} {$pattern}", $section->item(0)->querySelector('.sugar-html h2')?->textContent);
                }
            }
        }
    }

    /**
     * 管理画面の確かめの API: 同じ決まりで整えた結果・外したもの・編集の箱を組む木を返す。何も書かない。ログインが要る
     */
    public function test_the_check_api_cleans_without_writing_anything(): void
    {
        $this->tenant('csc');
        $this->enrolledOperator();
        $before = $this->inside('csc', fn (): int => DomainEvent::query()->count());

        $response = $this->send('POST', '/api/tenants/csc/section-html', ['html' => self::ATTACK])->assertOk();
        $this->assertSame(SectionHtml::clean(self::ATTACK)->html, $response->json('html'));
        $removed = $response->json('removed');
        foreach (['<script>', '<iframe>', 'onclick の属性（スクリプト）', 'style の属性', 'class「evil」', 'リンク先「javascript:alert(1)」（ショップ内のページか、https:// で始まる URL だけ使えます）', '写真「https://evil.example/a.png」（ショップの「写真」にある写真だけ使えます）'] as $item) {
            $this->assertContains($item, $removed);
        }
        $this->assertSame('p', $response->json('tree.0.t'));
        $this->assertSame('残る文', $response->json('tree.0.c.0'));

        $this->send('POST', '/api/tenants/csc/section-html', ['html' => null])->assertOk()->assertJsonPath('html', '')->assertJsonPath('tree', []);
        $this->send('POST', '/api/tenants/csc/section-html', ['html' => str_repeat('<div>', 21)])->assertStatus(422)->assertJsonValidationErrors('html');
        $this->send('POST', '/api/tenants/csc/section-html', ['html' => ['x']])->assertStatus(422);
        $this->assertSame($before, $this->inside('csc', fn (): int => DomainEvent::query()->count()));
    }

    public function test_the_check_api_needs_a_signed_in_operator(): void
    {
        $this->tenant('csn');

        $this->send('POST', '/api/tenants/csn/section-html', ['html' => '<p>文</p>'])->assertUnauthorized();
    }

    /**
     * ページの下書きの API（管理画面の自動の保存）も同じ整え方を通り、答えは整えた形
     */
    public function test_the_page_api_stores_the_cleaned_html(): void
    {
        $this->tenant('csg')->forceFill(['theme' => 'botanica'])->save();
        $this->enrolledOperator();

        $this->send('PUT', '/api/tenants/csg/themes/botanica/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'basic_richtext', 'pattern' => 'narrow', 'values' => ['body' => '<p>文<b onclick="alert(1)">太</b></p><script>alert(1)</script>']],
        ]])->assertOk()->assertJsonPath('sections.0.values.body', '<p>文<b>太</b></p>');

        $this->send('PUT', '/api/tenants/csg/themes/botanica/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'basic_html', 'values' => ['html' => str_repeat('<b>', 30)]],
        ]])->assertStatus(422)->assertJsonValidationErrors('sections.0.values.html');
    }

    /**
     * 記号が多く、しまう形が打った形より長い HTML（" が &#34; に）: 保存でき、しまった形のまま保存し直せ、確かめの API も通り、
     * ショップにも出る（保存したのに描くときに黙って消える、が無い）
     */
    public function test_html_that_grows_when_cleaned_is_saved_again_and_drawn(): void
    {
        $this->tenant('csl')->forceFill(['theme' => 'fashion'])->save();
        $this->enrolledOperator();
        $typed = '<p>"引用"'.str_repeat('"', 15000).str_repeat('文', 4980).'</p>';
        $this->assertLessThanOrEqual(SectionHtml::MAX_CHARS, mb_strlen($typed));

        $put = fn (string $html, ?string $versionId, int $revision) => $this->send('PUT', '/api/tenants/csl/themes/fashion/pages/home/draft', [
            'version_id' => $versionId, 'revision' => $revision, 'sections' => [['id' => 'custom', 'type' => 'basic_html', 'values' => ['html' => $html]]],
        ]);
        $first = $put($typed, null, 0)->assertOk();
        $stored = (string) $first->json('sections.0.values.html');
        $this->assertGreaterThan(SectionHtml::MAX_CHARS, mb_strlen($stored));
        $this->assertLessThanOrEqual(SectionHtml::MAX_STORED_CHARS, mb_strlen($stored));

        // 管理画面はしまった形のまま送り返す（ほかの区画を直して保存するときも）
        $again = $put($stored, (string) $first->json('version.id'), 1)->assertOk();
        $this->assertSame($stored, $again->json('sections.0.values.html'));
        $this->send('POST', '/api/tenants/csl/section-html', ['html' => $stored])->assertOk()->assertJsonPath('html', $stored)->assertJsonPath('removed', []);

        $this->inside('csl', function (): void {
            $content = app(ThemeContentService::class);
            $version = ThemeVersion::query()->where('current', true)->firstOrFail();
            $content->publish('fashion', app(ThemeRegistry::class)->get('fashion')->schema(), $version->id, $version->revision, 'test');
        });
        $document = HTMLDocument::createFromString($this->html('http://csl.localhost/'), LIBXML_NOERROR);
        $this->assertStringContainsString('"引用"', (string) $document->querySelector('main [data-section="custom"] .sugar-html p')?->textContent);
    }

    /**
     * 使っているバージョンの公開の値（か下書き）にある核の区画の HTML を、確かめを通さずに直に書き換える（DB を直に書かれた場面）
     */
    private function tamper(string $html, string $column = 'published'): void
    {
        $version = ThemeVersion::query()->where('current', true)->firstOrFail();
        $document = $version->{$column};
        foreach ($document['pages']['home'] as $i => $section) {
            $field = $section['type'] === 'basic_html' ? 'html' : 'body';
            $document['pages']['home'][$i]['values'][$field] = $html;
        }

        ThemeVersion::query()->whereKey($version->id)->update([$column => json_encode($document)]);
    }

    private function assertNoScriptIn(string $html): void
    {
        foreach (['<script', 'onclick', 'onerror', 'onmouseover', 'javascript:', '<iframe', 'evil.example', 'style='] as $danger) {
            $this->assertStringNotContainsStringIgnoringCase($danger, $html);
        }
    }

    private function html(string $url): string
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }
}
