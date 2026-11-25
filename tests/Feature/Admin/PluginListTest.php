<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\AppContract;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 入っているプラグインとテーマの一覧。オーナーだけ、読むだけ。テーマは使っている店つき
 * （未設定か、入っていないテーマの店は既定のテーマで描いている）。入れる・外す経路は無い
 */
final class PluginListTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('pla', '店 A');
        $this->tenant('plb', '店 B');
        $this->tenant('plc', '店 C');
        Tenant::query()->findOrFail('plb')->forceFill(['theme' => 'kinari'])->save();
        Tenant::query()->findOrFail('plc')->forceFill(['theme' => 'removed-theme'])->save();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_an_owner_sees_the_installed_themes_and_the_shops_using_them(): void
    {
        $this->enrolledOperator();
        $response = $this->send('GET', '/api/plugins')->assertOk()->assertJsonPath('core_version', '1.0.0');
        $packages = $response->collect('packages')->keyBy('package');

        // 公開用の木（1.0。ADR 0098）は定期便・予約・チケット・抽選販売の App が入っていないので、入っていない App の名前は除いて比べる
        $absent = array_map(static fn (string $name): string => "ec-sugar/plugin-{$name}", AppContract::absentUnpublished());
        $this->assertSame(array_values(array_diff(['ec-sugar/plugin-age-check', 'ec-sugar/plugin-blog', 'ec-sugar/plugin-booking', 'ec-sugar/plugin-bundle', 'ec-sugar/plugin-cart-reminder', 'ec-sugar/plugin-catalog-sort', 'ec-sugar/plugin-clarity', 'ec-sugar/plugin-contact', 'ec-sugar/plugin-downloads', 'ec-sugar/plugin-faq', 'ec-sugar/plugin-favorites', 'ec-sugar/plugin-ga4', 'ec-sugar/plugin-gift', 'ec-sugar/plugin-gift-cards', 'ec-sugar/plugin-google-ads', 'ec-sugar/plugin-google-map', 'ec-sugar/plugin-line', 'ec-sugar/plugin-lineyahoo-ads', 'ec-sugar/plugin-lottery', 'ec-sugar/plugin-member-ranks', 'ec-sugar/plugin-meta-pixel', 'ec-sugar/plugin-microsoft-uet', 'ec-sugar/plugin-newsletter', 'ec-sugar/plugin-order-print', 'ec-sugar/plugin-pinterest-tag', 'ec-sugar/plugin-preorder', 'ec-sugar/plugin-product-csv', 'ec-sugar/plugin-product-feed', 'ec-sugar/plugin-purchase-limits', 'ec-sugar/plugin-recommendations', 'ec-sugar/plugin-referral', 'ec-sugar/plugin-restock', 'ec-sugar/plugin-reviews', 'ec-sugar/plugin-sale-period', 'ec-sugar/plugin-services', 'ec-sugar/plugin-shipping-csv', 'ec-sugar/plugin-shop-notify', 'ec-sugar/plugin-smartnews-ads', 'ec-sugar/plugin-subscription', 'ec-sugar/plugin-tag-sample', 'ec-sugar/plugin-tickets', 'ec-sugar/plugin-tiktok-pixel', 'ec-sugar/plugin-video', 'ec-sugar/plugin-wrapping', 'ec-sugar/plugin-x-pixel', 'ec-sugar/theme-botanica', 'ec-sugar/theme-default', 'ec-sugar/theme-fashion', 'ec-sugar/theme-kinari'], $absent)), $packages->keys()->sort()->values()->all());
        // プラグインは登録簿に載っていれば「動いている」。使っている店は店ごとの画面で見る（ここでは店の DB に入らない）
        $faq = $packages['ec-sugar/plugin-faq'];
        $this->assertSame(['plugin', 'faq', true, true, true, []], [$faq['kind'], $faq['name'], $faq['compatible'], $faq['first_party'], $faq['active'], $faq['shops']]);
        $this->assertTrue($packages['ec-sugar/theme-fashion']['active']);
        $this->assertTrue($packages['ec-sugar/theme-fashion']['first_party']);
        $kinari = $packages['ec-sugar/theme-kinari'];
        $this->assertSame(['theme', 'kinari', '0.1.0', '^1.0', true, true, true, false, null], [$kinari['kind'], $kinari['name'], $kinari['version'], $kinari['core'], $kinari['compatible'], $kinari['first_party'], $kinari['active'], $kinari['default'], $kinari['problem']]);
        $this->assertSame([['id' => 'plb', 'name' => '店 B']], $kinari['shops']);
        // 既定のテーマ: 未設定の店と、入っていないテーマを選んでいた店
        $this->assertTrue($packages['ec-sugar/theme-default']['default']);
        $this->assertSame(['pla', 'plc'], array_column($packages['ec-sugar/theme-default']['shops'], 'id'));

        // 入れる・外す・上げる経路は無い（CONCEPT 7.2）
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach (['/api/plugins', '/api/plugins/upload', '/api/plugins/install'] as $path) {
                $this->assertContains($this->send($method, $path)->status(), [404, 405], "{$method} {$path}");
            }
        }
    }

    public function test_staff_and_strangers_cannot_see_it(): void
    {
        $this->send('GET', '/api/plugins')->assertUnauthorized();

        $this->enrolledOperator('staff@example.com', 'staff');
        $this->send('GET', '/api/plugins')->assertForbidden();
    }
}
