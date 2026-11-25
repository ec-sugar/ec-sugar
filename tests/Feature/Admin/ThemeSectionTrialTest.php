<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Core\Section\Services\ShopPageService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\ThemePreviewLink;
use App\Storefront\View\ShopPhoto;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\RegistersSampleTheme;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 区画の試し見の断片（ADR 0039）。編集の画面の「＋ 区画を足す」で指した種類とパターンを、下書きのプレビューの中に見本の値で仮に差し込む。
 * 店の境界にかかるので、署名の書き換え・期限切れ・ほかの店・ほかのテーマ・ほかのページ・画面のリンクの署名の流用・置けない種類・知らないパターンを断り、
 * DB に何も書かないこと（下書き・イベント・控え・記録）まで確かめる
 */
final class ThemeSectionTrialTest extends TestCase
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
        app(ThemeRegistry::class)->register(new ThemeDefinition('fixed', '決まった並びのテーマ', 2, 'theme-sample', 'x.css', manifest: base_path('tests/Support/Themes/fixed/theme.json')));
        $this->tenant('tsa')->forceFill(['theme' => 'sample'])->save();
        $this->tenant('tsb')->forceFill(['theme' => 'sample'])->save();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_preview_carries_a_trial_path_that_draws_one_section_with_the_example_values(): void
    {
        // 下書きのプレビューの画面に、同じオリジンの断片の道筋を置く（ふだんの画面には置かない）
        $html = (string) $this->page('tsa', $this->issue('tsa'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-sugar-trial="/preview/themes/sample/home/section\?expires=\d+&amp;token=[0-9a-f]{64}"#', $html);
        $this->assertStringNotContainsString('data-sugar-trial', (string) $this->page('tsa', '/')->getContent());

        // ヒーローの「左右」を見本の値（見出し・文・ボタンのブロック）で描く。写真の欄は無地の仮の写真
        $before = $this->footprint('tsa');
        $response = $this->page('tsa', $this->trialPath('tsa').'&type=hero&pattern=split')->assertOk();
        $fragment = (string) $response->getContent();
        $this->assertStringContainsString('data-sugar-trial-root', $fragment);
        $this->assertStringContainsString('data-pattern="split"', $fragment);
        $this->assertStringContainsString('見出しを入れてください', $fragment);
        $this->assertStringContainsString('data-test="hero-button"', $fragment);
        $this->assertStringContainsString('src="'.ShopPhoto::PLACEHOLDER.'"', $fragment);
        // 外枠・App・編集の印は描かない
        $this->assertStringNotContainsString('data-sugar-editor', $fragment);
        $this->assertStringNotContainsString('<script', $fragment);
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        // 枠には入れない（読むのは枠の中の部品。frame-ancestors は 'none' のまま）
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));

        // 繰り返しの組は見本の並び、商品の並びは新着、パターンの部品が無い種類は種類の部品
        $this->page('tsa', $this->trialPath('tsa').'&type=features&pattern=default')->assertOk()->assertSee('特集の題を入れてください')->assertSee('2 つ目の特集');
        $this->page('tsa', $this->trialPath('tsa').'&type=rail&pattern=default')->assertOk()->assertSee('新着の商品');
        $this->page('tsa', $this->trialPath('tsa').'&type=hero&pattern=grid')->assertOk()->assertSee('data-fallback', false);
        // 部品の無い種類（お知らせの帯は外枠が描く）は、描く物の無い印だけ
        $this->page('tsa', $this->trialPath('tsa').'&type=announcement&pattern=default')->assertOk()
            ->assertSee('data-sugar-trial-frame', false)->assertDontSee('お知らせを入れてください');

        // 何度試しても DB には何も書かない（下書き・公開・イベント・控え・記録）
        $this->assertSame($before, $this->footprint('tsa'));
    }

    /**
     * 公式テーマ 4 つの、トップと自由なページに置けるすべての種類 × パターンを試し見で描ける（見本と仮の写真で部品が落ちない）。
     * 部品の無い種類（外枠が描くお知らせの帯・フッター）は、描く物の無い印だけ
     */
    public function test_every_pattern_of_the_official_themes_can_be_tried(): void
    {
        $page = $this->inside('tsa', static fn () => app(ShopPageService::class)->create(['slug' => 'about', 'title' => '私たちについて'], 'cli:test'));

        foreach (['default', 'kinari', 'fashion', 'botanica'] as $theme) {
            $schema = app(ThemeRegistry::class)->get($theme)->schema();

            foreach (['home', "page-{$page->id}"] as $name) {
                $spec = $schema->page($name);
                $this->assertNotNull($spec, "{$theme} {$name}");
                $path = ThemePreviewLink::trialPath('tsa', $theme, $name, now()->addMinutes(5)->getTimestamp());

                foreach ($spec->types as $type) {
                    foreach (array_keys($schema->section($type)->patterns ?? []) as $pattern) {
                        $response = $this->page('tsa', "{$path}&type={$type}&pattern={$pattern}");
                        $where = "{$theme} {$name} {$type}.{$pattern}";

                        $this->assertSame(200, $response->status(), $where);
                        $this->assertStringContainsString('data-sugar-trial-root', (string) $response->getContent(), $where);
                        // 外枠が描く種類（お知らせの帯・フッター）だけは描く物が無く、印だけ
                        $this->assertSame(in_array($type, ['announcement', 'footer'], true), str_contains((string) $response->getContent(), 'data-sugar-trial-frame'), $where);
                    }
                }
            }
        }
    }

    public function test_a_free_page_tries_the_sections_of_its_template_only(): void
    {
        $page = $this->inside('tsa', static fn () => app(ShopPageService::class)->create(['slug' => 'about', 'title' => '私たちについて'], 'cli:test'));
        $name = "page-{$page->id}";
        $path = $this->trialPath('tsa', $name);

        // 雛形に入れた核の汎用の区画は、核の部品で見本の値を描く
        $this->page('tsa', "{$path}&type=basic_text&pattern=center")->assertOk()->assertSee('見出しを入力してください');
        // 雛形に無い種類は断る
        $this->page('tsa', "{$path}&type=features&pattern=default")->assertNotFound();
        $this->page('tsa', "{$path}&type=basic_image&pattern=wide")->assertNotFound();

        // ゴミ箱のページは断る
        $this->inside('tsa', static fn () => app(ShopPageService::class)->trash($page->id, 'cli:test'));
        $this->page('tsa', "{$path}&type=basic_text&pattern=center")->assertNotFound();
    }

    public function test_a_changed_expired_borrowed_or_unknown_request_shows_nothing(): void
    {
        $path = $this->trialPath('tsa');
        parse_str((string) parse_url($path, PHP_URL_QUERY), $params);
        $this->assertIsString($params['token'] ?? null);
        $this->assertIsString($params['expires'] ?? null);
        $token = $params['token'];
        $expires = $params['expires'];
        $ok = '&type=hero&pattern=split';

        $refused = function (string $path, string $shop = 'tsa'): void {
            $response = $this->page($shop, $path)->assertNotFound();
            $this->assertSame('', (string) $response->getContent(), $path);
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $path);
        };

        // 本物は開ける
        $this->page('tsa', $path.$ok)->assertOk();

        // 署名の書き換え・署名なし・期限を延ばす・形の違う値
        $changed = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $refused("/preview/themes/sample/home/section?expires={$expires}&token={$changed}{$ok}");
        $refused('/preview/themes/sample/home/section?type=hero&pattern=split');
        $refused('/preview/themes/sample/home/section?expires='.((int) $expires + 3600)."&token={$token}{$ok}");
        $refused("/preview/themes/sample/home/section?expires[]=1&token[]=x{$ok}");

        // 画面のプレビューのリンクの署名では開けない（鍵は用途で分ける）。逆に断片の署名で画面は開けない
        $page = ThemePreviewLink::query('tsa', 'sample', 'home', (int) $expires);
        $refused("/preview/themes/sample/home/section?expires={$page['expires']}&token={$page['token']}{$ok}");
        $this->page('tsa', "/preview/themes/sample/home?expires={$expires}&token={$token}")->assertNotFound()->assertDontSee('data-sugar-editor', false);

        // ほかのテーマ・ほかのページへ広げない（同じ署名で）
        $refused("/preview/themes/default/home/section?expires={$expires}&token={$token}{$ok}");
        $refused("/preview/themes/sample/page-01k8z3x5y7a9b1c3d5e7f9g1h3/section?expires={$expires}&token={$token}&type=basic_text&pattern=left");

        // ほかの店で作った署名は、この店では開けない。この店の署名を、ほかの店のホストで使っても開けない
        $refused($this->trialPath('tsb').$ok);
        $refused($path.$ok, 'tsb');

        // 知らない種類・置けない形の名前・知らないパターン・パターンの書き忘れ・配列
        $refused("{$path}&type=nope&pattern=default");
        $refused("{$path}&type=../hero&pattern=split");
        $refused("{$path}&type=Hero&pattern=split");
        $refused("{$path}&type=hero&pattern=nope");
        $refused("{$path}&type=hero");
        $refused("{$path}&type[]=hero&pattern=split");
        // トップに置けない核の汎用の区画（雛形の外）
        $refused("{$path}&type=basic_text&pattern=left");

        // 期限（30 分）を過ぎたら開けない
        $this->travel(ThemePreviewLink::MINUTES + 1)->minutes();
        $refused($path.$ok);
    }

    public function test_a_fixed_page_and_a_preparing_shop(): void
    {
        // 決まった並びのテーマには足す操作が無いので、試し見も断る
        Tenant::query()->findOrFail('tsa')->forceFill(['theme' => 'fixed'])->save();
        $fixed = ThemePreviewLink::trialPath('tsa', 'fixed', 'home', now()->addMinutes(5)->getTimestamp());
        $this->page('tsa', "{$fixed}&type=hero&pattern=default")->assertNotFound();
        Tenant::query()->findOrFail('tsa')->forceFill(['theme' => 'sample'])->save();

        // 準備中のショップでも、署名の合う断片は開ける。合わなければ「準備中です」へ送らず、中身の無い 404
        $this->inside('tsa', static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'cli:test'));
        $this->page('tsa', $this->trialPath('tsa').'&type=hero&pattern=split')->assertOk()->assertSee('見出しを入れてください');
        $this->page('tsa', '/preview/themes/sample/home/section?expires=9999999999&token='.str_repeat('0', 64).'&type=hero&pattern=split')->assertNotFound();
        $this->page('tsa', '/')->assertRedirect('http://tsa.localhost/password');
    }

    /**
     * 試し見の断片の道筋（プレビューの画面に置くものと同じ）
     */
    private function trialPath(string $shop, string $page = 'home'): string
    {
        return ThemePreviewLink::trialPath($shop, 'sample', $page, now()->addMinutes(ThemePreviewLink::MINUTES)->getTimestamp());
    }

    private function issue(string $shop): string
    {
        $link = ThemePreviewLink::issue(Tenant::query()->findOrFail($shop), 'sample', 'home');
        $this->assertNotNull($link);

        return $link['url'];
    }

    /**
     * 店の DB と中央の記録の跡（下書き・公開・改訂・イベント・控え・ページ・運営の記録の数と中身の要約）
     *
     * @return array<string, mixed>
     */
    private function footprint(string $shop): array
    {
        $tenant = $this->inside($shop, static fn (): array => [
            'versions' => DB::table('theme_versions')->orderBy('id')->get()->map(static fn (object $row): string => md5(json_encode($row, JSON_THROW_ON_ERROR)))->all(),
            'events' => DB::table('domain_events')->count(),
            'legacy' => DB::table('legacy_storefront_settings')->count(),
            'pages' => DB::table('shop_pages')->count(),
        ]);

        return $tenant + ['audit' => AuditEvent::query()->count()];
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
