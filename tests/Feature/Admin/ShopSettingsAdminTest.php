<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Shop\Models\ShopSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の店の設定: 店頭の文言（テナント DB、バージョンつき）と店のテーマ（中央、監査つき）。
 */
final class ShopSettingsAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private Operator $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_theme_is_chosen_from_installed_themes_and_audited(): void
    {
        $this->tenant('ssc');
        $this->signIn();

        $this->send('GET', '/api/tenants/ssc/shop-settings')->assertOk()
            ->assertJsonPath('theme.chosen', null)
            ->assertJsonPath('theme.default', 'default')
            ->assertJsonFragment(['name' => 'kinari', 'label' => '公式テーマ 1（kinari）']);

        foreach (['../evil', 'not-installed', 'Kinari'] as $name) {
            $this->send('PUT', '/api/tenants/ssc/shop-settings/theme', ['theme' => $name])->assertStatus(422)->assertJsonValidationErrors('theme');
        }
        $this->send('PUT', '/api/tenants/ssc/shop-settings/theme', [])->assertStatus(422);

        $this->send('PUT', '/api/tenants/ssc/shop-settings/theme', ['theme' => 'kinari'])->assertOk()->assertJsonPath('theme.chosen', 'kinari');
        $this->send('PUT', '/api/tenants/ssc/shop-settings/theme', ['theme' => null])->assertOk()->assertJsonPath('theme.chosen', null);

        $events = AuditEvent::query()->where('type', 'tenant.theme_changed')->where('tenant_id', 'ssc')->orderBy('sequence')->get();
        $this->assertSame([['from' => null, 'to' => 'kinari'], ['from' => 'kinari', 'to' => null]], $events->map(static fn (AuditEvent $e): array => ['from' => $e->data['from'], 'to' => $e->data['to']])->all());
        $this->assertSame($this->operator->id, $events[0]->operator_id);
    }

    public function test_selling_free_items_is_chosen_through_the_api_and_recorded(): void
    {
        $this->tenant('ssg');
        $this->signIn();

        // 既定は売らない
        $this->send('GET', '/api/tenants/ssg/shop-settings')->assertOk()->assertJsonPath('sell_free_items', false);
        $this->send('PUT', '/api/tenants/ssg/shop-settings/free-items', ['sell' => true])->assertOk()->assertJsonPath('sell_free_items', true);
        $this->send('PUT', '/api/tenants/ssg/shop-settings/free-items', ['sell' => 'たぶん'])->assertStatus(422)->assertJsonValidationErrors('sell');
        $this->send('PUT', '/api/tenants/ssg/shop-settings/free-items', [])->assertStatus(422)->assertJsonValidationErrors('sell');
        // 同じ値をもう一度送っても記録は増えない
        $this->send('PUT', '/api/tenants/ssg/shop-settings/free-items', ['sell' => true])->assertOk();
        $this->inside('ssg', fn () => $this->assertSame(1, DomainEvent::query()->where('type', 'shop.free_items_updated')->count()));
    }

    /**
     * 同意の帯: 既定は出さない。変えるのは運用の段階から（閲覧は読むだけ、API でも 403）、変えたら記録に値だけを残す
     */
    public function test_the_consent_banner_is_off_by_default_and_only_operate_level_changes_it(): void
    {
        $this->tenant('ssh');
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'ssh', 'read', 'test');

        $this->send('GET', '/api/tenants/ssh/shop-settings')->assertOk()->assertJsonPath('consent_banner', false);
        $this->send('PUT', '/api/tenants/ssh/shop-settings/consent-banner', ['show' => true])->assertForbidden();
        $this->inside('ssh', function (): void {
            $this->assertFalse(app(ShopSettingsService::class)->showsConsentBanner());
            $this->assertSame(0, DomainEvent::query()->where('type', 'shop.consent_banner_updated')->count());
        });

        $access->grant($staff, 'ssh', 'operate', 'test');
        $this->send('PUT', '/api/tenants/ssh/shop-settings/consent-banner', ['show' => 'たぶん'])->assertStatus(422)->assertJsonValidationErrors('show');
        $this->send('PUT', '/api/tenants/ssh/shop-settings/consent-banner', [])->assertStatus(422)->assertJsonValidationErrors('show');
        $this->send('PUT', '/api/tenants/ssh/shop-settings/consent-banner', ['show' => true])->assertOk()->assertJsonPath('consent_banner', true);
        // 同じ値をもう一度送っても記録は増えない。戻すと 2 件目
        $this->send('PUT', '/api/tenants/ssh/shop-settings/consent-banner', ['show' => true])->assertOk();
        $this->send('PUT', '/api/tenants/ssh/shop-settings/consent-banner', ['show' => false])->assertOk()->assertJsonPath('consent_banner', false);
        $this->inside('ssh', function () use ($staff): void {
            $events = DomainEvent::query()->where('type', 'shop.consent_banner_updated')->orderBy('sequence')->get();
            $this->assertSame([['show' => true], ['show' => false]], $events->map(static fn (DomainEvent $e): array => $e->payload)->all());
            $this->assertSame('operator:'.$staff->id, $events[0]->actor);
        });
        // App の画面にも、帯を出すかを返す（いつ動くかの説明に使う）
        $this->send('GET', '/api/tenants/ssh/plugins')->assertOk()->assertJsonPath('consent_banner', false);
    }

    public function test_the_legal_notice_is_encrypted_kept_in_order_and_shown_on_the_storefront(): void
    {
        $this->tenant('ssl');
        $this->tenant('ssm');
        $this->signIn();
        $items = [
            ['label' => '販売業者', 'value' => '  山田 花子  '],
            ['label' => '所在地', 'value' => "東京都渋谷区神宮前 1-2-3\r\nハイツ 101"],
            ['label' => '<script>x</script>', 'value' => '<img src=x onerror=alert(1)>'],
        ];

        $this->send('GET', '/api/tenants/ssl/shop-settings')->assertOk()->assertJsonPath('legal', []);
        $this->send('PUT', '/api/tenants/ssl/shop-settings/legal', ['items' => $items])->assertOk()
            ->assertJsonPath('legal.0', ['label' => '販売業者', 'value' => '山田 花子'])
            ->assertJsonPath('legal.1.value', "東京都渋谷区神宮前 1-2-3\nハイツ 101")
            ->assertJsonMissingPath('storefront')->assertJsonMissingPath('hero_image');
        // 同じ内容なら記録しない。並べ替えは変更
        $this->send('PUT', '/api/tenants/ssl/shop-settings/legal', ['items' => $items])->assertOk();
        $this->send('PUT', '/api/tenants/ssl/shop-settings/legal', ['items' => [$items[1], $items[0], $items[2]]])->assertOk()->assertJsonPath('legal.0.label', '所在地');

        $this->inside('ssl', function (): void {
            // 列は暗号化（店主の住所などを平文で置かない）。記録は項目の数だけ
            // （平文の JSON は日本語を \uXXXX で書くので、文字が無いことだけでは確かめにならない）
            $raw = (string) ShopSettings::query()->toBase()->value('legal');
            $this->assertStringStartsWith(TenantCipher::PREFIX, $raw);
            $this->assertStringNotContainsString('label', $raw);
            $events = DomainEvent::query()->where('type', 'shop.legal_updated')->orderBy('sequence')->get();
            $this->assertCount(2, $events);
            $this->assertSame(['items' => 3], $events[0]->payload);
            $this->assertStringStartsWith('operator:', (string) $events[0]->actor);
        });

        // 店頭: 並び順で、改行を保ち、HTML は文字のまま。別の店には出ない
        foreach (['kinari', null] as $theme) {
            Tenant::query()->findOrFail('ssl')->forceFill(['theme' => $theme])->save();
            $html = (string) $this->get('http://ssl.localhost/legal/commerce')->assertOk()->getContent();
            tenancy()->end();
            $this->assertLessThan(strpos($html, '販売業者'), strpos($html, '所在地'), (string) $theme);
            $this->assertStringContainsString("東京都渋谷区神宮前 1-2-3\nハイツ 101", $html);
            $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('data-test="legal-empty"', $html);
        }
        $this->get('http://ssm.localhost/legal/commerce')->assertOk()->assertSee('data-test="legal-empty"', false)->assertDontSee('山田');
        tenancy()->end();
        $this->send('GET', '/api/tenants/ssm/shop-settings')->assertJsonPath('legal', []);

        // 全部外すと「準備中」に戻る
        $this->send('PUT', '/api/tenants/ssl/shop-settings/legal', ['items' => []])->assertOk()->assertJsonPath('legal', []);
        $this->get('http://ssl.localhost/legal/commerce')->assertSee('data-test="legal-empty"', false);
        tenancy()->end();
    }

    public function test_malformed_legal_items_are_refused(): void
    {
        $this->tenant('ssn');
        $this->signIn();
        $row = ['label' => '販売業者', 'value' => '山田商店'];

        foreach ([
            'items' => [],
            'items ' => ['items' => 'x'],
            'items  ' => ['items' => ['a' => $row]],
            'items   ' => ['items' => array_fill(0, 21, $row)],
            'items.0' => ['items' => [$row + ['onclick' => 'x']]],
            'items.0.label' => ['items' => [['label' => '', 'value' => 'x']]],
            'items.0.label ' => ['items' => [['label' => str_repeat('あ', 31), 'value' => 'x']]],
            'items.0.label  ' => ['items' => [['label' => "1 行目\n2 行目", 'value' => 'x']]],
            'items.0.label   ' => ['items' => [['label' => ['x'], 'value' => 'x']]],
            'items.0.value' => ['items' => [['label' => 'x', 'value' => '']]],
            'items.0.value ' => ['items' => [['label' => 'x', 'value' => str_repeat('あ', 1001)]]],
            'items.0.value  ' => ['items' => [['label' => 'x', 'value' => "見えない\u{0007}文字"]]],
        ] as $field => $input) {
            $this->send('PUT', '/api/tenants/ssn/shop-settings/legal', $input)->assertStatus(422)->assertJsonValidationErrors(trim($field));
        }

        // ちょうど上限は通る
        $this->send('PUT', '/api/tenants/ssn/shop-settings/legal', ['items' => array_fill(0, 20, ['label' => str_repeat('あ', 30), 'value' => str_repeat('い', 1000)])])->assertOk();
        $this->inside('ssn', fn () => $this->assertSame(1, DomainEvent::query()->where('type', 'shop.legal_updated')->count()));
    }

    public function test_settings_stay_inside_their_tenant_and_need_a_signed_in_operator(): void
    {
        $this->tenant('ssd');
        $this->tenant('sse');
        $this->signIn();
        // 送料の表（ADR 0064）は店ごと。ほかの店の方法の ID では保存できない
        $ssd = $this->send('GET', '/api/tenants/ssd/shipping-methods')->assertOk()->json('methods.0');
        // ssd で足した方法を使う（テストの店の DB は型から写すので、移行が作る最初の方法の ID は店どうしで同じになる）
        $rates = array_fill_keys(array_keys($ssd['rates']), 880);
        $added = $this->send('PUT', '/api/tenants/ssd/shipping-methods', ['methods' => [
            ['id' => $ssd['id'], 'name' => '通常便', 'free_over' => null, 'rates' => $rates],
            ['id' => null, 'name' => 'クール便', 'free_over' => null, 'rates' => $rates],
        ]])->assertOk()->json('methods.1.id');
        $valid = ['methods' => [['id' => $ssd['id'], 'name' => '通常便', 'free_over' => null, 'rates' => $rates], ['id' => $added, 'name' => 'クール便', 'free_over' => null, 'rates' => $rates]]];

        $this->send('PUT', '/api/tenants/ssd/shipping-methods', $valid)->assertOk();
        $this->send('GET', '/api/tenants/sse/shipping-methods')->assertOk()->assertJsonPath('methods.0.rates.東京都', 0);
        $this->send('PUT', '/api/tenants/sse/shipping-methods', $valid)->assertStatus(422)->assertJsonValidationErrors('methods.1.id');

        $this->operator->update(['disabled_at' => now()]);
        $this->send('PUT', '/api/tenants/sse/shop-settings/theme', ['theme' => 'kinari'])->assertUnauthorized();
        $this->send('PUT', '/api/tenants/sse/shop-settings/legal', ['items' => [['label' => '販売業者', 'value' => '乗っ取り']]])->assertUnauthorized();
        $this->sessionId = null;
        $this->send('PUT', '/api/tenants/sse/shipping-methods', $valid)->assertUnauthorized();
        $this->send('GET', '/api/tenants/sse/shipping-methods')->assertUnauthorized();
        $this->assertNull(Tenant::query()->findOrFail('sse')->getAttribute('theme'));
    }

    private function signIn(): void
    {
        [$this->operator] = $this->enrolledOperator();
    }
}
