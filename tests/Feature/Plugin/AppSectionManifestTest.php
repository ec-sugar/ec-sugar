<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\Manifest\ConsentKind;
use App\Core\Plugin\Manifest\CspSources;
use App\Core\Plugin\Manifest\InvalidAppManifestException;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Sections\AppSectionHandler;
use App\Core\Plugin\Sections\Embed;
use App\Core\Section\FieldType;
use App\Core\Section\InvalidSectionSchemaException;
use App\Core\Section\SectionSchema;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * App が区画を足す口（ADR 0040）のマニフェストの確かめ。区画の宣言はテーマの区画と同じ決まり（欄は核の 7 種類だけ・知らない書き方は断る）、
 * 同意の種類は必ず書き、外から埋め込む区画は同意を 1 つ以上と公表を持つ。枠とリンクの宛先は CSP の宛先と同じ形、sandbox と allow は核の決めた言葉だけ。
 * 種類の名前は app_ ＋ App の名前で、ほかの App と重ならず、テーマは app_ の名前を宣言できない
 */
final class AppSectionManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/sugar-app-sections-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir.'/resources/views/sections');
        File::put($this->dir.'/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_video_and_map_apps_pass_the_core_checks(): void
    {
        $registry = app(PluginRegistry::class);
        $video = $registry->get('video')->app()->sections['app_video'];
        $map = $registry->get('google-map')->app()->sections['app_google_map'];

        $this->assertSame('動画', $video->spec->label);
        $this->assertSame(['wide', 'narrow'], array_keys($video->spec->patterns));
        $this->assertSame(['wide' => 'video-wide', 'narrow' => 'video-narrow'], $video->spec->wireframes);
        // 選べる設定は核の選ぶ欄（決まった選択肢だけ）。再生の前の写真の欄は無い（最初から枠を出すので）
        $this->assertSame(['url', 'heading', 'caption', 'autoplay', 'mute', 'controls', 'loop'], array_keys($video->spec->fields));
        $this->assertSame(FieldType::Choice, $video->spec->fields['autoplay']->type);
        $this->assertSame(['show' => '出す', 'hide' => '出さない'], $video->spec->fields['controls']->options);
        // 動画も地図も同意の種類を持たない（誰にでも最初から枠。守りは CSP・sandbox・宛先、送る内容は公表）
        $this->assertSame([], $video->consent);
        $this->assertSame([], $registry->get('video')->app()->allConsent());
        $this->assertSame(['https://www.youtube-nocookie.com/embed/', 'https://player.vimeo.com/video/'], $video->embed?->frames);
        $this->assertSame(['autoplay', 'encrypted-media', 'fullscreen', 'picture-in-picture'], $video->embed->allow);
        $this->assertNotNull($video->handler);
        $this->assertNotNull($video->spec->check);
        $this->assertSame('動画', $video->title);

        $this->assertSame([], $map->consent);
        $this->assertNotSame([], $registry->get('google-map')->app()->disclosures);
        // 地図はキーの要らない埋め込み（ADR 0040 の 6 の置き換え）: 枠は /maps ちょうど、設定の項目は無い
        $this->assertSame(['https://www.google.com/maps'], $map->embed?->frames);
        $this->assertSame(['https://www.google.com/maps/search/'], $map->embed->links);
        $this->assertSame([], $registry->get('google-map')->app()->settings);
        // 区画だけの App はスクリプトを持たない（JavaScript を持ち込まない）
        $this->assertSame([], $registry->get('video')->app()->scripts);
        $this->assertSame([], $registry->get('google-map')->app()->scripts);
        // 入っている App の区画（お知らせ・ブログの新着は並べる区画〔ADR 0072〕、おすすめの区画の 3 つは外から埋め込まない区画。ADR 0071）
        $this->assertSame(['app_blog', 'app_google_map', 'app_recommendations_related', 'app_recommendations_recent', 'app_recommendations_ranking', 'app_video'], array_keys($registry->sections()));
        $this->assertNull($registry->sections()['app_recommendations_related']->embed);
        $this->assertSame([], $registry->get('recommendations')->app()->disclosures);
    }

    public function test_a_well_formed_section_is_read(): void
    {
        $app = AppManifest::fromArray($this->manifest(), $this->dir);
        $section = $app->sections['app_mine'];

        $this->assertSame('mine', $section->plugin);
        $this->assertSame(['url', 'heading'], array_keys($section->spec->fields));
        // 同意の種類は書かなくてよい（外から埋め込む区画も。誰にでも出す）
        $this->assertSame([], $section->consent);
        $this->assertSame(['allow-scripts', 'allow-same-origin'], $section->embed?->sandbox);
        $this->assertSame(['fullscreen'], $section->embed->allow);
        // 書けば、その種類に同意した人にだけ出す（同意の帯でも聞く）
        $gated = $this->manifest();
        $gated['sections']['app_mine']['consent'] = ['ads'];
        $gated = AppManifest::fromArray($gated, $this->dir);
        $this->assertSame([ConsentKind::Ads], $gated->sections['app_mine']->consent);
        $this->assertSame([ConsentKind::Analytics, ConsentKind::Ads], (new AppManifest(consent: [ConsentKind::Analytics], sections: $gated->sections))->allConsent());
        // 埋め込まない区画（自分の値だけで描く）
        $plain = AppManifest::fromArray(['name' => 'mine', 'sections' => ['app_mine_note' => ['label' => 'お知らせ', 'fields' => ['body' => ['type' => 'textarea', 'label' => '文']], 'consent' => []]]], $this->dir);
        $this->assertNull($plain->sections['app_mine_note']->embed);
    }

    /**
     * 断る形と、断る場所（道筋）
     */
    public function test_sections_are_refused_where_they_break_the_rules(): void
    {
        $with = fn (array $change): array => array_replace_recursive($this->manifest(), ['sections' => ['app_mine' => $change]]);
        $without = function (string $key): array {
            $manifest = $this->manifest();
            unset($manifest['sections']['app_mine'][$key]);

            return $manifest;
        };
        // 埋め込みの決まりは項目ごとに置き換える（並びは丸ごと）
        $embed = function (array $change): array {
            $manifest = $this->manifest();
            foreach ($change as $key => $value) {
                $manifest['sections']['app_mine']['embed'][$key] = $value;
            }

            return $manifest;
        };

        $this->refused(['name' => 'mine', 'sections' => [['label' => 'x']]], 'sections');
        $this->refused(['name' => 'mine', 'sections' => []], 'sections');
        // 種類の名前は app_ ＋ App の名前（ほかの App の名前・頭の無い名前・大文字は断る）
        foreach (['app_other', 'mine', 'basic_mine', 'app_mine-x', 'App_mine', 'app_mine_', 'app_minex'] as $type) {
            $manifest = $this->manifest();
            $manifest['sections'] = [$type => $manifest['sections']['app_mine']];
            $this->refused($manifest, 'sections');
        }
        // 同意の種類は核の決めたものだけ（「動画・地図の表示」embeds は 2026-10-30 に外した）
        $this->refused($with(['consent' => ['marketing']]), 'sections.app_mine.consent');
        $this->refused($with(['consent' => ['embeds']]), 'sections.app_mine.consent');
        $this->refused($with(['consent' => 'analytics']), 'sections.app_mine.consent');
        // 欄は核の 7 種類だけ・知らない書き方は断る（テーマの区画と同じ確かめ）
        $this->refused($with(['fields' => ['url' => ['type' => 'html']]]), 'sections.app_mine.fields.url.type');
        $this->refused($with(['fields' => ['url' => ['script' => 'alert(1)']]]), 'sections.app_mine.fields.url');
        $this->refused($with(['script' => 'embed.js']), 'sections.app_mine');
        $this->refused($with(['patterns' => ['wide' => ['label' => '横', 'wireframe' => 'my-own-svg']]]), 'sections.app_mine.patterns.wide.wireframe');
        // 埋め込みの決まり
        $this->refused($embed(['srcdoc' => '<b>x</b>']), 'sections.app_mine.embed');
        $this->refused($embed(['frame-src' => []]), 'sections.app_mine.embed.frame-src');
        foreach (["'unsafe-inline'", 'http://player.example.com', '*', 'https://*', 'data:', 'javascript:', 'https://127.0.0.1', 'https://localhost'] as $i => $source) {
            $this->refused($embed(['frame-src' => [$source]]), 'sections.app_mine.embed.frame-src.0');
        }
        $this->refused($embed(['links' => ['https://example.com', 'javascript:alert(1)']]), 'sections.app_mine.embed.links.1');
        foreach (['allow-top-navigation', 'allow-forms', 'allow-modals', 'allow-downloads', 'allow-top-navigation-by-user-activation'] as $token) {
            $this->refused($embed(['sandbox' => ['allow-scripts', $token]]), 'sections.app_mine.embed.sandbox');
        }
        foreach (['camera', 'microphone', 'geolocation', 'payment', 'usb', '*'] as $feature) {
            $this->refused($embed(['allow' => [$feature]]), 'sections.app_mine.embed.allow');
        }
        // 読み込む前のボタンの文字（button）は外した（押して読み込む形が無い）。書けば知らない書き方として断る
        $this->refused($embed(['button' => '再生']), 'sections.app_mine.embed');
        // 外から埋め込む App は公表を書く
        $manifest = $this->manifest();
        unset($manifest['disclosure']);
        $this->refused($manifest, 'disclosure');
    }

    /**
     * 登録簿: 外から埋め込む区画には係が要る・ほかの App と種類が重ならない・描く Blade の場所が要る
     */
    public function test_the_registry_needs_a_handler_unique_types_and_views(): void
    {
        $write = fn (string $dir, array $manifest): string => tap($dir.'/composer.json', static fn (string $path) => File::put($path, (string) json_encode(['extra' => ['ec-sugar' => $manifest]])));
        $base = ['type' => 'plugin', 'name' => 'mine', 'plugin_api' => 1, 'core' => '^1.0'];

        try {
            (new PluginRegistry)->register(new PluginDefinition('mine', '私の', '', 1, manifest: $write($this->dir, $base + $this->manifest())));
            $this->fail('係の無い埋め込みのセクションで登録できた');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sectionHandler', $e->getMessage());
        }

        $registry = new PluginRegistry;
        $registry->register(new PluginDefinition('mine', '私の', '', 1, manifest: $write($this->dir, $base + $this->manifest()), sectionHandler: $this->handler()));
        $this->assertSame('私の', $registry->sections()['app_mine']->title);
        $this->assertNotNull($registry->sections()['app_mine']->spec->check);

        // App「mine-x」の app_mine_x と、App「mine」の app_mine_x は重なる（名前の - は _ になる）
        $other = $this->dir.'/other';
        File::ensureDirectoryExists($other.'/resources/views');
        $manifest = $this->manifest();
        $manifest['name'] = 'mine-x';
        $manifest['sections'] = ['app_mine_x' => $manifest['sections']['app_mine']];
        $registry->register(new PluginDefinition('mine-x', '別の', '', 1, manifest: $write($other, ['type' => 'plugin', 'plugin_api' => 1] + $manifest), sectionHandler: $this->handler()));

        $third = $this->dir.'/third';
        File::ensureDirectoryExists($third.'/resources/views');
        $again = $this->manifest();
        $again['sections'] = ['app_mine_x' => $again['sections']['app_mine']];
        try {
            $fresh = new PluginRegistry;
            $fresh->register(new PluginDefinition('mine-x', '別の', '', 1, manifest: $write($other, ['type' => 'plugin', 'plugin_api' => 1] + $manifest), sectionHandler: $this->handler()));
            $fresh->register(new PluginDefinition('mine', '私の', '', 1, manifest: $write($third, $base + $again), sectionHandler: $this->handler()));
            $this->fail('ほかの App と重なる種類で登録できた');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('重なって', $e->getMessage());
        }

        // 描く Blade の場所（resources/views）が無い
        $bare = $this->dir.'/bare';
        File::ensureDirectoryExists($bare);
        try {
            (new PluginRegistry)->register(new PluginDefinition('mine', '私の', '', 1, manifest: $write($bare, $base + $this->manifest()), sectionHandler: $this->handler()));
            $this->fail('Blade の場所の無い App で登録できた');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('resources/views', $e->getMessage());
        }
    }

    public function test_a_theme_cannot_declare_an_app_section_name(): void
    {
        try {
            SectionSchema::fromManifest(['app_video' => ['label' => '動画', 'fields' => ['url' => ['type' => 'text', 'label' => 'URL']]]]);
            $this->fail('テーマが app_ の名前を宣言できた');
        } catch (InvalidSectionSchemaException $e) {
            $this->assertSame('sections.app_video', $e->path);
        }
    }

    /**
     * 枠とリンクの URL が宣言の宛先に合うか（核が描く前に確かめる）
     */
    public function test_urls_are_matched_against_the_declared_sources(): void
    {
        $sources = ['https://www.youtube-nocookie.com/embed/', 'https://*.example.com', 'https://maps.example.org/search'];

        foreach (['https://www.youtube-nocookie.com/embed/abcdefghijk?rel=0', 'https://a.example.com/x', 'https://a.b.example.com:443/', 'https://maps.example.org/search?q=1', 'https://WWW.YOUTUBE-NOCOOKIE.COM/embed/x'] as $ok) {
            $this->assertTrue(CspSources::matchesAny($sources, $ok), $ok);
        }

        foreach ([
            'https://www.youtube-nocookie.com/watch?v=x', 'https://www.youtube-nocookie.com/embedx', 'http://www.youtube-nocookie.com/embed/x',
            'https://example.com/', 'https://evilexample.com/', 'https://a.example.com:8443/', 'https://maps.example.org/search/more',
            'https://www.youtube-nocookie.com.evil.test/embed/x', 'https://user:pw@a.example.com/', 'javascript:alert(1)', 'https://a.example.com/"onload="x',
            'https://a.example.com/ x', '//a.example.com/', 'data:text/html,x', '',
            // 道筋の「..」「.」と符号化した点・斜線で、前方一致の宛先から外へ出る
            'https://www.youtube-nocookie.com/embed/../../url?q=x', 'https://www.youtube-nocookie.com/embed/./x', 'https://www.youtube-nocookie.com/embed/..',
            'https://www.youtube-nocookie.com/embed/%2e%2e/url', 'https://www.youtube-nocookie.com/embed/%2E%2E/url', 'https://www.youtube-nocookie.com/embed/.%2e/url',
            'https://www.youtube-nocookie.com/embed/x%2f..%2furl', 'https://www.youtube-nocookie.com/embed/x%5C..', 'https://a.example.com/a/../b',
        ] as $bad) {
            $this->assertFalse(CspSources::matchesAny($sources, $bad), $bad);
        }

        // 見直しで見つけた形: 前方一致の宛先から .. で別の道筋へ
        $this->assertFalse(CspSources::matchesAny(['https://www.google.com/maps/embed/v1/'], 'https://www.google.com/maps/embed/v1/../../../url?q=x'));
        // 点が区切りでない（名前の一部・値の中）なら通す
        $this->assertTrue(CspSources::matchesAny($sources, 'https://www.youtube-nocookie.com/embed/a..b.c?q=../x'));
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'name' => 'mine',
            'disclosure' => [['company' => '見本の会社', 'data' => 'IP アドレス', 'purpose' => '動画の表示', 'policy' => 'https://example.com/privacy']],
            'sections' => [
                'app_mine' => [
                    'label' => '見本の動画',
                    'patterns' => ['wide' => ['label' => '横いっぱい', 'wireframe' => 'video-wide']],
                    'fields' => [
                        'url' => ['type' => 'text', 'label' => 'URL', 'max' => 200],
                        'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 80],
                    ],
                    'embed' => [
                        'frame-src' => ['https://player.example.com/embed/'],
                        'links' => ['https://example.com'],
                        'sandbox' => ['allow-scripts', 'allow-same-origin'],
                        'allow' => ['fullscreen'],
                    ],
                ],
            ],
        ];
    }

    private function handler(): AppSectionHandler
    {
        return new class implements AppSectionHandler
        {
            public function problems(string $type, array $values): array
            {
                return [];
            }

            public function embed(string $type, array $values, array $settings): ?Embed
            {
                return null;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function refused(array $manifest, string $path): void
    {
        try {
            AppManifest::fromArray($manifest, $this->dir);
            $this->fail("通ってしまった（{$path} で断るはず）: ".json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        } catch (InvalidAppManifestException $e) {
            $this->assertSame($path, $e->path, $e->getMessage());
        }
    }
}
