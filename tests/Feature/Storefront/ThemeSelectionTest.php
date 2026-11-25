<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Admin\Models\AuditEvent;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * 店ごとのテーマ（公式テーマ 1）。テーマは composer で入れたものから選び、変えたことは中央の監査ログに残す。
 */
final class ThemeSelectionTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<Tenant> */
    private array $tenants = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->tenants as $tenant) {
            $tenant->delete();
        }

        parent::tearDown();
    }

    public function test_each_shop_is_drawn_with_its_own_theme(): void
    {
        $this->assertContains('kinari', app(ThemeRegistry::class)->names());
        $this->tenant('tha');
        $this->tenant('thb');

        $this->artisan('storefront:theme', ['tenant' => 'tha', 'theme' => 'kinari'])->assertSuccessful();

        $this->get('http://tha.localhost/')->assertOk()->assertSee('data-test="shop-logo"', false);
        tenancy()->end();
        // 別の店は既定のテーマのまま
        $this->get('http://thb.localhost/')->assertOk()->assertDontSee('data-test="shop-logo"', false);
        tenancy()->end();

        // 外したテーマ（入っていない名前）が残っていても、既定のテーマで描く（店頭を止めない）
        Tenant::query()->findOrFail('thb')->forceFill(['theme' => 'removed-theme'])->save();
        $this->get('http://thb.localhost/')->assertOk()->assertDontSee('data-test="shop-logo"', false);
        tenancy()->end();

        // 既定に戻す
        $this->artisan('storefront:theme', ['tenant' => 'tha', '--reset' => true])->assertSuccessful();
        $this->get('http://tha.localhost/')->assertOk()->assertDontSee('data-test="shop-logo"', false);
    }

    public function test_changing_the_theme_is_audited_and_only_installed_themes_are_accepted(): void
    {
        $this->tenant('thc');

        $this->artisan('storefront:theme', ['tenant' => 'thc', 'theme' => '../../etc/passwd'])->assertExitCode(2);
        $this->artisan('storefront:theme', ['tenant' => 'thc', 'theme' => 'not-installed'])->assertExitCode(2);
        $this->artisan('storefront:theme', ['tenant' => 'nope', 'theme' => 'kinari'])->assertExitCode(2);
        $this->assertNull(Tenant::query()->findOrFail('thc')->getAttribute('theme'));
        // 監査ログは追記専用で、テストの間でも消えない。この店の分だけを見る
        $this->assertSame(0, AuditEvent::query()->where('type', 'tenant.theme_changed')->where('tenant_id', 'thc')->count());

        $this->artisan('storefront:theme', ['tenant' => 'thc', 'theme' => 'kinari'])->assertSuccessful();
        $this->artisan('storefront:theme', ['tenant' => 'thc'])->expectsOutputToContain('今のテーマ: kinari')->assertSuccessful();

        $event = AuditEvent::query()->where('type', 'tenant.theme_changed')->where('tenant_id', 'thc')->sole();
        $this->assertSame('thc', $event->tenant_id);
        $this->assertNull($event->data['from']);
        $this->assertSame('kinari', $event->data['to']);
        $this->assertStringStartsWith('cli:', (string) $event->data['by']);
    }

    /**
     * 入っているすべてのテーマで、店頭の約束（エスケープ・画面の目印・防御ヘッダ）を守る
     */
    public function test_every_installed_theme_escapes_and_renders_every_page(): void
    {
        $this->tenant('thd', '<script>alert(1)</script>の店');
        tenancy()->initialize(Tenant::query()->findOrFail('thd'));
        Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '<img src=x onerror=alert(1)>シャツ', 'unit_amount' => 4900, 'status' => ListingStatus::Active]);
        tenancy()->end();

        foreach (app(ThemeRegistry::class)->names() as $name) {
            Tenant::query()->findOrFail('thd')->forceFill(['theme' => $name])->save();

            foreach (['/' => 200, '/legal/commerce' => 200, '/nope' => 404] as $path => $status) {
                $response = $this->get('http://thd.localhost'.$path)->assertStatus($status);
                $html = (string) $response->getContent();
                tenancy()->end();

                $this->assertStringNotContainsString('<script>alert(1)', $html, "{$name} {$path}");
                $this->assertStringNotContainsString('<img src=x', $html, "{$name} {$path}");
                $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;の店', $html, "{$name} {$path}");
                $this->assertSame(config('storefront.csp'), $response->headers->get('Content-Security-Policy'), "{$name} {$path}");
            }

            $this->assertStringContainsString('data-test="product-card"', (string) $this->get('http://thd.localhost/')->getContent(), $name);
            tenancy()->end();
            $this->assertStringContainsString('data-test="legal-empty"', (string) $this->get('http://thd.localhost/legal/commerce')->getContent(), $name);
            tenancy()->end();
            $this->assertStringContainsString('data-test="error-page"', (string) $this->get('http://thd.localhost/nope')->getContent(), $name);
            tenancy()->end();
        }
    }

    private function tenant(string $id, ?string $name = null): void
    {
        $tenant = Tenant::create(['id' => $id, 'name' => $name ?? "店 {$id}"]);
        $tenant->domains()->create(['domain' => "{$id}.localhost"]);
        $this->tenants[] = $tenant;
    }
}
