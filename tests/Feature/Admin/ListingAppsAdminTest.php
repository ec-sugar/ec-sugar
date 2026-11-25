<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\Pricing;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の商品の画面の App の欄（ADR 0050）と、定期便の欄（定期でも買える・周期・値引き）。
 * 出すのはこの店で使っている App のうち欄を持つものだけ。読むのは閲覧、保存は運用から。使っていない・欄の無い App は 404、
 * 形の誤りは欄の名前つきの 422。保存は subscription_plans に、変えた値だけのイベントを同じトランザクションで残す。ほかの店の商品は見えない
 */
final class ListingAppsAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const array ON = ['enabled' => true, 'interval_weekly' => false, 'interval_biweekly' => true, 'interval_monthly' => true, 'interval_bimonthly' => false, 'discount' => 'pct10'];

    protected function setUp(): void
    {
        parent::setUp();
        // 欄の例は定期便の App（公開用の 1.0 の木には入らない。ADR 0098）。欄の口そのものは、ほかの App の欄のテストも確かめる
        $this->requiresApps('subscription');
        $this->configureAdminHost();
        $this->tenant('lpa');
        $this->tenant('lpb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_subscription_panel_appears_only_when_the_app_is_in_use_and_saves_with_an_event(): void
    {
        [$owner] = $this->enrolledOperator();
        $listing = $this->listing('lpa');
        $path = "/api/tenants/lpa/listings/{$listing}/apps";

        // 使っていない店では欄が無く、保存も 404
        $this->send('GET', $path)->assertOk()->assertExactJson(['apps' => []]);
        $this->send('PUT', "{$path}/subscription", ['values' => self::ON])->assertNotFound();

        $this->use('lpa', 'subscription');
        $app = $this->send('GET', $path)->assertOk()->json('apps.0');
        $this->assertSame(['subscription', '定期便'], [$app['plugin'], $app['title']]);
        $this->assertSame(['enabled', 'interval_weekly', 'interval_biweekly', 'interval_monthly', 'interval_bimonthly', 'discount'], array_column($app['fields'], 'key'));
        $this->assertSame(['toggle', 'toggle', 'toggle', 'toggle', 'toggle', 'choice'], array_column($app['fields'], 'type'));
        $this->assertSame(['off', 'pct5', 'pct10', 'pct15', 'pct20'], array_keys($app['fields'][5]['options']));
        // まだ設定の無い商品: 定期にしない・周期は毎月を選んでおく・値引きなし
        $this->assertSame(['enabled' => false, 'interval_weekly' => false, 'interval_biweekly' => false, 'interval_monthly' => true, 'interval_bimonthly' => false, 'discount' => 'off'], $app['values']);

        $saved = $this->send('PUT', "{$path}/subscription", ['values' => self::ON])->assertOk()->json('app.values');
        $this->assertSame(self::ON, $saved);
        $this->assertSame(self::ON, $this->send('GET', $path)->json('apps.0.values'));

        $this->inside('lpa', function () use ($listing, $owner): void {
            $plan = DB::table('subscription_plans')->where('listing_id', $listing)->sole();
            $this->assertTrue((bool) $plan->enabled);
            $this->assertSame(['biweekly', 'monthly'], json_decode($plan->intervals, true));
            $this->assertSame(1000, (int) $plan->discount_bp);

            $event = DomainEvent::query()->where('type', 'subscription.plan_updated')->sole();
            $this->assertSame(['subscription_plan', $plan->id, $owner->actor()], [$event->aggregate_type, $event->aggregate_id, $event->actor]);
            // 中身は商品の ID と変えた値だけ（jsonb は鍵の順を変えるので、順は見ない）
            $this->assertEquals(['listing_id' => $listing, 'enabled' => true, 'intervals' => ['biweekly', 'monthly'], 'discount_bp' => 1000], $event->payload);
            $this->assertNull(app(EventStore::class)->verify()->brokenAt);
        });

        // 書かなかった欄は今の値のまま。変えた値だけが記録に残る。何も変わらなければ記録しない
        $this->send('PUT', "{$path}/subscription", ['values' => ['discount' => 'pct5']])->assertOk()->assertJsonPath('app.values.discount', 'pct5')->assertJsonPath('app.values.interval_monthly', true);
        $this->send('PUT', "{$path}/subscription", ['values' => ['discount' => 'pct5']])->assertOk();
        $this->inside('lpa', function () use ($listing): void {
            $payloads = DomainEvent::query()->where('type', 'subscription.plan_updated')->orderBy('sequence')->pluck('payload')->all();
            $this->assertCount(2, $payloads);
            $this->assertEquals(['listing_id' => $listing, 'discount_bp' => 500], $payloads[1]);
        });

        // 定期をやめるときは周期が 0 個でもよい
        $off = ['enabled' => false, 'interval_weekly' => false, 'interval_biweekly' => false, 'interval_monthly' => false, 'interval_bimonthly' => false];
        $this->send('PUT', "{$path}/subscription", ['values' => $off])->assertOk()->assertJsonPath('app.values.enabled', false);
        // 別の店は変わらない（使っていない）
        $this->inside('lpb', fn () => $this->assertSame(0, DB::table('subscription_plans')->count()));
    }

    public function test_wrong_values_are_refused_with_the_field_name(): void
    {
        $this->enrolledOperator();
        $this->use('lpa', 'subscription');
        $listing = $this->listing('lpa');
        $path = "/api/tenants/lpa/listings/{$listing}/apps/subscription";

        // 定期でも買えるのに周期が 0 個
        $none = ['interval_weekly' => false, 'interval_biweekly' => false, 'interval_monthly' => false, 'interval_bimonthly' => false];
        $this->send('PUT', $path, ['values' => ['enabled' => true] + $none])->assertStatus(422)->assertJsonValidationErrors('values.interval_weekly');
        // 値引きは選択肢の中だけ（50% や数そのものは断る）
        $this->send('PUT', $path, ['values' => ['discount' => 'pct50']])->assertStatus(422)->assertJsonValidationErrors('values.discount');
        $this->send('PUT', $path, ['values' => ['discount' => 5000]])->assertStatus(422)->assertJsonValidationErrors('values.discount');
        // 切り替えは真偽だけ、知らない欄・欄の数より多い値・形の違う本体は断る
        $this->send('PUT', $path, ['values' => ['enabled' => 'yes']])->assertStatus(422)->assertJsonValidationErrors('values.enabled');
        $this->send('PUT', $path, ['values' => ['price' => 1]])->assertStatus(422)->assertJsonValidationErrors('values');
        $this->send('PUT', $path, ['values' => array_fill_keys(array_map(static fn (int $i): string => "k{$i}", range(1, 7)), true)])->assertStatus(422)->assertJsonValidationErrors('values');
        $this->send('PUT', $path, [])->assertStatus(422)->assertJsonValidationErrors('values');
        $this->send('PUT', $path, ['values' => 'on'])->assertStatus(422)->assertJsonValidationErrors('values');

        // 値段が見積もりで決まる商品は定期にできない（やめる保存はできる）
        $quoted = $this->inside('lpa', fn (): string => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'pricing' => Pricing::Quoted, 'title' => '見積もりの品', 'status' => ListingStatus::Active])->id);
        $this->send('PUT', "/api/tenants/lpa/listings/{$quoted}/apps/subscription", ['values' => ['enabled' => true]])->assertStatus(422)->assertJsonValidationErrors('values.enabled');
        $this->send('PUT', "/api/tenants/lpa/listings/{$quoted}/apps/subscription", ['values' => ['enabled' => false, 'discount' => 'pct5']])->assertOk();

        $this->inside('lpa', function () use ($listing): void {
            $this->assertSame(0, DB::table('subscription_plans')->where('listing_id', $listing)->count());
            $this->assertSame(1, DomainEvent::query()->where('type', 'subscription.plan_updated')->count());
        });
    }

    public function test_apps_without_a_panel_unknown_apps_and_other_shops_listings_are_not_found(): void
    {
        $this->enrolledOperator();
        $this->use('lpa', 'subscription');
        $this->use('lpa', 'faq');
        $listing = $this->listing('lpa');
        $path = "/api/tenants/lpa/listings/{$listing}/apps";

        // 使っていても欄の無い App（よくある質問）は出さず、保存は 404。入っていない名前も同じ
        $this->assertSame(['subscription'], $this->send('GET', $path)->assertOk()->collect('apps')->pluck('plugin')->all());
        $this->send('PUT', "{$path}/faq", ['values' => []])->assertNotFound();
        $this->send('PUT', "{$path}/nope", ['values' => []])->assertNotFound();
        $this->assertContains($this->send('PUT', "{$path}/Subscription", ['values' => []])->status(), [404, 405]);

        // ほかの店の商品は、その店の道筋からは見つからない（オーナーでも）
        $this->use('lpb', 'subscription');
        $this->send('GET', "/api/tenants/lpb/listings/{$listing}/apps")->assertNotFound();
        $this->send('PUT', "/api/tenants/lpb/listings/{$listing}/apps/subscription", ['values' => self::ON])->assertNotFound();
        $this->inside('lpb', fn () => $this->assertSame(0, DB::table('subscription_plans')->count()));
    }

    public function test_read_only_staff_see_the_panel_but_cannot_save_and_operators_can(): void
    {
        $this->use('lpa', 'subscription');
        $listing = $this->listing('lpa');
        $path = "/api/tenants/lpa/listings/{$listing}/apps";

        [$reader] = $this->enrolledOperator('reader@example.com', 'staff');
        app(OperatorAccess::class)->grant($reader, 'lpa', 'read', 'test');
        $this->send('GET', $path)->assertOk()->assertJsonPath('apps.0.plugin', 'subscription');
        $this->send('PUT', "{$path}/subscription", ['values' => self::ON])->assertForbidden();
        // 任されていない店は無いのと同じ
        $this->send('GET', "/api/tenants/lpb/listings/{$listing}/apps")->assertNotFound();
        $this->inside('lpa', fn () => $this->assertSame(0, DB::table('subscription_plans')->count()));

        $this->send('POST', '/auth/logout')->assertSuccessful();
        [$operator] = $this->enrolledOperator('operator@example.com', 'staff');
        app(OperatorAccess::class)->grant($operator, 'lpa', 'operate', 'test');
        $this->send('PUT', "{$path}/subscription", ['values' => self::ON])->assertOk();
        $this->inside('lpa', fn () => $this->assertSame($operator->actor(), DomainEvent::query()->where('type', 'subscription.plan_updated')->sole()->actor));
    }

    private function use(string $tenant, string $plugin): void
    {
        $this->inside($tenant, fn () => app(PluginStates::class)->set($plugin, true, 'test'));
    }

    private function listing(string $tenant): string
    {
        return $this->inside($tenant, fn (): string => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'コーヒー豆', 'unit_amount' => 1800, 'status' => ListingStatus::Active])->id);
    }
}
