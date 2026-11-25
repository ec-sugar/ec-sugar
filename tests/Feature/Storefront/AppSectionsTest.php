<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Sections\AppSectionHandler;
use App\Core\Plugin\Sections\Embed;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\Models\ThemeVersion;
use App\Models\Tenant;
use App\Storefront\Access\ThemePreviewLink;
use App\Storefront\Apps\ConsentCookie;
use EcSugar\PluginGoogleMap\GoogleMapSections;
use EcSugar\PluginVideo\VideoSections;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App が足す区画（ADR 0040）と、動画（plugins/plugin-video）・地図（plugins/plugin-google-map）の App。
 * App を「使う」にした店でだけ区画を置け（区画を足す欄に出る）、止めた店では置けず・ショップに出さず・値は消さない。
 * 動画と地図は同意を待たずに、誰にでも最初から枠（iframe）を描く（2026-10-30 の置き換え）。守りは CSP・sandbox・allow・宛先の確かめ。
 * CSP の frame-src は、App の区画を出した画面にだけ、宣言の宛先だけを足す。URL は決まった形だけを受け、値は文字として出す
 */
final class AppSectionsTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string VIDEO = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('vid');
        $this->enrolledOperator();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_an_app_section_can_be_placed_only_where_the_app_is_used(): void
    {
        // 使っていない: 区画を足す欄に出ず、送っても置けない
        $page = $this->send('GET', '/api/tenants/vid/themes/default/pages/home')->assertOk();
        $this->assertNotContains('app_video', $page->json('page.types'));
        $this->assertArrayNotHasKey('app_video', $page->json('schema'));
        $sections = $page->json('sections');
        $at = count($sections);
        $this->save([...$sections, $this->video()], null, 0)->assertStatus(422)->assertJsonValidationErrors("sections.{$at}.type");

        // 使う: 欄に出て（App の名前つき）、置ける
        $this->use('video');
        $page = $this->send('GET', '/api/tenants/vid/themes/default/pages/home')->assertOk();
        $this->assertContains('app_video', $page->json('page.types'));
        $this->assertSame('動画', $page->json('schema.app_video.app'));
        $this->assertSame(['wide' => 'video-wide', 'narrow' => 'video-narrow'], $page->json('schema.app_video.wireframes'));

        // URL の形: 別のホスト・javascript:・形の違う ID は、App の係が断る（道筋つきの 422）
        foreach (['https://evil.example/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)', 'https://www.youtube.com/watch?v=short', 'https://www.youtube.com.evil.test/watch?v=dQw4w9WgXcQ', 'http://www.youtube.com/watch?v=dQw4w9WgXcQ'] as $url) {
            $this->save([...$sections, $this->video(['url' => $url])], null, 0)->assertStatus(422)->assertJsonValidationErrors("sections.{$at}.values.url");
        }

        $this->save([...$sections, $this->video()], null, 0)->assertOk()
            ->assertJsonPath("sections.{$at}.type", 'app_video')
            ->assertJsonPath("sections.{$at}.values.url", self::VIDEO);
    }

    public function test_the_frame_is_drawn_from_the_start_for_everyone(): void
    {
        $this->use('video');
        $this->publishHome([$this->video(['heading' => '<script>alert("x")</script>', 'caption' => '"><img src=x onerror=alert(1)>'])]);

        // 同意を決めていない・断った・前の「embeds=1」の Cookie・解析と広告だけ同意、のどれでも同じ: 最初から枠（自動で再生しない URL）。
        // sandbox・allow は宣言の言葉だけ（自動で再生しないので autoplay は付けない）、送り元はドメインまで、遅れて読む
        foreach ([null, 'analytics=0&ads=0', 'embeds=1', 'embeds=0', 'analytics=1&ads=1'] as $consent) {
            $response = $this->shop('/', $consent)->assertOk();
            $html = (string) $response->getContent();
            $this->assertMatchesRegularExpression('~<iframe class="sugar-embed__frame" src="https://www\.youtube-nocookie\.com/embed/dQw4w9WgXcQ\?rel=0&amp;playsinline=1" title="動画: &lt;script&gt;[^"]*"\s+sandbox="allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox" allow="encrypted-media; fullscreen; picture-in-picture"\s+referrerpolicy="strict-origin" loading="lazy"~', $html, (string) $consent);
            $this->assertSame(1, substr_count($html, '<iframe'), (string) $consent);
            // CSP は、この画面にだけ宣言の宛先を足す
            $this->assertSame(['frame-src' => ['https://www.youtube-nocookie.com/embed/', 'https://player.vimeo.com/video/']], $this->added((string) $response->headers->get('Content-Security-Policy')), (string) $consent);
        }

        $html = (string) $this->shop('/', null)->getContent();
        // 押して読み込む形は無い（ボタンも、核の JS の印も）。同意の帯も「動画・地図の表示」を聞かない（区画の App だけの店では帯を出さない）
        $this->assertStringNotContainsString('data-sugar-embed', $html);
        $this->assertStringNotContainsString('動画を再生', $html);
        $this->assertStringNotContainsString('動画・地図の表示', $html);
        $this->assertStringNotContainsString('data-sugar-consent ', $html);
        // 外のサイトで開くリンク（宣言の links に合うもの）
        $this->assertStringContainsString('href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', $html);
        // 店の人の文は文字として出す
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert("x")', $html);
        $this->assertStringNotContainsString('<img src=x', $html);

        // 区画の無い画面には足さない
        $this->assertSame(config('storefront.csp'), $this->shop('/products', null)->headers->get('Content-Security-Policy'));
        // 公表のページには載る（表示のときに送られる形の文）
        $this->shop('/legal/external', null)->assertOk()->assertSee('Google LLC（YouTube）')->assertSee('動画のセクションのあるページを表示したとき');
    }

    /**
     * 動画の区画の選べる設定（自動で再生する・音を消す・操作の表示を出さない・繰り返す）。URL は確かめた ID と決まった値だけから組み立てる
     *
     * @return array<string, array{string, array<string, string>, string, string}>
     */
    public static function videoOptions(): array
    {
        $yt = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $vimeo = 'https://vimeo.com/76979871/8e1f2b3c4d';

        return [
            'YouTube 何も選ばない' => [$yt, [], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1', 'encrypted-media fullscreen picture-in-picture'],
            'YouTube しないを選ぶ' => [$yt, ['autoplay' => 'off', 'mute' => 'off', 'controls' => 'show', 'loop' => 'off'], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1', 'encrypted-media fullscreen picture-in-picture'],
            'YouTube 自動で再生（音を消すが付く）' => [$yt, ['autoplay' => 'on'], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1&autoplay=1&mute=1', 'autoplay encrypted-media fullscreen picture-in-picture'],
            'YouTube 自動で再生で音を消さないを選んでも消す' => [$yt, ['autoplay' => 'on', 'mute' => 'off'], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1&autoplay=1&mute=1', 'autoplay encrypted-media fullscreen picture-in-picture'],
            'YouTube 音を消すだけ' => [$yt, ['mute' => 'on'], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1&mute=1', 'encrypted-media fullscreen picture-in-picture'],
            'YouTube 操作の表示を出さない' => [$yt, ['controls' => 'hide'], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1&controls=0', 'encrypted-media fullscreen picture-in-picture'],
            'YouTube 繰り返す（playlist に同じ ID）' => [$yt, ['loop' => 'on'], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1&loop=1&playlist=dQw4w9WgXcQ', 'encrypted-media fullscreen picture-in-picture'],
            'YouTube 全部' => [$yt, ['autoplay' => 'on', 'mute' => 'on', 'controls' => 'hide', 'loop' => 'on'], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1&autoplay=1&mute=1&controls=0&loop=1&playlist=dQw4w9WgXcQ', 'autoplay encrypted-media fullscreen picture-in-picture'],
            'Vimeo 何も選ばない' => [$vimeo, [], 'https://player.vimeo.com/video/76979871?dnt=1&h=8e1f2b3c4d', 'encrypted-media fullscreen picture-in-picture'],
            'Vimeo 自動で再生（muted が付く）' => [$vimeo, ['autoplay' => 'on'], 'https://player.vimeo.com/video/76979871?dnt=1&h=8e1f2b3c4d&autoplay=1&muted=1', 'autoplay encrypted-media fullscreen picture-in-picture'],
            'Vimeo 音を消すだけ' => [$vimeo, ['mute' => 'on'], 'https://player.vimeo.com/video/76979871?dnt=1&h=8e1f2b3c4d&muted=1', 'encrypted-media fullscreen picture-in-picture'],
            'Vimeo 全部' => [$vimeo, ['autoplay' => 'on', 'mute' => 'on', 'controls' => 'hide', 'loop' => 'on'], 'https://player.vimeo.com/video/76979871?dnt=1&h=8e1f2b3c4d&autoplay=1&muted=1&controls=0&loop=1', 'autoplay encrypted-media fullscreen picture-in-picture'],
        ];
    }

    /**
     * @param  array<string, string>  $options
     */
    #[DataProvider('videoOptions')]
    public function test_video_options_become_fixed_url_values(string $url, array $options, string $src, string $allow): void
    {
        $embed = (new VideoSections)->embed('app_video', ['url' => $url] + $options, []);
        $this->assertNotNull($embed);
        $this->assertSame($src, $embed->src);
        $rules = app(PluginRegistry::class)->get('video')->app()->sections['app_video']->embed;
        $this->assertNotNull($rules);
        $this->assertTrue($rules->allowsFrame($embed->src));
        // 枠の allow は宣言の中から（核が重なりだけを使う。宣言の順）
        $this->assertSame($allow, implode(' ', array_values(array_intersect($rules->allow, $embed->allow ?? $rules->allow))));
    }

    public function test_video_options_are_saved_drawn_and_unknown_values_are_refused(): void
    {
        $this->use('video');
        $page = $this->send('GET', '/api/tenants/vid/themes/default/pages/home')->assertOk();
        $this->assertSame(['off' => 'しない', 'on' => 'する'], $page->json('schema.app_video.fields.autoplay.options'));
        $sections = $page->json('sections');
        $at = count($sections);

        // 選択肢に無い値・URL を入れようとする値は断る（道筋つきの 422）
        foreach (['autoplay' => 'yes', 'mute' => '1', 'controls' => '0', 'loop' => 'on&autoplay=1'] as $field => $value) {
            $this->save([...$sections, $this->video([$field => $value])], null, 0)->assertStatus(422)->assertJsonValidationErrors("sections.{$at}.values.{$field}");
        }

        // 自動で再生して繰り返す: 音を消すが付き、allow に autoplay が付く（保存した値は変えない）
        $saved = $this->save([...$sections, $this->video(['autoplay' => 'on', 'loop' => 'on'])], null, 0)->assertOk()
            ->assertJsonPath("sections.{$at}.values.autoplay", 'on')
            ->assertJsonPath("sections.{$at}.values.mute", null);
        $this->send('POST', '/api/tenants/vid/themes/default/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => $saved->json('version.revision')])->assertOk();
        $html = (string) $this->shop('/', null)->getContent();
        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&amp;playsinline=1&amp;autoplay=1&amp;mute=1&amp;loop=1&amp;playlist=dQw4w9WgXcQ"', $html);
        $this->assertStringContainsString('allow="autoplay; encrypted-media; fullscreen; picture-in-picture"', $html);
    }

    public function test_a_stopped_app_hides_its_sections_but_keeps_their_values(): void
    {
        $this->use('video');
        $this->publishHome([$this->video()]);
        $this->assertStringContainsString('data-section-type="app_video"', (string) $this->shop('/', null)->getContent());

        // 止める: ショップに出さず、CSP にも足さない。編集の画面にも出さない
        $this->inside('vid', fn () => app(PluginStates::class)->set('video', false, 'test'));
        $off = $this->shop('/', null);
        $this->assertStringNotContainsString('app_video', (string) $off->getContent());
        $this->assertSame(config('storefront.csp'), $off->headers->get('Content-Security-Policy'));
        $page = $this->send('GET', '/api/tenants/vid/themes/default/pages/home')->assertOk();
        $this->assertNotContains('app_video', array_column($page->json('sections'), 'type'));

        // 止めている間にほかの区画を保存し直し・公開しても、値は消えない（元の位置に残る）
        $order = $this->stored('draft');
        $position = array_search('app_video', $order, true);
        $this->assertIsInt($position);
        $sections = $page->json('sections');
        $sections[0]['hidden'] = true;
        $saved = $this->save($sections, $page->json('version.id'), $page->json('version.revision'))->assertOk();
        $this->send('POST', '/api/tenants/vid/themes/default/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => $saved->json('version.revision')])->assertOk();
        $this->assertSame($order, $this->stored('draft'));
        $this->assertSame($order, $this->stored('published'));

        // 使い直すと、元の位置に元の値で出る
        $this->use('video');
        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', (string) $this->shop('/', null)->getContent());
    }

    public function test_the_editor_preview_never_frames_and_the_trial_needs_the_app(): void
    {
        $this->use('video');
        $this->publishHome([$this->video()]);

        $link = ThemePreviewLink::issue(Tenant::query()->findOrFail('vid'), 'default', 'home');
        $this->assertNotNull($link);
        $preview = $this->shopUrl($link['url'])->assertOk();
        $html = (string) $preview->getContent();
        $this->assertStringContainsString('data-section-type="app_video"', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('は、ショップではここに表示されます', $html);
        $this->assertStringNotContainsString('frame-src https://www.youtube-nocookie.com', (string) $preview->headers->get('Content-Security-Policy'));

        // 試し見の断片: 見本の値（URL は空）で「入れると出ます」の箱。止めた App の区画は試せない（中身の無い 404）
        $trial = ThemePreviewLink::trialPath('vid', 'default', 'home', now()->addMinutes(ThemePreviewLink::MINUTES)->getTimestamp()).'&type=app_video&pattern=narrow';
        $this->shopUrl("http://vid.localhost{$trial}")->assertOk()->assertSee('項目を入力するとここに表示されます')->assertDontSee('<iframe', false);
        $this->inside('vid', fn () => app(PluginStates::class)->set('video', false, 'test'));
        $this->shopUrl("http://vid.localhost{$trial}")->assertNotFound();
    }

    /**
     * 地図はキーの要らない埋め込み（ADR 0040 の 6 の置き換え）。使う店なら、場所を文字で入れるだけで出る（設定の項目は無い）
     */
    public function test_the_map_needs_only_a_place_in_words(): void
    {
        // 使っていない店: 公開してあってもショップに出ない
        $page = $this->send('GET', '/api/tenants/vid/themes/default/pages/home')->assertOk();
        $this->assertNotContains('app_google_map', $page->json('page.types'));
        $this->use('google-map');
        $page = $this->send('GET', '/api/tenants/vid/themes/default/pages/home')->assertOk();
        $sections = $page->json('sections');
        $at = count($sections);
        $map = static fn (string $place): array => ['type' => 'app_google_map', 'pattern' => 'split', 'values' => ['place' => $place, 'heading' => 'アクセス']];

        // 地図の URL を貼る: 文字で入れるよう断る
        foreach (['https://maps.app.goo.gl/abc', 'javascript:alert(1)', 'www.google.com/maps'] as $place) {
            $this->save([...$sections, $map($place)], null, 0)->assertStatus(422)->assertJsonValidationErrors("sections.{$at}.values.place");
        }

        $saved = $this->save([...$sections, $map('東京都千代田区丸の内1-9-1 "東京駅"')], null, 0)->assertOk();
        $this->send('POST', '/api/tenants/vid/themes/default/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => $saved->json('version.revision')])->assertOk();

        // 設定（キー）を何も入れなくても、同意を待たずに最初から枠が出る
        $response = $this->shop('/', null);
        $html = (string) $response->getContent();
        $this->assertStringContainsString('data-section-type="app_google_map"', $html);
        $this->assertSame(1, substr_count($html, '<iframe'));
        // 場所は URL の値として符号化して渡す（引用符・空白・& で URL の形を壊せない）。キーは載せない。外で開くリンクは Maps URLs の検索
        $query = rawurlencode('東京都千代田区丸の内1-9-1 "東京駅"');
        $this->assertStringContainsString('src="https://www.google.com/maps?q='.$query.'&amp;output=embed&amp;hl=ja"', $html);
        $this->assertStringNotContainsString('key=', $html);
        $this->assertStringContainsString('href="https://www.google.com/maps/search/?api=1&amp;query='.$query.'"', $html);
        // CSP の frame-src には宣言の宛先（/maps ちょうど）だけを足す
        $this->assertSame(['frame-src' => ['https://www.google.com/maps']], $this->added((string) $response->headers->get('Content-Security-Policy')));
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"', $html);
        $this->assertStringContainsString('data-layout="split"', $html);

        // 場所に & や # を入れても、値の中に閉じ込める（output=embed を外したり、別の値を足したりできない）
        $this->assertSame(
            'https://www.google.com/maps?q=a%26output%3Dsvembed%23x&output=embed&hl=ja',
            (new GoogleMapSections)->embed('app_google_map', ['place' => 'a&output=svembed#x'], [])?->src,
        );
        // 場所が空・URL なら作らない（ショップに出さない）
        $this->assertNull((new GoogleMapSections)->embed('app_google_map', ['place' => '  '], []));
        $this->assertNull((new GoogleMapSections)->embed('app_google_map', ['place' => 'https://maps.app.goo.gl/abc'], []));
    }

    /**
     * 地図の枠の宛先は www.google.com の /maps ちょうどだけ（似せたホスト・別の道筋は核が通さない）
     *
     * @return array<string, array{string, bool}>
     */
    public static function mapFrames(): array
    {
        return [
            '今の形' => ['https://www.google.com/maps?q=%E6%9D%B1%E4%BA%AC%E9%A7%85&output=embed&hl=ja', true],
            'Maps Embed API（前の形）' => ['https://www.google.com/maps/embed/v1/place?key=x&q=a', false],
            '別の道筋' => ['https://www.google.com/maps/search/?api=1&query=a', false],
            '道筋の後ろに足す' => ['https://www.google.com/maps/@35.6,139.7,15z', false],
            'ほかの道筋' => ['https://www.google.com/url?q=https://evil.example/', false],
            '道筋の前に足す' => ['https://www.google.com/x/maps?q=a', false],
            '似せたホスト' => ['https://www.google.com.evil.test/maps?q=a&output=embed', false],
            '前に足したホスト' => ['https://evilwww.google.com/maps?q=a&output=embed', false],
            'サブドメイン' => ['https://maps.google.com/maps?q=a&output=embed', false],
            '別の国のドメイン' => ['https://www.google.co.jp/maps?q=a&output=embed', false],
            'http' => ['http://www.google.com/maps?q=a&output=embed', false],
            'ポートつき' => ['https://www.google.com:8443/maps?q=a&output=embed', false],
            '利用者名つき' => ['https://evil@www.google.com/maps?q=a&output=embed', false],
        ];
    }

    #[DataProvider('mapFrames')]
    public function test_the_map_frame_is_only_www_google_com_maps(string $url, bool $ok): void
    {
        $embed = app(PluginRegistry::class)->get('google-map')->app()->sections['app_google_map']->embed;
        $this->assertNotNull($embed);
        $this->assertSame($ok, $embed->allowsFrame($url));
    }

    /**
     * App の係が宣言に無い宛先の URL を返しても、核は枠を描かない（区画ごと出さない）。宣言に無いリンクは出さない
     */
    public function test_urls_outside_the_declaration_are_dropped_by_the_core(): void
    {
        $dir = sys_get_temp_dir().'/sugar-rogue-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir.'/resources/views/sections');
        File::put($dir.'/resources/views/sections/app_rogue.blade.php', '<section data-section-type="app_rogue"><x-storefront.embed :section="$section" /></section>');
        File::put($dir.'/composer.json', (string) json_encode(['extra' => ['ec-sugar' => [
            'type' => 'plugin', 'name' => 'rogue', 'plugin_api' => 1,
            'disclosure' => [['company' => '見本', 'data' => 'IP アドレス', 'purpose' => '表示', 'policy' => 'https://example.com/privacy']],
            'sections' => ['app_rogue' => [
                'label' => '見本', 'fields' => ['mode' => ['type' => 'text', 'label' => '形', 'max' => 20]],
                'embed' => ['frame-src' => ['https://player.example.com/embed/'], 'links' => ['https://example.com/watch'], 'sandbox' => [], 'allow' => ['fullscreen']],
            ]],
        ]]]));

        try {
            app(PluginRegistry::class)->register(new PluginDefinition('rogue', '見本', '', 1, manifest: $dir.'/composer.json', sectionHandler: new class implements AppSectionHandler
            {
                public function problems(string $type, array $values): array
                {
                    return [];
                }

                public function embed(string $type, array $values, array $settings): ?Embed
                {
                    return match ($values['mode'] ?? null) {
                        'frame' => new Embed('https://evil.example/embed/x', '見本'),
                        'dots' => new Embed('https://player.example.com/embed/../../steal', '見本'),
                        // 宣言に無い allow（カメラ・自動の再生）は落とす。宣言より広げられない
                        'allow' => new Embed('https://player.example.com/embed/x', '見本', null, ['camera', 'autoplay', 'fullscreen']),
                        'link' => new Embed('https://player.example.com/embed/x', '見本', 'javascript:alert(1)'),
                        // 値が読めない: 何も埋め込まない
                        'none' => null,
                        default => new Embed('https://player.example.com/embed/x', '見本', 'https://example.com/watch?v=x'),
                    };
                }
            }));
            $this->use('rogue');

            foreach (['frame' => false, 'dots' => false, 'none' => false, 'link' => true, 'allow' => true, 'ok' => true] as $mode => $shown) {
                $this->publishHome([['id' => "rogue-{$mode}", 'type' => 'app_rogue', 'values' => ['mode' => $mode]]], replace: true);
                $html = (string) $this->shop('/', null)->getContent();
                $this->assertSame($shown, str_contains($html, 'data-section-type="app_rogue"'), $mode);
                $this->assertStringNotContainsString('evil', $html, $mode);
                $this->assertStringNotContainsString('steal', $html, $mode);
                $this->assertStringNotContainsString('javascript:', $html, $mode);
                $this->assertStringNotContainsString('camera', $html, $mode);
            }

            $this->publishHome([['id' => 'rogue-allow', 'type' => 'app_rogue', 'values' => ['mode' => 'allow']]], replace: true);
            $this->assertStringContainsString('allow="fullscreen"', (string) $this->shop('/', null)->getContent());
            $this->publishHome([['id' => 'rogue-ok', 'type' => 'app_rogue', 'values' => ['mode' => 'ok']]], replace: true);

            $this->assertStringContainsString('href="https://example.com/watch?v=x"', (string) $this->shop('/', null)->getContent());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * 同意の種類を書いた区画（動画・地図は書かない）は、全部に同意したお客さんにだけ出す。ほかの人には区画も枠も CSP の宛先も出さない
     */
    public function test_a_section_that_declares_consent_waits_for_consent_only_in_a_shop_that_shows_the_banner(): void
    {
        $dir = sys_get_temp_dir().'/sugar-gated-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir.'/resources/views/sections');
        File::put($dir.'/resources/views/sections/app_gated.blade.php', '<section data-section-type="app_gated"><x-storefront.embed :section="$section" /></section>');
        File::put($dir.'/composer.json', (string) json_encode(['extra' => ['ec-sugar' => [
            'type' => 'plugin', 'name' => 'gated', 'plugin_api' => 1,
            'disclosure' => [['company' => '見本', 'data' => 'IP アドレス', 'purpose' => '計測', 'policy' => 'https://example.com/privacy']],
            'sections' => ['app_gated' => [
                'label' => '見本', 'fields' => ['mode' => ['type' => 'text', 'label' => '形', 'max' => 20]], 'consent' => ['analytics'],
                'embed' => ['frame-src' => ['https://player.example.com/embed/'], 'links' => [], 'sandbox' => [], 'allow' => []],
            ]],
        ]]]));

        try {
            app(PluginRegistry::class)->register(new PluginDefinition('gated', '見本', '', 1, manifest: $dir.'/composer.json', sectionHandler: new class implements AppSectionHandler
            {
                public function problems(string $type, array $values): array
                {
                    return [];
                }

                public function embed(string $type, array $values, array $settings): ?Embed
                {
                    return ($values['mode'] ?? null) === 'x' ? new Embed('https://player.example.com/embed/x', '見本') : null;
                }
            }));
            $this->use('gated');
            $this->publishHome([['id' => 'gated', 'type' => 'app_gated', 'values' => ['mode' => 'x']]], replace: true);

            // 同意の帯を出さない店（既定）: 同意の種類を書いた区画も、誰にでも出す（前に断った Cookie も見ない）。帯も聞かない
            foreach ([null, 'analytics=0'] as $consent) {
                $response = $this->shop('/', $consent);
                $this->assertStringContainsString('src="https://player.example.com/embed/x"', (string) $response->getContent(), (string) $consent);
                $this->assertStringNotContainsString('consent-banner', (string) $response->getContent(), (string) $consent);
                $this->assertSame(['frame-src' => ['https://player.example.com/embed/']], $this->added((string) $response->headers->get('Content-Security-Policy')));
            }
            $this->assertStringContainsString('data-test="consent-empty"', (string) $this->shop('/consent', null)->getContent());

            // 同意の帯を出す店: 今までどおり、全部に同意したお客さんにだけ
            $this->inside('vid', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));

            foreach ([null, 'analytics=0', 'ads=1'] as $consent) {
                $response = $this->shop('/', $consent);
                $this->assertStringNotContainsString('app_gated', (string) $response->getContent(), (string) $consent);
                $this->assertStringNotContainsString('player.example.com', (string) $response->headers->get('Content-Security-Policy'), (string) $consent);
            }

            // 同意の帯はこの区画の種類（アクセスの解析）を聞く
            $this->assertStringContainsString('アクセス解析', (string) $this->shop('/consent', null)->getContent());

            $response = $this->shop('/', 'analytics=1');
            $this->assertStringContainsString('src="https://player.example.com/embed/x"', (string) $response->getContent());
            $this->assertSame(['frame-src' => ['https://player.example.com/embed/']], $this->added((string) $response->headers->get('Content-Security-Policy')));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function videoUrls(): array
    {
        return [
            'YouTube の見る画面' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube:dQw4w9WgXcQ'],
            'ほかの値つき' => ['https://www.youtube.com/watch?si=abc&v=dQw4w9WgXcQ&t=42s', 'youtube:dQw4w9WgXcQ'],
            'スマホ' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube:dQw4w9WgXcQ'],
            '短い URL' => ['https://youtu.be/dQw4w9WgXcQ?si=xyz', 'youtube:dQw4w9WgXcQ'],
            'ショート' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'youtube:dQw4w9WgXcQ'],
            '埋め込みの URL' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube:dQw4w9WgXcQ'],
            'Vimeo' => ['https://vimeo.com/76979871', 'vimeo:76979871'],
            'Vimeo の限定公開' => ['https://vimeo.com/76979871/8e1f2b3c4d', 'vimeo:76979871:8e1f2b3c4d'],
            'Vimeo の埋め込み' => ['https://player.vimeo.com/video/76979871?h=8e1f2b3c4d', 'vimeo:76979871:8e1f2b3c4d'],
            '別のホスト' => ['https://evil.example/watch?v=dQw4w9WgXcQ', null],
            '似せたホスト' => ['https://www.youtube.com.evil.test/watch?v=dQw4w9WgXcQ', null],
            'http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ', null],
            'javascript:' => ['javascript:alert(1)', null],
            'data:' => ['data:text/html,<script>alert(1)</script>', null],
            '利用者名つき' => ['https://user@www.youtube.com/watch?v=dQw4w9WgXcQ', null],
            'ポートつき' => ['https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ', null],
            'ID が短い' => ['https://www.youtube.com/watch?v=dQw4w9WgXc', null],
            'ID に引用符' => ['https://youtu.be/dQw4w9WgX"Q', null],
            'v が並び' => ['https://www.youtube.com/watch?v[]=dQw4w9WgXcQ', null],
            'チャンネル' => ['https://www.youtube.com/@channel', null],
            'Vimeo の番号でない' => ['https://vimeo.com/channels/staffpicks', null],
            'Vimeo の 0' => ['https://vimeo.com/0', null],
            '空白' => ['https://youtu.be/dQw4w9WgXcQ onload', null],
        ];
    }

    #[DataProvider('videoUrls')]
    public function test_only_fixed_forms_of_video_urls_are_accepted(string $url, ?string $expected): void
    {
        $video = VideoSections::parse($url);
        $this->assertSame($expected, $video === null ? null : implode(':', array_filter([$video['service'], $video['id'], $video['hash']])));
        $this->assertSame($expected === null, (new VideoSections)->problems('app_video', ['url' => $url]) !== []);
        $this->assertSame([], (new GoogleMapSections)->problems('app_google_map', ['place' => '東京駅']));
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function video(array $values = []): array
    {
        return ['type' => 'app_video', 'pattern' => 'wide', 'values' => $values + ['url' => self::VIDEO, 'heading' => '作っているところ']];
    }

    private function use(string $plugin): void
    {
        $this->inside('vid', fn () => app(PluginStates::class)->set($plugin, true, 'test'));
    }

    /**
     * トップの今の並びの後ろに足して（$replace なら置き換えて）公開する
     *
     * @param  list<array<string, mixed>>  $add
     */
    private function publishHome(array $add, bool $replace = false): void
    {
        $page = $this->send('GET', '/api/tenants/vid/themes/default/pages/home')->assertOk();
        $saved = $this->save($replace ? $add : [...$page->json('sections'), ...$add], $page->json('version.id'), (int) ($page->json('version.revision') ?? 0))->assertOk();
        $this->send('POST', '/api/tenants/vid/themes/default/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => $saved->json('version.revision')])->assertOk();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return TestResponse<Response>
     */
    private function save(array $sections, ?string $versionId, int $revision): TestResponse
    {
        return $this->send('PUT', '/api/tenants/vid/themes/default/pages/home/draft', ['version_id' => $versionId, 'revision' => $revision, 'sections' => $sections]);
    }

    /**
     * しまってあるトップの並びの種類（読み直さない）
     *
     * @return list<string>
     */
    private function stored(string $which): array
    {
        return $this->inside('vid', fn (): array => array_column(ThemeVersion::query()->where('theme', 'default')->where('current', true)->firstOrFail()->{$which}['pages']['home'], 'type'));
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $path, ?string $consent): TestResponse
    {
        if ($consent !== null) {
            $this->withCookie(ConsentCookie::NAME, $consent);
        }

        $response = $this->shopUrl("http://vid.localhost{$path}");
        $this->defaultCookies = [];

        return $response;
    }

    /**
     * @return TestResponse<Response>
     */
    private function shopUrl(string $url): TestResponse
    {
        $response = $this->get($url);
        tenancy()->end();

        return $response;
    }

    /**
     * ショップの CSP に足した宛先（元の CSP に無いものだけ）
     *
     * @return array<string, list<string>>
     */
    private function added(string $csp): array
    {
        $base = $this->directives((string) config('storefront.csp'));
        $out = [];
        foreach ($this->directives($csp) as $name => $sources) {
            $extra = array_values(array_diff($sources, $base[$name] ?? []));
            if ($extra !== []) {
                $out[$name] = $extra;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function directives(string $csp): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $csp))) as $part) {
            $words = preg_split('/\s+/', $part) ?: [];
            $out[(string) array_shift($words)] = $words;
        }

        return $out;
    }
}
