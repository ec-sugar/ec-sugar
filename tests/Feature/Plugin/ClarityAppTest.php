<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Manifest\ConsentKind;
use App\Core\Plugin\Manifest\PageKind;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Apps\ConsentCookie;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「Microsoft Clarity」（plugins/plugin-clarity）。マニフェストが核の検査を通って登録され、
 * 店で使うにしてプロジェクト ID を入れ、お客さんが解析に同意すると、ふつうの画面にだけスクリプトと ID の印が出て、
 * その応答の CSP に Clarity の宛先が足される。形の違う ID は 422 で断る
 */
final class ClarityAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('clr');
        // 同意の帯を出す店（既定は出さない）。帯を出さない店の動きは ConsentBannerTest
        $this->inside('clr', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_manifest_passes_the_core_checks(): void
    {
        $app = app(PluginRegistry::class)->get('clarity')->app();

        $this->assertSame(['clarity.js'], array_map(static fn ($s): string => $s->file, $app->scripts));
        // 購入の完了の画面には入れない（録画に名前や住所を残さない）
        $this->assertSame([PageKind::General], $app->scripts[0]->pages);
        $this->assertSame([ConsentKind::Analytics], $app->consent);
        $this->assertSame(['project_id'], array_keys($app->settings));
        $this->assertTrue($app->settings['project_id']->required);
        $this->assertCount(1, $app->disclosures);
        $this->assertSame('https://privacy.microsoft.com/ja-jp/privacystatement', $app->disclosures[0]->policy);
    }

    public function test_the_tag_is_injected_with_the_project_id_after_consent(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);
        $this->send('PUT', '/api/tenants/clr/plugins/clarity', ['enabled' => true])->assertOk();

        // よくある失敗: 形の違う ID（トラッキングコードを丸ごと貼った・大文字・短すぎる・長すぎる）は断る
        foreach (['<script>(function(c,l,a,r,i,t,y){})</script>', '3T0WLOGVDZ', 'abc123', 'abcdefghijklm', '3t0wlogvdz"'] as $bad) {
            $this->send('PUT', '/api/tenants/clr/plugins/clarity/settings', ['values' => ['project_id' => $bad]])
                ->assertStatus(422)->assertJsonValidationErrors('values.project_id');
        }

        // ID が入るまでは、同意があっても差し込まない
        $this->assertStringNotContainsString('/apps/clarity/', (string) $this->shop('/', consent: true)->getContent());

        $this->send('PUT', '/api/tenants/clr/plugins/clarity/settings', ['values' => ['project_id' => '3t0wlogvdz']])->assertOk();
        $this->assertTrue($this->inside('clr', fn (): bool => app(PluginStates::class)->enabled('clarity')));

        $response = $this->shop('/', consent: true)->assertOk();
        $this->assertMatchesRegularExpression('~<script src="/apps/clarity/clarity\.js\?v=[0-9a-f]{12}" defer\s+data-sugar-app="clarity"\s+data-sugar-page="general"\s+data-setting-project-id="3t0wlogvdz"~', (string) $response->getContent());
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' https://*.clarity.ms", $csp);
        $this->assertStringContainsString('https://*.clarity.ms https://c.bing.com', $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);

        // 同意しなかったお客さんには差し込まず、CSP も元のまま
        $denied = $this->shop('/', consent: false);
        $this->assertStringNotContainsString('/apps/clarity/', (string) $denied->getContent());
        $this->assertSame(config('storefront.csp'), $denied->headers->get('Content-Security-Policy'));

        // スクリプトは同じオリジンから配る
        $this->shop('/apps/clarity/clarity.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $path, ?bool $consent = null): TestResponse
    {
        if ($consent !== null) {
            $this->withCookie(ConsentCookie::NAME, 'analytics='.($consent ? '1' : '0').'&ads=0');
        }

        $response = $this->get("http://clr.localhost{$path}");
        tenancy()->end();
        $this->defaultCookies = [];

        return $response;
    }
}
