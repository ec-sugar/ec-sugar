<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Section\SectionSchema;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeMedia;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ヒーローのボタンの文字と行き先。公式テーマ 4 つのヒーローの欄 button_label・button_link に入れて公開すると、
 * どのパターンのボタンにも出る。空なら今までのテーマごとの文字と行き先。行き先は核のリンクの欄の決まり
 * （店の中の道筋か https の URL だけ）で保存の時に断り、文字はエスケープして出す
 */
final class HeroButtonTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    /**
     * テーマ・空のときの文字・空のときの行き先（null は店の商品一覧の URL）
     *
     * @return array<string, array{string, string, string|null}>
     */
    public static function themes(): array
    {
        return [
            'default' => ['default', '商品一覧を見る', null],
            'kinari' => ['kinari', '新着を見る', '#new'],
            'fashion' => ['fashion', 'コレクションを見る', null],
            'botanica' => ['botanica', '植物を探す', null],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function themeNames(): array
    {
        return array_map(static fn (array $row): array => [$row[0]], self::themes());
    }

    #[DataProvider('themes')]
    public function test_the_hero_button_shows_the_shop_values_and_falls_back_when_empty(string $theme, string $label, ?string $link): void
    {
        $tenant = "hb{$theme[0]}";
        $this->tenant($tenant)->forceFill(['theme' => $theme])->save();
        $schema = app(ThemeRegistry::class)->get($theme)->schema();
        $hero = $schema->section('hero');
        $this->assertNotNull($hero);
        $patterns = array_keys($hero->patterns);

        // どのパターンにも、店の人の文字と行き先。文字のタグは文字のまま出る
        $values = ['button_label' => '<b>春</b>のセールへ', 'button_link' => 'https://example.com/sale?a=1&b=2'];
        $this->inside($tenant, fn () => $this->publish($theme, $schema, array_map(static fn (string $pattern): array => ['type' => 'hero', 'pattern' => $pattern, 'values' => $values], $patterns)));
        $html = $this->html("http://{$tenant}.localhost/");
        $buttons = $this->buttons($html, count($patterns));
        foreach ($buttons as $button) {
            $this->assertSame('<b>春</b>のセールへ', trim((string) $button->textContent));
            $this->assertSame('https://example.com/sale?a=1&b=2', $button->getAttribute('href'));
        }
        $this->assertStringNotContainsString('<b>春</b>', $html);

        // 店の中の道筋
        $this->inside($tenant, fn () => $this->publish($theme, $schema, [['type' => 'hero', 'values' => ['button_label' => '春の特集', 'button_link' => '/pages/spring']]]));
        [$button] = $this->buttons($this->html("http://{$tenant}.localhost/"), 1);
        $this->assertSame('春の特集', trim((string) $button->textContent));
        $this->assertSame('/pages/spring', $button->getAttribute('href'));

        // 空なら今までどおり（テーマごとの文字と行き先）
        $this->inside($tenant, fn () => $this->publish($theme, $schema, [['type' => 'hero', 'values' => ['button_label' => ' ', 'button_link' => null]]]));
        [$button] = $this->buttons($this->html("http://{$tenant}.localhost/"), 1);
        $this->assertSame($label, trim((string) $button->textContent));
        $this->assertSame($link ?? "http://{$tenant}.localhost/products", $button->getAttribute('href'));
    }

    /**
     * 危ない行き先（javascript:・data:・別のホストへの //・http）と長すぎる文字は、保存で断り、何も保存しない
     */
    #[DataProvider('themeNames')]
    public function test_unsafe_button_links_and_long_labels_are_refused_on_save(string $theme): void
    {
        $tenant = "hr{$theme[0]}";
        $this->tenant($tenant)->forceFill(['theme' => $theme])->save();
        $schema = app(ThemeRegistry::class)->get($theme)->schema();

        $refused = [
            ['sections.0.values.button_link', ['button_label' => '見る', 'button_link' => 'javascript:alert(1)']],
            ['sections.0.values.button_link', ['button_link' => 'data:text/html,<script>alert(1)</script>']],
            ['sections.0.values.button_link', ['button_link' => '//evil.example/x']],
            ['sections.0.values.button_link', ['button_link' => 'http://example.com/']],
            ['sections.0.values.button_link', ['button_link' => '/products" onclick="alert(1)']],
            ['sections.0.values.button_label', ['button_label' => str_repeat('あ', 21)]],
        ];

        $this->inside($tenant, function () use ($theme, $schema, $refused): void {
            $content = app(ThemeContentService::class);

            foreach ($refused as [$path, $values]) {
                try {
                    $content->savePage($theme, $schema, 'home', null, 0, [['type' => 'hero', 'values' => $values]], 'test');
                    $this->fail('保存してしまった: '.json_encode($values, JSON_UNESCAPED_UNICODE));
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey($path, $e->errors(), (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
                }
            }

            $this->assertNull($content->current($theme), '断ったときは何も保存しない');
        });
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function publish(string $theme, SectionSchema $schema, array $sections): void
    {
        $content = app(ThemeContentService::class);
        $current = $content->current($theme);
        $version = $content->savePage($theme, $schema, 'home', $current?->id, $current->revision ?? 0, $sections, 'test');
        $content->publish($theme, $schema, $version->id, $version->revision, 'test');
    }

    /**
     * @return list<Element>
     */
    private function buttons(string $html, int $count): array
    {
        $buttons = iterator_to_array(HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelectorAll('[data-test="hero-button"]'), false);
        $this->assertCount($count, $buttons);

        return $buttons;
    }

    private function html(string $url): string
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }
}
