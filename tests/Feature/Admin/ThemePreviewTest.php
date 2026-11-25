<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Models\Tenant;
use App\Storefront\Access\ThemePreviewLink;
use App\Storefront\Apps\ConsentCookie;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\RegistersSampleTheme;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * テーマの下書きのプレビュー（ADR 0039 区切り 2）。編集の画面の真ん中の枠に、署名つきの期限つきのリンクで下書きを開く。
 * 店の境界にかかるので、署名の書き換え・期限切れ・ほかの店・ほかのテーマ・準備中のショップ・枠に入れてよい親（管理画面だけ）まで確かめる
 */
final class ThemePreviewTest extends TestCase
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
        $this->tenant('tva')->forceFill(['theme' => 'sample'])->save();
        $this->tenant('tvb')->forceFill(['theme' => 'sample'])->save();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_preview_draws_the_draft_inside_the_admin_frame_only(): void
    {
        // ログインしていなければ・与えていない店では作れない（あるかどうかも見せない）
        $this->postJson('http://admin.localhost/api/tenants/tva/themes/sample/pages/home/preview')->assertUnauthorized();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $this->send('POST', '/api/tenants/tva/themes/sample/pages/home/preview')->assertNotFound()->assertJsonMissingPath('url');

        // 閲覧の段階から作れる。ほかの区画の並びを下書きに入れ、1 つ目だけを公開しておく
        app(OperatorAccess::class)->grant($staff, 'tva', 'operate', 'test');
        $saved = $this->send('PUT', '/api/tenants/tva/themes/sample/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [
            ['id' => 'hero', 'type' => 'hero', 'pattern' => 'full', 'values' => ['heading' => '公開した見出し']],
        ]])->assertOk();
        $this->send('POST', '/api/tenants/tva/themes/sample/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => 1])->assertOk();
        $this->send('PUT', '/api/tenants/tva/themes/sample/pages/home/draft', ['version_id' => $saved->json('version.id'), 'revision' => 2, 'sections' => [
            ['type' => 'rail', 'values' => ['title' => '下書きの並び']],
            ['id' => 'hero', 'type' => 'hero', 'pattern' => 'split', 'values' => ['heading' => 'まだ秘密の見出し']],
        ]])->assertOk();
        app(OperatorAccess::class)->grant($staff, 'tva', 'read', 'test');

        $response = $this->send('POST', '/api/tenants/tva/themes/sample/pages/home/preview')->assertOk();
        $url = (string) $response->json('url');
        $this->assertStringStartsWith(StorefrontUrls::base('tva.localhost').'/preview/themes/sample/home?expires=', $url);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), strtotime((string) $response->json('expires_at')), 5);
        $issued = AuditEvent::query()->where('type', 'operator.theme_preview_issued')->where('operator_id', $staff->id)->latest('sequence')->firstOrFail();
        $this->assertSame(['sample', 'home', 'tva'], [$issued->data['theme'], $issued->data['page'], $issued->tenant_id]);

        // 入っていないテーマ・無いページでは作れない
        $this->send('POST', '/api/tenants/tva/themes/nope/pages/home/preview')->assertNotFound();
        $this->send('POST', '/api/tenants/tva/themes/sample/pages/about/preview')->assertNotFound();

        // ふだんのショップは公開した中身のまま
        $this->page('tva', '/')->assertOk()->assertSee('公開した見出し')->assertDontSee('まだ秘密の見出し');

        // プレビューは下書きの並び（新着が先、ヒーローは左右）で描き、編集の印に管理画面のオリジンを置く
        $preview = $this->page('tva', $url)->assertOk()->assertSee('まだ秘密の見出し')->assertSee('下書きの並び')->assertDontSee('公開した見出し')
            ->assertSee('data-sugar-editor="http://admin.localhost"', false)
            ->assertDontSee('data-test="preview-banner"', false);
        $html = (string) $preview->getContent();
        $this->assertLessThan(strpos($html, 'data-pattern="split"'), strpos($html, 'data-test="rail"'));
        $this->assertSame('noindex, nofollow', $preview->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $preview->headers->get('Referrer-Policy'));
        // 枠に入れてよいのは管理画面のオリジンだけ。ほかの決まりは店頭の CSP のまま
        $csp = (string) $preview->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors http://admin.localhost;', $csp);
        $this->assertStringNotContainsString("frame-ancestors 'none'", $csp);
        $this->assertSame(str_replace("frame-ancestors 'none'", 'frame-ancestors http://admin.localhost', (string) config('storefront.csp')), $csp);
        $this->assertNull($preview->headers->get('X-Frame-Options'));

        // ふだんの画面は枠に入れさせないまま
        $shop = $this->page('tva', '/');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $shop->headers->get('Content-Security-Policy'));
        $this->assertSame('DENY', $shop->headers->get('X-Frame-Options'));
        $shop->assertDontSee('data-sugar-editor', false);
    }

    public function test_apps_are_not_injected_into_the_draft_preview(): void
    {
        $this->inside('tva', static function (): void {
            app(PluginStates::class)->set('tag-sample', true);
            app(PluginSettings::class)->update('tag-sample', ['tag_id' => 'TS-EDITOR1']);
        });

        $this->withCookie(ConsentCookie::NAME, 'analytics=1&ads=1');
        $content = (string) $this->page('tva', $this->issue('tva'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/apps/tag-sample/', $content);
        $this->assertStringNotContainsString('TS-EDITOR1', $content);
        $this->assertStringNotContainsString('consent-banner', $content);
    }

    public function test_a_changed_expired_or_borrowed_link_shows_nothing(): void
    {
        $url = $this->issue('tva');
        $query = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        $this->assertIsString($params['token'] ?? null);
        $this->assertIsString($params['expires'] ?? null);
        $token = $params['token'];
        $expires = $params['expires'];

        $refused = function (string $path, string $shop = 'tva'): void {
            $response = $this->page($shop, $path)->assertNotFound();
            $this->assertStringNotContainsString('data-sugar-editor', (string) $response->getContent(), $path);
            $this->assertStringNotContainsString('frame-ancestors http', (string) $response->headers->get('Content-Security-Policy'), $path);
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $path);
        };

        // 署名の書き換え・署名なし・期限を延ばす・形の違う値
        $changed = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $refused("/preview/themes/sample/home?expires={$expires}&token={$changed}");
        $refused('/preview/themes/sample/home');
        $refused('/preview/themes/sample/home?expires='.((int) $expires + 3600).'&token='.$token);
        $refused('/preview/themes/sample/home?expires[]=1&token[]=x');

        // ほかのテーマ・ほかのページへ広げない（同じ署名で）
        $refused("/preview/themes/default/home?{$query}");
        $refused("/preview/themes/sample/about?{$query}");

        // ほかの店で作った署名は、この店では開けない。この店の署名を、ほかの店のホストで使っても開けない
        $borrowed = ThemePreviewLink::query('tvb', 'sample', 'home', (int) $expires);
        $refused("/preview/themes/sample/home?expires={$borrowed['expires']}&token={$borrowed['token']}");
        $refused("/preview/themes/sample/home?{$query}", 'tvb');

        // 本物のリンクは開ける。期限（30 分）を過ぎたら開けない
        $this->page('tva', $url)->assertOk()->assertSee('data-sugar-editor', false);
        $this->travel(ThemePreviewLink::MINUTES + 1)->minutes();
        $refused("/preview/themes/sample/home?{$query}");
    }

    public function test_a_preparing_shop_opens_only_the_preview(): void
    {
        $this->inside('tva', static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'cli:test'));
        $url = $this->issue('tva');

        $response = $this->page('tva', $url)->assertOk()->assertSee('data-sugar-editor', false);
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertNull($response->getCookie('sugar_shop_pass'));
        $this->page('tva', '/')->assertRedirect('http://tva.localhost/password');

        // 署名の合わないプレビューは、関所と同じく「準備中です」へ（中身を出さない）
        $this->page('tva', '/preview/themes/sample/home?expires=9999999999&token='.str_repeat('0', 64))
            ->assertRedirect('http://tva.localhost/password')->assertDontSee('data-sugar-editor', false);
    }

    public function test_only_the_editor_shell_lets_a_granted_operator_frame_that_shop(): void
    {
        $frameSrc = fn (string $path): ?string => preg_match('/frame-src ([^;]+)/', (string) $this->send('GET', $path)->assertOk()->headers->get('Content-Security-Policy'), $m) === 1 ? $m[1] : null;

        // ログインしていない人には、店のアドレスも枠も許さない
        $this->assertNull($frameSrc('/tenants/tva/theme/pages/home'));

        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        // 与えていない店では許さない
        $this->assertNull($frameSrc('/tenants/tva/theme/pages/home'));

        app(OperatorAccess::class)->grant($staff, 'tva', 'read', 'test');
        $this->assertSame(StorefrontUrls::base('tva.localhost'), $frameSrc('/tenants/tva/theme/pages/home'));
        // ほかの画面・ほかの店の編集の画面では許さない
        $this->assertNull($frameSrc('/tenants/tva/theme'));
        $this->assertNull($frameSrc('/tenants/tva'));
        $this->assertNull($frameSrc('/tenants/tvb/theme/pages/home'));
    }

    public function test_the_frame_falls_back_to_deny_when_the_csp_cannot_carry_the_parent(): void
    {
        $url = $this->issue('tva');

        // 店頭の CSP の書き方が変わり、frame-ancestors 'none' を置き換えられないときは、許さずに 'none' と DENY に倒す
        config(['storefront.csp' => "default-src 'none'; script-src 'self'"]);
        $response = $this->page('tva', $url)->assertOk();
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringEndsWith("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('admin.localhost', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_editor_origin_is_normalized_and_https_only_in_production(): void
    {
        $cases = [
            'https://Admin.Example.com:443/' => 'https://admin.example.com',
            'http://admin.localhost:80' => 'http://admin.localhost',
            'http://admin.localhost:8080/path' => 'http://admin.localhost:8080',
            'https://admin.example.com:8443' => 'https://admin.example.com:8443',
            'javascript://admin.example.com' => null,
            'https://admin example.com' => null,
        ];

        foreach ($cases as $url => $expected) {
            config(['admin.url' => $url]);
            $this->assertSame($expected, ThemePreviewLink::editorOrigin(), $url);
        }

        // 本番では https だけ（http の管理画面にはプレビューを入れさせない）
        $this->app['env'] = 'production';
        config(['admin.url' => 'http://admin.example.com']);
        $this->assertNull(ThemePreviewLink::editorOrigin());
        config(['admin.url' => 'https://admin.example.com']);
        $this->assertSame('https://admin.example.com', ThemePreviewLink::editorOrigin());
        $this->app['env'] = 'testing';
    }

    private function issue(string $shop): string
    {
        $link = ThemePreviewLink::issue(Tenant::query()->findOrFail($shop), 'sample', 'home');
        $this->assertNotNull($link);

        return $link['url'];
    }

    /**
     * @return TestResponse<Response>
     */
    private function page(string $shop, string $pathOrUrl): TestResponse
    {
        $response = $this->get(str_starts_with($pathOrUrl, 'http') ? $pathOrUrl : "http://{$shop}.localhost{$pathOrUrl}");
        tenancy()->end();

        return $response;
    }
}
