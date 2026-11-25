<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の店のプラグイン（ADR 0025）。入っているプラグインと、この店で使うかどうか。
 * 変えるのは運用の段階で直前の再確認の後、記録に誰が変えたかが残る。入っていない・形の違う名前は見つからない
 */
final class TenantPluginAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('tpa');
        $this->tenant('tpb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_an_operator_turns_a_plugin_on_for_one_shop_after_confirming(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $faq = $this->faq($this->send('GET', '/api/tenants/tpa/plugins')->assertOk());
        $this->assertSame(['よくある質問', false], [$faq['title'], $faq['enabled']]);

        $this->send('PUT', '/api/tenants/tpa/plugins/faq', ['enabled' => true])->assertStatus(423);
        $this->inside('tpa', fn () => $this->assertFalse(app(PluginStates::class)->enabled('faq')));

        $this->reconfirm($owner, $authenticator);
        $this->assertTrue($this->faq($this->send('PUT', '/api/tenants/tpa/plugins/faq', ['enabled' => true])->assertOk())['enabled']);
        $this->inside('tpa', function () use ($owner): void {
            $this->assertTrue(app(PluginStates::class)->enabled('faq'));
            $this->assertSame($owner->actor(), DomainEvent::query()->where('type', 'plugin.enabled')->sole()->actor);
        });
        // 別の店は変わらない
        $this->assertFalse($this->faq($this->send('GET', '/api/tenants/tpb/plugins'))['enabled']);

        // 入っていない・形の違う名前、形の違う値
        $this->send('PUT', '/api/tenants/tpa/plugins/nope', ['enabled' => true])->assertNotFound()->assertJsonPath('user_message', 'この App はインストールされていません。');
        $this->assertContains($this->send('PUT', '/api/tenants/tpa/plugins/FAQ', ['enabled' => true])->status(), [404, 405]);
        $this->send('PUT', '/api/tenants/tpa/plugins/faq', [])->assertStatus(422)->assertJsonValidationErrors('enabled');
        $this->send('PUT', '/api/tenants/tpa/plugins/faq', ['enabled' => 'yes'])->assertStatus(422);

        $this->assertFalse($this->faq($this->send('PUT', '/api/tenants/tpa/plugins/faq', ['enabled' => false])->assertOk())['enabled']);
    }

    public function test_read_only_staff_see_but_cannot_change_and_other_shops_stay_hidden(): void
    {
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'tpa', 'read', 'test');
        $this->reconfirm($staff, $authenticator);

        $this->send('GET', '/api/tenants/tpa/plugins')->assertOk();
        $this->send('PUT', '/api/tenants/tpa/plugins/faq', ['enabled' => true])->assertForbidden();
        $this->send('GET', '/api/tenants/tpb/plugins')->assertNotFound();
        $this->send('PUT', '/api/tenants/tpb/plugins/faq', ['enabled' => true])->assertNotFound();
        $this->inside('tpa', fn () => $this->assertFalse(app(PluginStates::class)->enabled('faq')));
    }

    /**
     * App の画面の目的ごとのまとまり: 一覧は目的の言葉（核の順）と、App ごとの目的・設定がそろっているかを返す。
     * 設定の無い App は settings が null、ある App は入れるべき項目がそろうまで complete が偽。ほかの店の値は混ざらない
     */
    public function test_the_list_returns_categories_and_whether_settings_are_complete(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $list = $this->send('GET', '/api/tenants/tpa/plugins')->assertOk();

        $this->assertSame(
            ['analytics', 'marketing', 'page', 'selling', 'products', 'payment', 'shipping', 'customers', 'operations', 'other'],
            $list->collect('categories')->pluck('key')->all(),
        );
        $this->assertSame(['key' => 'page', 'label' => 'ページの部品', 'description' => 'ページの編集の画面で、動画・地図・よくある質問などのセクションを足せます。'], $list->json('categories.2'));
        $plugins = $list->collect('plugins')->keyBy('name');
        $this->assertSame(['analytics', 'page', 'marketing', 'page'], [$plugins['ga4']['category'], $plugins['faq']['category'], $plugins['meta-pixel']['category'], $plugins['video']['category']]);
        // どの App も、知っている目的のどれかに入る（画面で迷子にならない）
        $this->assertEmpty($plugins->pluck('category')->diff($list->collect('categories')->pluck('key')));

        // 設定の無い App は null、ID がまだの GA4 はそろっていない
        $this->assertNull($plugins['faq']['settings']);
        $this->assertNull($plugins['video']['settings']);
        $this->assertFalse($plugins['ga4']['settings']['complete']);

        $this->reconfirm($owner, $authenticator);
        $saved = $this->send('PUT', '/api/tenants/tpa/plugins/ga4/settings', ['values' => ['measurement_id' => 'G-ABC123DEF4']])->assertOk();
        $this->assertTrue($saved->collect('plugins')->firstWhere('name', 'ga4')['settings']['complete']);
        $this->assertTrue($this->send('GET', '/api/tenants/tpa/plugins')->collect('plugins')->firstWhere('name', 'ga4')['settings']['complete']);
        // 別の店はそろっていないまま
        $this->assertFalse($this->send('GET', '/api/tenants/tpb/plugins')->collect('plugins')->firstWhere('name', 'ga4')['settings']['complete']);
    }

    /**
     * 一覧の中の faq の行（App が増えると並びの位置が変わるので、名前で引く）
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function faq(TestResponse $response): array
    {
        $row = $response->collect('plugins')->firstWhere('name', 'faq');
        $this->assertIsArray($row);

        return $row;
    }
}
