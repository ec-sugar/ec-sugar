<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Admin\Services\OperatorAccess;
use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Section\Models\ShopPage;
use App\Core\Shop\Models\Redirect;
use App\Core\Shop\Models\ShopSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Models\Tenant;
use App\Storefront\View\PrivacyPolicyView;
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
 * プライバシーポリシー（ADR 0094）: 店の設定の本文（暗号化・記録・上限・権限）と、ショップの /privacy（テーマの任意の画面か核の既定の画面・
 * 本文が空なら「見つかりません」・HTML は文字のまま・フッターのリンク・店の境界）と、自由なページの予約語 privacy。
 */
final class PrivacyPolicyTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use RegistersSampleTheme;
    use WithTenants;

    private const string BODY = "当店は、お客さまの個人情報を次のとおり取り扱います。\n二行目です。\n\n## 取得する情報\n- お名前\n・ご住所\n\n### 小さい見出し\n<script>alert(1)</script>\n<img src=x onerror=alert(2)>\n\n1. 番号はそのまま";

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

    public function test_the_policy_is_kept_encrypted_recorded_and_shown_on_every_official_theme_with_the_html_left_as_text(): void
    {
        $this->tenant('ppa');
        $this->enrolledOperator();

        $this->send('GET', '/api/tenants/ppa/shop-settings')->assertOk()->assertJsonPath('privacy_policy', null);
        $this->send('PUT', '/api/tenants/ppa/shop-settings/privacy-policy', ['body' => '  '.str_replace("\n", "\r\n", self::BODY)."\t "])->assertOk()
            ->assertJsonPath('privacy_policy', self::BODY);
        // 同じ本文なら記録しない
        $this->send('PUT', '/api/tenants/ppa/shop-settings/privacy-policy', ['body' => self::BODY])->assertOk();

        $this->inside('ppa', function (): void {
            // 列は暗号化（問い合わせ先の名前・住所を平文で置かない）。記録は文字の数だけ
            $raw = (string) ShopSettings::query()->toBase()->value('privacy_policy');
            $this->assertStringStartsWith(TenantCipher::PREFIX, $raw);
            $events = DomainEvent::query()->where('type', 'shop.privacy_updated')->get();
            $this->assertCount(1, $events);
            $this->assertSame(['chars' => mb_strlen(self::BODY)], $events[0]->payload);
            $this->assertStringStartsWith('operator:', (string) $events[0]->actor);

            // DB も平文を断る
            try {
                DB::table('shop_settings')->update(['privacy_policy' => '平文']);
                $this->fail('DB が平文の本文を通した');
            } catch (QueryException $e) {
                $this->assertStringContainsString('shop_settings_privacy_policy_encrypted', $e->getMessage());
            }
        });

        // テーマが privacy の画面を持たない 4 つの公式テーマは、核の既定の画面で外枠の中に描く。フッターにリンク
        foreach ([null, 'kinari', 'fashion', 'botanica'] as $theme) {
            Tenant::query()->findOrFail('ppa')->forceFill(['theme' => $theme])->save();
            $html = (string) $this->shop('ppa', '/privacy')->assertOk()->getContent();
            $label = (string) ($theme ?? 'default');
            $this->assertStringContainsString('data-test="privacy-page"', $html, $label);
            $this->assertStringContainsString('data-test="privacy-link"', $html, $label);
            $this->assertMatchesRegularExpression('#<h2 data-block="heading" data-level="2">取得する情報</h2>#u', $html, $label);
            $this->assertMatchesRegularExpression('#<h3 data-block="heading" data-level="3">小さい見出し</h3>#u', $html, $label);
            $this->assertMatchesRegularExpression('#<li>お名前</li>\s*<li>ご住所</li>#u', $html, $label);
            $this->assertStringContainsString("当店は、お客さまの個人情報を次のとおり取り扱います。\n二行目です。", $html, $label);
            $this->assertStringContainsString('1. 番号はそのまま', $html, $label);
            // 本文の HTML は文字のまま（スクリプトにも要素にもならない）
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $label);
            $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html, $label);
            $this->assertStringNotContainsString('<script>alert(1)', $html, $label);
            $this->assertStringNotContainsString('<img src=x', $html, $label);
            $home = (string) $this->shop('ppa', '/')->assertOk()->getContent();
            $this->assertStringContainsString('href="/privacy" ', $home, $label);
        }

        // テーマが privacy の画面を持てば、その画面で描く
        Tenant::query()->findOrFail('ppa')->forceFill(['theme' => 'sample'])->save();
        $this->shop('ppa', '/privacy')->assertOk()->assertSee('data-test="sample-privacy-title"', false)->assertDontSee('data-test="privacy-page"', false);

        // 検索に載せる（サイトマップにも）
        $this->assertStringContainsString('http://ppa.localhost/privacy', (string) $this->shop('ppa', '/sitemap.xml')->assertOk()->getContent());
    }

    public function test_an_empty_policy_is_not_found_and_leaves_no_link_and_another_shop_never_shows_it(): void
    {
        $this->tenant('ppb');
        $this->tenant('ppc');
        $this->enrolledOperator();

        // まだ無い店: 見つからない・リンクも出ない・サイトマップにも載らない
        $this->send('GET', '/api/tenants/ppb/shop-settings')->assertOk()->assertJsonPath('privacy_policy', null);
        $this->shop('ppb', '/privacy')->assertNotFound()->assertDontSee('data-test="privacy-page"', false);
        $this->shop('ppb', '/')->assertOk()->assertDontSee('data-test="privacy-link"', false);
        $this->assertStringNotContainsString('/privacy', (string) $this->shop('ppb', '/sitemap.xml')->getContent());

        $this->send('PUT', '/api/tenants/ppb/shop-settings/privacy-policy', ['body' => 'ppb の店だけの本文'])->assertOk();
        $this->shop('ppb', '/privacy')->assertOk()->assertSee('ppb の店だけの本文');
        // ほかの店には出ない
        $this->shop('ppc', '/privacy')->assertNotFound()->assertDontSee('ppb の店だけの本文');
        $this->shop('ppc', '/')->assertDontSee('data-test="privacy-link"', false);
        $this->send('GET', '/api/tenants/ppc/shop-settings')->assertJsonPath('privacy_policy', null);

        // 空白だけ・null は外す（見つからないに戻る）
        $this->send('PUT', '/api/tenants/ppb/shop-settings/privacy-policy', ['body' => "  \n "])->assertOk()->assertJsonPath('privacy_policy', null);
        $this->shop('ppb', '/privacy')->assertNotFound();
        $this->send('PUT', '/api/tenants/ppb/shop-settings/privacy-policy', ['body' => 'もう一度'])->assertOk();
        $this->send('PUT', '/api/tenants/ppb/shop-settings/privacy-policy', ['body' => null])->assertOk()->assertJsonPath('privacy_policy', null);
        $this->inside('ppb', function (): void {
            $this->assertNull(ShopSettings::query()->toBase()->value('privacy_policy'));
            $this->assertSame([['chars' => mb_strlen('ppb の店だけの本文')], ['chars' => 0], ['chars' => 4], ['chars' => 0]], DomainEvent::query()->where('type', 'shop.privacy_updated')->orderBy('sequence')->pluck('payload')->all());
        });
    }

    public function test_malformed_or_too_long_policies_are_refused_and_only_operate_level_changes_it(): void
    {
        $this->tenant('ppd');
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'ppd', 'read', 'test');

        // 閲覧の段階は読めるが変えられない
        $this->send('GET', '/api/tenants/ppd/shop-settings')->assertOk()->assertJsonPath('privacy_policy', null);
        $this->send('PUT', '/api/tenants/ppd/shop-settings/privacy-policy', ['body' => '乗っ取り'])->assertForbidden();
        $this->inside('ppd', fn () => $this->assertNull(app(ShopSettingsService::class)->privacyPolicy()));

        $access->grant($staff, 'ppd', 'operate', 'test');
        foreach ([[], ['body' => ['x']], ['body' => 12], ['body' => str_repeat('あ', ShopSettingsService::MAX_PRIVACY_POLICY + 1)], ['body' => "見えない\u{0007}文字"], ['body' => "縦の\u{000B}タブ"]] as $input) {
            $response = $this->send('PUT', '/api/tenants/ppd/shop-settings/privacy-policy', $input);
            $this->assertSame(422, $response->getStatusCode(), (string) json_encode(array_map(static fn ($v) => is_string($v) ? mb_substr($v, 0, 20) : $v, $input), JSON_UNESCAPED_UNICODE));
            $response->assertJsonValidationErrors('body');
        }
        $this->inside('ppd', fn () => $this->assertSame(0, DomainEvent::query()->where('type', 'shop.privacy_updated')->count()));

        // ちょうど上限は通る。タブは空白にそろえる
        $this->send('PUT', '/api/tenants/ppd/shop-settings/privacy-policy', ['body' => str_repeat('あ', ShopSettingsService::MAX_PRIVACY_POLICY)])->assertOk();
        $this->send('PUT', '/api/tenants/ppd/shop-settings/privacy-policy', ['body' => "項目\t内容"])->assertOk()->assertJsonPath('privacy_policy', '項目 内容');
    }

    public function test_privacy_is_a_reserved_page_address_and_an_old_privacy_page_moves_with_a_redirect(): void
    {
        $this->tenant('ppe');
        $this->enrolledOperator();

        $this->send('POST', '/api/tenants/ppe/shop-pages', ['title' => '個人情報', 'slug' => 'privacy'])->assertUnprocessable()->assertJsonValidationErrors(['slug']);

        $this->inside('ppe', function (): void {
            // DB でも断る
            try {
                DB::table('shop_pages')->insert(['id' => strtolower((string) str()->ulid()), 'slug' => 'privacy', 'title' => 'x', 'created_at' => now(), 'updated_at' => now()]);
                $this->fail('DB が slug「privacy」を通した');
            } catch (QueryException $e) {
                $this->assertStringContainsString('shop_pages_slug_not_reserved', $e->getMessage());
            }

            // 前の案内どおりに privacy のページを作っていた店: 移行が privacy-page へ替え、前の URL から転送する
            DB::statement('ALTER TABLE shop_pages DROP CONSTRAINT shop_pages_slug_not_reserved');
            $live = strtolower((string) str()->ulid());
            $trashed = strtolower((string) str()->ulid());
            DB::table('shop_pages')->insert([
                ['id' => $live, 'slug' => 'privacy', 'title' => '個人情報', 'status' => ShopPage::PUBLISHED, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
                ['id' => $trashed, 'slug' => 'privacy', 'title' => '古い', 'status' => ShopPage::DRAFT, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()],
            ]);
            (require base_path('database/migrations/tenant/2026_11_11_000200_reserve_privacy_slug_for_shop_pages.php'))->up();

            $this->assertSame(['privacy-page', 'privacy-page-2'], [
                ShopPage::withTrashed()->findOrFail($live)->slug,
                ShopPage::withTrashed()->findOrFail($trashed)->slug,
            ]);
            $this->assertSame(['/pages/privacy' => '/pages/privacy-page'], Redirect::query()->pluck('to_path', 'from_path')->all());
        });

        $this->shop('ppe', '/pages/privacy')->assertStatus(Response::HTTP_MOVED_PERMANENTLY)->assertRedirect('/pages/privacy-page');
        $this->shop('ppe', '/pages/privacy-page')->assertOk();
    }

    public function test_the_text_reads_as_paragraphs_headings_and_bullets_only(): void
    {
        $blocks = PrivacyPolicyView::blocks("# 大\n本文\n- a\n- b\n続き\n\n\n### 小\n#見出しでない\n-箇条でない");

        $this->assertSame(
            [['heading', '大'], ['paragraph', '本文'], ['list', 'a|b'], ['paragraph', '続き'], ['heading', '小'], ['paragraph', "#見出しでない\n-箇条でない"]],
            array_map(static fn ($b): array => [$b->type, $b->type === 'list' ? implode('|', $b->items) : $b->text], $blocks),
        );
        $this->assertSame([2, 3], [$blocks[0]->level, $blocks[4]->level]);
        $this->assertSame([], PrivacyPolicyView::blocks("\n \n"));
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $shop, string $path): TestResponse
    {
        $response = $this->get("http://{$shop}.localhost{$path}");
        tenancy()->end();

        return $response;
    }
}
