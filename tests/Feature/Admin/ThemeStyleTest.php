<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\ThemePreviewLink;
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
 * 店の見た目（色の組・主な色・字の組・角の丸み。ADR 0097）: 管理画面で下書きに保存し、公開した値だけが小さな CSS でショップに出る。
 * 宣言に無い名前・形の違う色・CSS を壊す文字は 422 で何も保存しない。CSS の経路は公開した値だけ・印が合うときだけ・店ごと。
 * 下書きの CSS は署名つきの道筋だけ。読むのは閲覧、変えるのは運用
 */
final class ThemeStyleTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use RegistersSampleTheme;
    use WithTenants;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->registerSampleTheme();
        // 主な色を自由にしないテーマ（見本のテーマの宣言から accent だけを外したもの。画面は同じ）
        $manifest = json_decode((string) file_get_contents(base_path('tests/Support/Themes/sample/theme.json')), true);
        $this->assertIsArray($manifest);
        $manifest['style']['accent'] = false;
        $path = (string) tempnam(sys_get_temp_dir(), 'theme');
        file_put_contents($path, (string) json_encode($manifest));
        $this->files[] = $path;
        app(ThemeRegistry::class)->register(new ThemeDefinition('fixedaccent', '主な色を選べないテーマ', 2, 'theme-sample', 'x.css', manifest: $path));
        // 見た目を宣言しないテーマ
        app(ThemeRegistry::class)->register(new ThemeDefinition('nostyle', '見た目なし', 2, 'theme-sample', 'x.css', manifest: base_path('tests/Support/Themes/fixed/theme.json')));
        $this->tenant('tsa')->forceFill(['theme' => 'sample'])->save();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_the_published_style_reaches_the_shop_as_a_small_css_and_the_draft_does_not(): void
    {
        $this->enrolledOperator();

        // まだ何も選んでいない: 宣言と既定の値。ショップは CSS を読まず、外枠に既定の字と角の名前
        $this->send('GET', '/api/tenants/tsa/themes/sample/style')->assertOk()
            ->assertJsonPath('style.palettes.1.name', 'indigo')
            ->assertJsonPath('style.palettes.1.colors.accent', '#283593')
            ->assertJsonPath('style.accent', true)
            ->assertJsonPath('style.fonts.1', ['name' => 'mincho', 'label' => '明朝'])
            ->assertJsonPath('values', ['palette' => 'mono', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'])
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('version', null);
        $home = $this->shop('tsa');
        $this->assertStringNotContainsString('data-sugar-theme-style', $home);
        $this->assertStringContainsString('data-sugar-font="gothic" data-sugar-radius="square"', $home);

        // 下書きに保存する（最初のバージョンを作る）。主な色は小文字にそろえる。ショップはまだ変わらない
        $saved = $this->saveStyle('tsa', null, 0, ['palette' => 'indigo', 'accent' => '#1E3A8A', 'font' => 'mincho', 'radius' => 'soft'])->assertOk()
            ->assertJsonPath('values', ['palette' => 'indigo', 'accent' => '#1e3a8a', 'font' => 'mincho', 'radius' => 'soft'])
            ->assertJsonPath('version.revision', 1)
            ->assertJsonPath('version.unpublished_changes', true);
        $versionId = (string) $saved->json('version.id');
        $this->assertStringNotContainsString('data-sugar-theme-style', $this->shop('tsa'));

        // 記録には選んだ名前と「主な色を自分で決めた」だけ（色の値は残さない）
        $event = $this->inside('tsa', static fn () => DomainEvent::query()->where('type', 'theme.style_saved')->sole());
        // jsonb はキーの順を保たないので、順を問わずに比べる
        $this->assertEquals(['theme' => 'sample', 'revision' => 1, 'palette' => 'indigo', 'font' => 'mincho', 'radius' => 'soft', 'custom_accent' => true], $event->payload);

        // 公開すると（ページの公開と同じ口。バージョンごと）、ショップがテーマの CSS の後に小さな CSS を読み、外枠に選んだ字と角
        $this->send('POST', '/api/tenants/tsa/themes/sample/pages/home/publish', ['version_id' => $versionId, 'revision' => 1])->assertOk()
            ->assertJsonPath('version.unpublished_changes', false);
        $home = $this->shop('tsa');
        $this->assertStringContainsString('data-sugar-font="mincho" data-sugar-radius="soft"', $home);
        $this->assertSame(1, preg_match('#<link rel="stylesheet" href="(/theme/style-([0-9a-f]{16})\.css)" data-sugar-theme-style\s*>#', $home, $m));
        [, $href, $mark] = $m;

        $css = $this->get("http://tsa.localhost{$href}")->assertOk();
        tenancy()->end();
        $this->assertSame('text/css; charset=utf-8', $css->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=31536000', (string) $css->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $css->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $css->headers->get('Cache-Control'));
        $this->assertNull($css->headers->getCookies()[0] ?? null);
        // 中身は決まった形だけ（組の色・主な色・計算した色）
        $body = (string) $css->getContent();
        $this->assertMatchesRegularExpression('/\A:root\{(--color-[a-z][a-z0-9-]*:#[0-9a-f]{6};)+\}\n\z/', $body);
        $this->assertStringContainsString('--color-band:#f3f5fa;', $body);
        $this->assertStringContainsString('--color-sage-band:#e8ebf5;', $body);
        $this->assertStringContainsString('--color-accent:#1e3a8a;--color-on-accent:#ffffff;', $body);

        // 下書きを変えても、公開の CSS は前の値のまま（下書きは出ない）
        $this->saveStyle('tsa', $versionId, 2, ['palette' => 'sand', 'accent' => '#ffeb3b', 'font' => 'gothic', 'radius' => 'square'])->assertOk()
            ->assertJsonPath('version.unpublished_changes', true);
        $this->assertStringContainsString($href, $this->shop('tsa'));
        $this->assertStringNotContainsString('#ffeb3b', $this->css('tsa', $href)->assertOk()->getContent() ?: '');

        // 公開すると印が変わり URL が変わる。古い印は中身の無い 404（残さない）
        $this->send('POST', '/api/tenants/tsa/themes/sample/pages/home/publish', ['version_id' => $versionId, 'revision' => 3])->assertOk();
        $home = $this->shop('tsa');
        $this->assertSame(1, preg_match('#href="(/theme/style-([0-9a-f]{16})\.css)"#', $home, $next));
        $this->assertNotSame($mark, $next[2]);
        $this->assertStringContainsString('--color-accent:#ffeb3b;--color-on-accent:#000000;', (string) $this->css('tsa', $next[1])->assertOk()->getContent());
        $old = $this->css('tsa', $href)->assertNotFound();
        $this->assertSame('', $old->getContent());
        $this->assertStringContainsString('no-store', (string) $old->headers->get('Cache-Control'));
        $this->css('tsa', '/theme/style-'.str_repeat('0', 16).'.css')->assertNotFound();
        $this->css('tsa', '/theme/style-XYZ.css')->assertNotFound();

        // 組の主な色のままの既定（最初の組）に戻せば、ショップは CSS を読まない
        $this->saveStyle('tsa', $versionId, 4, ['palette' => 'mono', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'])->assertOk();
        $this->send('POST', '/api/tenants/tsa/themes/sample/pages/home/publish', ['version_id' => $versionId, 'revision' => 5])->assertOk();
        $this->assertStringNotContainsString('data-sugar-theme-style', $this->shop('tsa'));
    }

    public function test_bad_values_are_refused_and_nothing_is_saved(): void
    {
        $this->enrolledOperator();
        $saved = $this->saveStyle('tsa', null, 0, ['palette' => 'indigo', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'])->assertOk();
        $versionId = (string) $saved->json('version.id');
        $valid = ['palette' => 'indigo', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'];

        $cases = [
            'style.palette' => ['palette' => 'nope'] + $valid,
            'style.font' => ['font' => 'serif'] + $valid,
            'style.radius' => ['radius' => 'round'] + $valid,
        ];
        foreach (['#fff', 'red', '#12345g', '#123456;}', '#123456</style>', ';}body{display:none', '#1234567', 'url(x)'] as $i => $accent) {
            $cases["style.accent#{$i}"] = ['accent' => $accent] + $valid;
        }
        // 宣言の名前の形を真似た注入も、宣言に無いので断る
        $cases['style.palette#inject'] = ['palette' => 'indigo;}x{'] + $valid;

        foreach ($cases as $key => $style) {
            $this->saveStyle('tsa', $versionId, 1, $style)->assertUnprocessable()->assertJsonValidationErrors([explode('#', $key)[0]]);
        }

        // 形そのものが違う・知らない鍵
        $this->saveStyle('tsa', $versionId, 1, ['palette' => 'indigo', 'css' => ':root{}'])->assertUnprocessable()->assertJsonValidationErrors(['style']);
        $this->send('PUT', '/api/tenants/tsa/themes/sample/style/draft', ['version_id' => $versionId, 'revision' => 1, 'style' => 'indigo'])->assertUnprocessable();

        // 何も保存していない（改訂も下書きもそのまま）
        $version = $this->inside('tsa', static fn () => ThemeVersion::query()->sole());
        $this->assertSame(1, $version->revision);
        $this->assertEquals($valid, $version->draft['style']);
        $this->assertSame(1, $this->inside('tsa', static fn () => DomainEvent::query()->where('type', 'theme.style_saved')->count()));

        // 古い改訂は 409（同時の編集で上書きしない）
        $this->saveStyle('tsa', $versionId, 0, $valid)->assertStatus(409)->assertJsonPath('current_revision', 1);

        // 主な色を自由にしないテーマでは、主な色を断る（組からは選べる）
        $this->saveStyle('tsa', null, 0, ['accent' => '#123456'] + $valid, 'fixedaccent')->assertUnprocessable()->assertJsonValidationErrors(['style.accent']);
        $this->saveStyle('tsa', null, 0, $valid, 'fixedaccent')->assertOk()->assertJsonPath('style.accent', false);

        // 見た目を宣言しないテーマは、読むと null、保存は断る
        $this->send('GET', '/api/tenants/tsa/themes/nostyle/style')->assertOk()->assertJsonPath('style', null)->assertJsonPath('values', null);
        $this->saveStyle('tsa', null, 0, $valid, 'nostyle')->assertUnprocessable();
        $this->send('GET', '/api/tenants/tsa/themes/nope/style')->assertNotFound();
    }

    public function test_hard_to_read_colors_are_saved_with_a_warning(): void
    {
        $this->enrolledOperator();

        // 白い地に、ほとんど白の主な色（ボタンの縁が見えにくい）。保存は止めない
        $this->saveStyle('tsa', null, 0, ['palette' => 'mono', 'accent' => '#fafafa', 'font' => 'gothic', 'radius' => 'square'])->assertOk()
            ->assertJsonPath('values.accent', '#fafafa')
            ->assertJsonPath('warnings.0.code', 'accent_on_canvas');
        $this->send('GET', '/api/tenants/tsa/themes/sample/style')->assertOk()->assertJsonPath('warnings.0.code', 'accent_on_canvas');
    }

    public function test_names_gone_from_the_declaration_fall_back_to_the_default_and_the_db_refuses_broken_values(): void
    {
        $this->enrolledOperator();
        $saved = $this->saveStyle('tsa', null, 0, ['palette' => 'indigo', 'accent' => null, 'font' => 'mincho', 'radius' => 'soft'])->assertOk();
        $this->send('POST', '/api/tenants/tsa/themes/sample/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => 1])->assertOk();

        // テーマの更新で組と字が消えた（しまってある名前が宣言に無い）: 描くときに既定へ戻す（壊れない）
        $this->inside('tsa', static function (): void {
            DB::table('theme_versions')->update(['published' => DB::raw("jsonb_set(jsonb_set(published, '{style,palette}', '\"gone\"'), '{style,font}', '\"gone\"')")]);
        });
        $home = $this->shop('tsa');
        $this->assertStringNotContainsString('data-sugar-theme-style', $home);
        $this->assertStringContainsString('data-sugar-font="gothic" data-sugar-radius="soft"', $home);

        // アプリの確かめをすり抜けた値は DB が拒む（CSS に出る値の形）
        foreach (['{style,accent}' => '"</style>"', '{style,palette}' => '"a;b"', '{style,radius}' => '"Soft"', '{style,css}' => '"x"'] as $path => $value) {
            try {
                $this->inside('tsa', static fn () => DB::table('theme_versions')->update(['draft' => DB::raw("jsonb_set(draft, '{$path}', '{$value}')")]));
                $this->fail("DB が拒むはず: {$path}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('theme_versions_draft_style', $e->getMessage(), $path);
            }
        }
    }

    public function test_reading_is_for_read_level_and_changing_for_operate_level(): void
    {
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $this->send('GET', '/api/tenants/tsa/themes/sample/style')->assertNotFound();

        app(OperatorAccess::class)->grant($staff, 'tsa', 'read', 'test');
        $this->send('GET', '/api/tenants/tsa/themes/sample/style')->assertOk();
        $this->saveStyle('tsa', null, 0, ['palette' => 'indigo', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'])->assertForbidden();
        $this->assertNull($this->inside('tsa', static fn () => ThemeVersion::query()->first()));

        app(OperatorAccess::class)->grant($staff, 'tsa', 'operate', 'test');
        $this->saveStyle('tsa', null, 0, ['palette' => 'indigo', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'])->assertOk();
    }

    public function test_the_css_is_per_shop_and_the_draft_css_needs_the_signature(): void
    {
        $this->tenant('tsb')->forceFill(['theme' => 'sample'])->save();
        $this->enrolledOperator();
        $saved = $this->saveStyle('tsa', null, 0, ['palette' => 'indigo', 'accent' => '#1e3a8a', 'font' => 'mincho', 'radius' => 'soft'])->assertOk();
        $this->send('POST', '/api/tenants/tsa/themes/sample/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => 1])->assertOk();
        $this->assertSame(1, preg_match('#href="(/theme/style-[0-9a-f]{16}\.css)"#', $this->shop('tsa'), $m));

        // ほかの店のホストでは、この店の印で読めない（ほかの店の値を読む道は無い）。ほかの店は既定のまま
        $this->css('tsb', $m[1])->assertNotFound();
        $this->assertStringNotContainsString('data-sugar-theme-style', $this->shop('tsb'));

        // 下書きの CSS: 署名なし・書き換えた署名・ほかの店の署名・ほかのテーマへ広げるのは中身の無い 404（残さない・検索に載せない）
        $this->saveStyle('tsa', (string) $saved->json('version.id'), 2, ['palette' => 'sand', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'])->assertOk();
        $expires = now()->addMinutes(30)->getTimestamp();
        $path = ThemePreviewLink::stylePath('tsa', 'sample', $expires);
        $this->assertStringStartsWith('/preview/themes/sample/style.css?expires=', $path);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $token = (string) $query['token'];

        $refused = function (string $shop, string $path): void {
            $response = $this->css($shop, $path)->assertNotFound();
            $this->assertSame('', $response->getContent(), $path);
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $path);
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $path);
        };
        $refused('tsa', '/preview/themes/sample/style.css');
        $refused('tsa', "/preview/themes/sample/style.css?expires={$expires}&token=".substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a'));
        $refused('tsa', '/preview/themes/sample/style.css?expires='.($expires + 60)."&token={$token}");
        $refused('tsb', $path);
        $refused('tsa', str_replace('/sample/', '/fixedaccent/', $path));
        // 画面のプレビューの署名では開けない（用途で鍵を分ける）
        $page = ThemePreviewLink::query('tsa', 'sample', 'home', $expires);
        $refused('tsa', "/preview/themes/sample/style.css?expires={$page['expires']}&token={$page['token']}");

        // 本物の署名は、下書きの値の CSS を返す（残さない）
        $draft = $this->css('tsa', $path)->assertOk();
        $this->assertSame('text/css; charset=utf-8', $draft->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $draft->headers->get('Cache-Control'));
        $this->assertStringContainsString('--color-canvas:#f6f2ec;', (string) $draft->getContent());
        $this->assertStringContainsString('--color-accent:#66745d;', (string) $draft->getContent());

        // 下書きのプレビューの画面は、署名つきの下書きの CSS を印つきで置き、外枠に下書きの字と角
        $link = ThemePreviewLink::issue(Tenant::query()->findOrFail('tsa'), 'sample', 'home');
        $this->assertNotNull($link);
        $preview = (string) $this->get($link['url'])->assertOk()->getContent();
        tenancy()->end();
        $this->assertSame(1, preg_match('#<link rel="stylesheet" href="([^"]+)" data-sugar-theme-style\s+data-sugar-theme-style-preview\s*>#', $preview, $p));
        $this->assertStringStartsWith('/preview/themes/sample/style.css?expires=', html_entity_decode($p[1]));
        $this->assertStringContainsString('data-sugar-font="gothic" data-sugar-radius="square"', $preview);
        $this->css('tsa', html_entity_decode($p[1]))->assertOk();

        // 期限を過ぎたら開けない
        $this->travel(ThemePreviewLink::MINUTES + 1)->minutes();
        $refused('tsa', $path);
    }

    /**
     * @param  array<string, mixed>  $style
     * @return TestResponse<Response>
     */
    private function saveStyle(string $tenant, ?string $versionId, int $revision, array $style, string $theme = 'sample'): TestResponse
    {
        return $this->send('PUT', "/api/tenants/{$tenant}/themes/{$theme}/style/draft", ['version_id' => $versionId, 'revision' => $revision, 'style' => $style]);
    }

    private function shop(string $tenant): string
    {
        $html = (string) $this->get("http://{$tenant}.localhost/")->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }

    /**
     * @return TestResponse<Response>
     */
    private function css(string $tenant, string $path): TestResponse
    {
        $response = $this->get("http://{$tenant}.localhost{$path}");
        tenancy()->end();

        return $response;
    }
}
