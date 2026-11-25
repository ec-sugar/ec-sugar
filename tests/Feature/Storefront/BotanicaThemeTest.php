<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Media\Services\ShopMediaService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 公式テーマ 3（botanica）。テーマ API 2 の区画（theme.json）で描く。右の特集は写真のあるものだけ、行き先は店の人が決める
 */
final class BotanicaThemeTest extends TestCase
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

    public function test_botanica_registers_every_required_page_and_declares_its_sections(): void
    {
        $theme = app(ThemeRegistry::class)->get('botanica');
        $this->assertSame(2, $theme->themeApi);
        $this->assertSame('plugins/theme-botanica/resources/css/theme.css', $theme->stylesheet);
        $this->assertSame(['announcement', 'hero', 'looks', 'categories', 'new_arrivals', 'seasonal', 'favorites', 'promises'], array_keys($theme->schema()->sections));
        // 並びの形（ADR 0039 区切り 4）: トップの最初の並びは今までのトップと同じ順
        $this->assertSame(['announcement', 'hero', 'looks', 'categories', 'new_arrivals', 'seasonal', 'favorites', 'promises'], array_column($theme->schema()->page('home')->default ?? [], 'type'));
        $this->assertFalse($theme->schema()->page('home')?->fixed);
        foreach (['layout', 'home', 'page', 'products', 'product', 'cart', 'checkout', 'checkout-confirm', 'checkout-complete', 'order-lookup', 'order', 'legal', 'error'] as $page) {
            $this->assertTrue(view()->exists($theme->view($page)), $page);
        }
    }

    public function test_botanica_draws_published_sections_escapes_text_and_hides_empty_ones(): void
    {
        $this->tenant('bta')->forceFill(['theme' => 'botanica'])->save();

        // 何も入れていない店: 店の名前の見出しと既定の英字、写真の要る区画は出ない
        $html = $this->home('bta');
        $this->assertStringContainsString('A little more green.', $html);
        $this->assertStringContainsString('店 bta', $html);
        // 右の特集が無いので、ヒーローのすぐ後に特集の区画が来ない（CSS がヒーローを幅いっぱいにする。前の botanica-mosaic-single）
        $this->assertNotSame('looks', $this->nextSection($html, 'botanica-hero'));
        foreach (['announcement', 'hero-image', 'looks', 'seasonal', 'favorites', 'promises'] as $hidden) {
            $this->assertStringNotContainsString("data-test=\"{$hidden}\"", $html, $hidden);
        }

        $this->inside('bta', function (): void {
            $media = app(ShopMediaService::class);
            $photo = fn (?string $alt = null): string => $media->add($this->image(), $alt, 'test')->id;
            $schema = app(ThemeRegistry::class)->get('botanica')->schema();
            $service = app(ThemeContentService::class);
            $version = $service->saveDraft('botanica', $schema, null, 0, [
                'hero' => ['values' => ['kicker' => '<i>Green</i>', 'heading' => '<script>bad</script>', 'image' => $photo()]],
                'looks' => ['items' => [
                    ['title' => '葉のかたち', 'kicker' => 'Leaves', 'image' => $photo('大きな葉" onload="x'), 'link' => '/c/plants'],
                    ['title' => '写真の無い特集'],
                ]],
            ], 'test');
            $service->publish('botanica', $schema, $version->id, $version->revision, 'test');
        });

        $html = $this->home('bta');
        $this->assertStringContainsString('&lt;i&gt;Green&lt;/i&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>bad', $html);
        $this->assertStringContainsString('data-test="hero-image"', $html);
        // 写真のある特集だけ、店の人が決めた行き先へ。代わりの文字はエスケープ
        $this->assertStringContainsString('data-test="looks"', $html);
        $this->assertStringContainsString('href="/c/plants"', $html);
        $this->assertStringContainsString('alt="大きな葉&quot; onload=&quot;x"', $html);
        $this->assertStringNotContainsString('写真の無い特集', $html);
        // ヒーロー「写真と面」のすぐ後に右の特集「ヒーローの右に縦」が来て、CSS が左右に並べる
        $this->assertSame('looks', $this->nextSection($html, 'botanica-hero'));
        $this->assertStringContainsString('class="botanica-promos botanica-mosaic-side"', $html);
    }

    /**
     * C 案: ヒーローと右の特集は「写真」と「文字の面」に分け、文字を写真に重ねない。
     * 季節 2: 季節の特集の写真は文の側の高さに合わせて切り取る（写真が区画を伸ばさない）
     */
    public function test_botanica_home_keeps_text_off_the_photos(): void
    {
        $this->tenant('btc')->forceFill(['theme' => 'botanica'])->save();
        $this->inside('btc', function (): void {
            $media = app(ShopMediaService::class);
            $photo = fn (): string => $media->add($this->image(), null, 'test')->id;
            $schema = app(ThemeRegistry::class)->get('botanica')->schema();
            $service = app(ThemeContentService::class);
            $version = $service->saveDraft('botanica', $schema, null, 0, [
                'hero' => ['values' => ['heading' => "暮らしに、\n小さな緑を。", 'body' => '植物と過ごす毎日を。', 'image' => $photo()]],
                'looks' => ['items' => [
                    ['title' => '葉のかたち', 'image' => $photo()],
                    ['title' => '小さなサボテン', 'image' => $photo()],
                ]],
                'seasonal' => ['values' => ['heading' => '緑と暮らす', 'body' => '窓辺に、ひと鉢。', 'image' => $photo()]],
            ], 'test');
            $service->publish('botanica', $schema, $version->id, $version->revision, 'test');
        });

        $document = HTMLDocument::createFromString($this->home('btc'), LIBXML_NOERROR);

        // ヒーロー: 写真は写真の枠に、英字の一言・見出し・文・ボタンは写真の外の面に。写真に膜を掛けて文字を載せる形は使わない
        $hero = $this->element($document, '[data-test="botanica-hero"]');
        $heroMedia = $this->element($hero, '.botanica-hero-media');
        $heroPanel = $this->element($hero, '.botanica-hero-panel');
        $this->assertNotNull($heroMedia->querySelector('[data-test="hero-image"]'));
        foreach (['.botanica-kicker', '[data-test="hero-heading"]', '[data-test="hero-body"]', '.botanica-hero-link'] as $text) {
            $this->assertTrue($heroPanel->contains($this->element($hero, $text)), $text);
            $this->assertFalse($heroMedia->contains($this->element($hero, $text)), $text);
        }
        $this->assertFalse($heroPanel->contains($heroMedia));
        $this->assertNull($heroPanel->querySelector('img'));
        $this->assertNull($document->querySelector('.botanica-hero-shade'));

        // 右の特集: 2 つとも「写真｜文字の面」。題は写真の枠の外
        $promos = $document->querySelectorAll('[data-test="looks"] .botanica-promo');
        $this->assertCount(2, $promos);
        foreach ($promos as $promo) {
            $promoMedia = $this->element($promo, '.botanica-promo-media');
            $promoCopy = $this->element($promo, '.botanica-promo-copy');
            $this->assertNotNull($promoMedia->querySelector('img'));
            $this->assertNull($promoMedia->querySelector('h2'));
            $this->assertNotNull($promoCopy->querySelector('h2'));
            $this->assertNull($promoCopy->querySelector('img'));
        }

        // 季節の特集: 写真は写真の列の中で、高さの上限を持つ切り取りの class を付ける。文は別の列
        $seasonal = $this->element($document, '[data-test="seasonal"]');
        $seasonalPhoto = $this->element($seasonal, '.botanica-seasonal-media img');
        $this->assertTrue($seasonalPhoto->classList->contains('botanica-seasonal-photo'));
        $this->assertNull($this->element($seasonal, '.botanica-seasonal-copy')->querySelector('img'));
        $this->assertNotNull($this->element($seasonal, '.botanica-seasonal-copy')->querySelector('#seasonal-title'));
    }

    /** ヒーローに写真が無く特集が 1 つ: 写真の枠を出さず、文字の面だけ。特集は 1 つでも「写真｜文字の面」 */
    public function test_botanica_home_without_hero_photo_and_with_one_look(): void
    {
        $this->tenant('btd')->forceFill(['theme' => 'botanica'])->save();
        $this->inside('btd', function (): void {
            $schema = app(ThemeRegistry::class)->get('botanica')->schema();
            $service = app(ThemeContentService::class);
            $version = $service->saveDraft('botanica', $schema, null, 0, [
                'hero' => ['values' => ['heading' => '見出しだけ']],
                'looks' => ['items' => [['title' => 'ひとつだけ', 'image' => app(ShopMediaService::class)->add($this->image(), null, 'test')->id]]],
            ], 'test');
            $service->publish('botanica', $schema, $version->id, $version->revision, 'test');
        });

        $document = HTMLDocument::createFromString($this->home('btd'), LIBXML_NOERROR);
        $hero = $this->element($document, '[data-test="botanica-hero"]');
        $this->assertNull($hero->querySelector('.botanica-hero-media'));
        $this->assertFalse($hero->classList->contains('botanica-hero-photo'));
        $this->assertStringContainsString('見出しだけ', $this->element($hero, '.botanica-hero-panel')->textContent ?? '');
        $this->assertTrue($this->element($document, '[data-test="botanica-hero"] + [data-test="looks"]')->classList->contains('botanica-mosaic-side'));
        $this->assertCount(1, $document->querySelectorAll('[data-test="looks"] .botanica-promo .botanica-promo-media img'));
    }

    /**
     * 文字の面の色は theme.css のトークンから決め、白い文字との対比は 4.5:1 以上（明るい方のセージ #6d8266 では足りない）。
     * 季節の特集の写真は流れから外して切り取り（object-fit: cover）、高さは文の側が決める（下限 360px・写真の上限 560px）
     */
    public function test_botanica_text_panels_are_readable_and_the_seasonal_photo_does_not_stretch_the_section(): void
    {
        $css = (string) file_get_contents(base_path('plugins/theme-botanica/resources/css/theme.css'));
        preg_match_all('/--color-([a-z0-9-]+):\s*(#[0-9a-f]{6})\s*;/i', $css, $tokens);
        $colors = array_combine($tokens[1], $tokens[2]);

        $panel = $this->declarations($css, '.botanica-hero-panel');
        $this->assertSame('var(--color-hero-panel)', $panel['background'] ?? null);
        $this->assertSame('var(--color-on-hero-panel)', $panel['color'] ?? null);
        $this->assertArrayNotHasKey('position', $panel, '文字の面を写真の上に重ねない');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($colors['on-hero-panel'], $colors['hero-panel']));
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($colors['hero-panel-soft'], $colors['hero-panel']));
        $this->assertLessThan(4.5, $this->contrast('#ffffff', $colors['accent']), '確認の仕方が効いていること（明るい方のセージは足りない）');

        $promoCopy = $this->declarations($css, '.botanica-promo-copy');
        $this->assertSame('var(--color-promo-panel)', $promoCopy['background'] ?? null);
        $this->assertArrayNotHasKey('position', $promoCopy, '題を写真の上に重ねない');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($colors['ink'], $colors['promo-panel']));
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($colors['hero-panel'], $colors['promo-panel']));

        $section = $this->declarations($css, '.botanica-seasonal');
        $this->assertSame('360px', $section['min-height'] ?? null);
        $photo = $this->declarations($css, '.botanica-seasonal-photo');
        $this->assertSame('absolute', $photo['position'] ?? null, '写真がセクションの高さを決めない');
        $this->assertSame('cover', $photo['object-fit'] ?? null);
        $this->assertSame('560px', $photo['max-height'] ?? null);
    }

    /**
     * $test の区画のすぐ後の要素の data-test（無ければ null）
     */
    private function nextSection(string $html, string $test): ?string
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        return $this->element($document, "[data-test=\"{$test}\"]")->nextElementSibling?->getAttribute('data-test');
    }

    private function element(HTMLDocument|Element $root, string $selector): Element
    {
        $element = $root->querySelector($selector);
        $this->assertNotNull($element, $selector);

        return $element;
    }

    /**
     * 選び方がちょうど $selector の規則（画面の幅の条件の無いもの）の宣言。同じ選び方の規則が後にもあれば、後の値で上書きする（CSS と同じ）。
     * 幅の条件の中（@media）の規則と、子孫の選び方（`.a .b`）の規則は除く
     *
     * @return array<string, string>
     */
    private function declarations(string $css, string $selector): array
    {
        // 注記と @media の中身を外してから探す（@media の入れ子は 1 段だけ）
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $outside = (string) preg_replace('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css);
        preg_match_all('/(?:^|[{};])\s*'.preg_quote($selector, '/').'\s*\{([^{}]*)\}/', $outside, $rules);
        $this->assertNotEmpty($rules[1], $selector);
        $declarations = [];
        foreach ($rules[1] as $rule) {
            foreach (array_filter(array_map('trim', explode(';', $rule))) as $declaration) {
                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                $declarations[$property] = $value;
            }
        }

        return $declarations;
    }

    /** WCAG 2 の対比（相対輝度の比） */
    private function contrast(string $foreground, string $background): float
    {
        $luminance = static function (string $hex): float {
            $channel = static function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
        };
        [$light, $dark] = [max($luminance($foreground), $luminance($background)), min($luminance($foreground), $luminance($background))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    private function home(string $tenant): string
    {
        $html = (string) $this->get("http://{$tenant}.localhost/")->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }

    private function image(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bt');
        file_put_contents($path, $this->jpeg(800, 600));
        $this->files[] = $path;

        return $path;
    }
}
