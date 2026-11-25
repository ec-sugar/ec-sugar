<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\OperatorWelcome;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\ShopHome;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Section\SectionValues;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeMedia;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面のホーム: 開設の手順の判定（店のデータから自動で）・数（今日の注文・今月の売上・平均注文単価）・やること・最近の注文・
 * 「ようこそ」を閉じた印（運営ごと・店ごと）。どれも店の中だけで、ほかの店の数が混ざらない・入れない店は 404・個人情報を返さない
 */
final class ShopHomeTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string OPERATOR = 'operator:01aaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_new_shop_has_no_step_done_and_each_step_turns_done_from_the_shop_data(): void
    {
        $this->tenant('hsa');
        // 新しい店は準備中（テストの土台は店を公開にして作るので戻す）
        $this->inside('hsa', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, self::OPERATOR));
        $this->enrolledOperator();

        $setup = $this->send('GET', '/api/tenants/hsa/setup')->assertOk();
        $this->assertSame(['legal', 'shipping', 'payment', 'listing', 'design', 'publish', 'customers'], $setup->json('steps.*.key'));
        $this->assertSame([false, false, false, false, false, false, false], $setup->json('steps.*.done'));
        $this->assertSame([0, 7, false], [$setup->json('done'), $setup->json('total'), $setup->json('opened')]);
        $this->assertSame(['installed' => false, 'remaining' => null], $setup->json('samples'));

        // 下書きの商品では「最初の商品」は済にならない。公開すると済
        $listing = $this->inside('hsa', fn (): Listing => Listing::create(['seller_id' => Seller::query()->firstOrFail()->id, 'kind' => 'product', 'title' => '下書き', 'unit_amount' => 500, 'status' => ListingStatus::Draft]));
        $this->assertFalse($this->step('hsa', 'listing'));
        $this->inside('hsa', fn () => app(ListingService::class)->update($listing, $listing->version, ['status' => ListingStatus::Active], self::OPERATOR));
        $this->assertTrue($this->step('hsa', 'listing'));

        // 表記・送料（同じ 0 円でも、保存したら済）・支払い（テストの鍵でも済、モードを知らせる）
        $this->inside('hsa', function (): void {
            app(ShopSettingsService::class)->setLegal(['items' => [['label' => '販売業者', 'value' => '株式会社テスト']]], self::OPERATOR);
            app(ShopSettingsService::class)->setShipping(['fee' => 600, 'free_over' => null], self::OPERATOR);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForHomeTest00001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForHomeTest00001234'])], self::OPERATOR);
        });
        $setup = $this->send('GET', '/api/tenants/hsa/setup')->assertOk();
        $this->assertSame([true, true, true], [$this->stepOf($setup->json(), 'legal'), $this->stepOf($setup->json(), 'shipping'), $this->stepOf($setup->json(), 'payment')]);
        $this->assertSame('test', $setup->json('steps.2.mode'));

        // デザイン: 下書きだけでは未、公開すると済
        $this->inside('hsa', function (): void {
            $schema = app(ThemeRegistry::class)->get('default')->schema();
            $content = app(ThemeContentService::class);
            $content->savePage('default', $schema, 'home', null, 0, SectionValues::defaultPage($schema, ThemeContentService::pageSpec($schema, 'home')), self::OPERATOR);
        });
        $this->assertFalse($this->step('hsa', 'design'));
        $this->inside('hsa', function (): void {
            $schema = app(ThemeRegistry::class)->get('default')->schema();
            $version = ThemeVersion::query()->where('theme', 'default')->firstOrFail();
            app(ThemeContentService::class)->publish('default', $schema, $version->id, $version->revision, self::OPERATOR);
        });
        $this->assertTrue($this->step('hsa', 'design'));

        // お客さんを集める: タグの App を使うだけでは未（ID が無いと差し込まれない）、ID を入れると済
        $this->inside('hsa', fn () => app(PluginStates::class)->set('ga4', true, self::OPERATOR));
        $this->assertFalse($this->step('hsa', 'customers'));
        $this->inside('hsa', fn () => app(PluginSettings::class)->update('ga4', ['measurement_id' => 'G-ABC123DEF4'], self::OPERATOR));
        $this->assertTrue($this->step('hsa', 'customers'));

        // 公開
        $this->inside('hsa', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, self::OPERATOR));
        $setup = $this->send('GET', '/api/tenants/hsa/setup')->assertOk();
        $this->assertSame([7, true], [$setup->json('done'), $setup->json('opened')]);
    }

    public function test_samples_alone_do_not_finish_the_steps_but_edited_samples_count_as_the_shops_own(): void
    {
        $this->tenant('hsb');
        $this->artisan('shop:sample', ['id' => 'hsb'])->assertSuccessful();
        $this->enrolledOperator();

        // サンプルの表記・送料・商品・テーマの中身だけでは、どれも済にしない
        $setup = $this->send('GET', '/api/tenants/hsb/setup')->assertOk()->json();
        foreach (['legal', 'shipping', 'listing', 'design'] as $key) {
            $this->assertFalse($this->stepOf($setup, $key), "サンプルだけで「{$key}」が済になった");
        }
        $this->assertTrue($setup['samples']['installed']);
        $this->assertSame(8, $setup['samples']['remaining']['listings']);

        // サンプルの商品を書き換えたら、お店の商品として「最初の商品」が済（まとめて消すときも残る物）
        $this->inside('hsb', function (): void {
            $tote = Listing::query()->where('title', '帆布のトートバッグ（サンプル）')->sole();
            app(ListingService::class)->update($tote, $tote->version, ['title' => '帆布のトートバッグ'], self::OPERATOR);
        });
        $this->assertTrue($this->step('hsb', 'listing'));

        // お店がサンプルの送料を確かめて保存し直すと済（同じ額でも、記録の主がお店に変わる）
        $this->inside('hsb', fn () => app(ShopSettingsService::class)->setShipping(['fee' => 900, 'free_over' => 5000], self::OPERATOR));
        $this->assertTrue($this->step('hsb', 'shipping'));

        // 表記を書き換えると済
        $this->inside('hsb', function (): void {
            $items = app(ShopSettingsService::class)->legal();
            $items[0]['value'] = '株式会社みどり';
            app(ShopSettingsService::class)->setLegal(['items' => $items], self::OPERATOR);
        });
        $this->assertTrue($this->step('hsb', 'legal'));

        // サンプルのテーマの中身を書き換えて公開すると済
        $this->inside('hsb', function (): void {
            $schema = app(ThemeRegistry::class)->get('default')->schema();
            $content = app(ThemeContentService::class);
            $version = ThemeVersion::query()->where('theme', 'default')->firstOrFail();
            $version = $content->savePage('default', $schema, 'home', $version->id, $version->revision, SectionValues::defaultPage($schema, ThemeContentService::pageSpec($schema, 'home')), self::OPERATOR);
            $content->publish('default', $schema, $version->id, $version->revision, self::OPERATOR);
        });
        $this->assertTrue($this->step('hsb', 'design'));
    }

    public function test_the_numbers_count_only_paid_real_orders_by_japanese_day_and_month_in_integers(): void
    {
        $this->tenant('hsc');
        $this->tenant('hsd');
        $at = static fn (string $jst): CarbonImmutable => CarbonImmutable::parse($jst, 'Asia/Tokyo');

        // 先月の終わり（日本の 10/31 23:30 = UTC 14:30）と今月の初め（日本の 11/1 0:30 = UTC 10/31 15:30）
        $this->travelTo($at('2026-10-31 23:30'));
        $this->sale('hsc', 3000, paid: true);
        $this->travelTo($at('2026-11-01 00:30'));
        $this->sale('hsc', 1000, paid: true);
        $this->sale('hsc', 2001, paid: true);
        // 0 円の受付済みは件数に入り、売上は 0
        $this->sale('hsc', 0, paid: true);
        // 入金待ち・払った後の取消・返金・テストの注文は数えない
        $this->sale('hsc', 5000, paid: false);
        $this->sale('hsc', 7000, paid: true, then: [Phase::Cancel]);
        $this->sale('hsc', 9000, paid: true, then: [Phase::Dispute, Phase::Refund]);
        $this->sale('hsc', 4000, paid: true, ref: 'cs_test_home1', test: true);
        // 本番の支払いの画面で払われた注文は数える
        $this->sale('hsc', 500, paid: true, ref: 'cs_live_home1');
        // 昨日（11/1）の注文。今月には入り、今日には入らない
        $this->travelTo($at('2026-11-02 09:00'));
        $this->sale('hsc', 100, paid: true);
        // ほかの店の注文は混ざらない
        $this->sale('hsd', 99999, paid: true);

        $this->travelTo($at('2026-11-02 23:59'));
        $this->enrolledOperator();
        $stats = $this->send('GET', '/api/tenants/hsc/overview')->assertOk()->json('stats');
        $this->assertSame(['orders' => 1, 'sales' => 100], $stats['today']);
        // 今月: 1000 + 2001 + 0 + 500 + 100 = 3601 を 5 件。平均は 720.2 → 720（切り捨て）
        $this->assertSame(['orders' => 5, 'sales' => 3601, 'average' => 720], $stats['month']);
        $this->assertSame(1, $stats['test_orders']);

        // 月が替わると今月は 0 件で、平均は無し（日本の 12/1 0:00 ちょうどから 12 月）
        $this->inside('hsc', function () use ($at): void {
            $this->assertSame(['orders' => 0, 'sales' => 0, 'average' => null], app(ShopHome::class)->stats($at('2026-12-01 00:00'))['month']);
            $this->assertSame(['orders' => 5, 'sales' => 3601, 'average' => 720], app(ShopHome::class)->stats($at('2026-11-30 23:59:59'))['month']);
        });

        // ほかの店はその店の注文だけ
        $other = $this->send('GET', '/api/tenants/hsd/overview')->assertOk()->json('stats');
        $this->assertSame(['orders' => 1, 'sales' => 99999, 'average' => 99999], $other['month']);
    }

    public function test_the_todo_and_recent_orders_carry_no_personal_data(): void
    {
        $this->tenant('hse');
        $this->sale('hse', 1200, paid: true, customer: true);
        $this->sale('hse', 0, paid: true);
        $this->sale('hse', 800, paid: false);
        $this->sale('hse', 4000, paid: true, ref: 'cs_test_home2', test: true);
        // 在庫の無い公開中の商品（下書きは数えない）
        $this->inside('hse', function (): void {
            $seller = Seller::query()->firstOrFail();
            Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '売り切れ', 'unit_amount' => 500, 'status' => ListingStatus::Active]);
            Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '下書き', 'unit_amount' => 500, 'status' => ListingStatus::Draft]);
        });
        $this->enrolledOperator();

        $overview = $this->send('GET', '/api/tenants/hse/overview')->assertOk();
        // 入金済み 2（1200 とテストの 4000）・受付済み 1。在庫切れは「売り切れ」だけ（sale が作った商品は在庫を持つ）
        // 返金で止めたギフトカード・返しきれなかった送料の差額は無い（ADR 0076）。AI エージェントの承認待ちの案も無い（ADR 0101）
        $this->assertSame(['to_ship' => 2, 'to_ship_cod' => 0, 'to_ship_free' => 1, 'out_of_stock' => 1, 'bank_awaiting' => 0, 'bank_due_soon' => 0, 'cod_to_confirm' => 0, 'gift_cards_stopped' => 0, 'shipping_unreturned' => 0, 'agent_proposals' => 0], $overview->json('todo'));
        $recent = $overview->json('recent');
        $this->assertCount(4, $recent);
        $this->assertSame(['paid', 'awaiting_payment', 'confirmed', 'paid'], array_column($recent, 'order_status'));
        $this->assertSame([true, false, false, false], array_column($recent, 'test'));
        // 明細の数（商品の 1 行）
        $this->assertSame([1, 1, 1, 1], array_column($recent, 'lines_count'));
        $this->assertSame(['id', 'order_status', 'currency', 'total_amount', 'lines_count', 'test', 'created_at'], array_keys($recent[0]));
        $text = (string) json_encode($overview->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('山田', $text);
        $this->assertStringNotContainsString('yamada@example.com', $text);
    }

    public function test_the_welcome_is_remembered_per_operator_and_per_shop(): void
    {
        $this->tenant('hsf');
        $this->tenant('hsg');
        [$owner] = $this->enrolledOperator();

        $this->assertFalse($this->send('GET', '/api/tenants/hsf/setup')->json('welcome.seen'));
        $this->send('PUT', '/api/tenants/hsf/welcome')->assertOk()->assertJsonPath('welcome.seen', true);
        // 二度閉じても 1 行
        $this->send('PUT', '/api/tenants/hsf/welcome')->assertOk();
        $this->assertSame(1, OperatorWelcome::query()->where('operator_id', $owner->id)->count());
        $this->assertTrue($this->send('GET', '/api/tenants/hsf/setup')->json('welcome.seen'));
        // ほかの店ではまだ出る
        $this->assertFalse($this->send('GET', '/api/tenants/hsg/setup')->json('welcome.seen'));

        // ほかの運営（閲覧の段階のスタッフ）には、まだ出る。閲覧でも閉じられる。与えていない店は 404
        $this->signOut();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'hsf', 'read', 'test');
        $this->assertFalse($this->send('GET', '/api/tenants/hsf/setup')->assertOk()->json('welcome.seen'));
        $this->send('PUT', '/api/tenants/hsf/welcome')->assertOk();
        $this->assertTrue($this->send('GET', '/api/tenants/hsf/setup')->json('welcome.seen'));
        $this->send('GET', '/api/tenants/hsg/setup')->assertNotFound();
        $this->send('PUT', '/api/tenants/hsg/welcome')->assertNotFound();
        $this->assertSame(0, OperatorWelcome::query()->where('operator_id', $staff->id)->where('tenant_id', 'hsg')->count());
        // 閲覧の段階でも数とやることは読める（注文の一覧と同じ）
        $this->send('GET', '/api/tenants/hsf/overview')->assertOk()->assertJsonPath('stats.month.orders', 0);

        // ログインしていなければ 401
        $this->signOut();
        $this->send('GET', '/api/tenants/hsf/setup')->assertUnauthorized();
        $this->send('PUT', '/api/tenants/hsf/welcome')->assertUnauthorized();
    }

    /**
     * 物販の注文を 1 つ作る。paid なら入金済み（0 円なら受付済み）へ進め、ref があれば払われた支払いの画面を残す。then のフェーズへさらに進める
     *
     * @param  list<Phase>  $then
     */
    private function sale(string $tenant, int $amount, bool $paid, ?string $ref = null, array $then = [], bool $customer = false, bool $test = false): void
    {
        $this->inside($tenant, function () use ($amount, $paid, $ref, $then, $customer, $test): void {
            $seller = Seller::query()->firstOrFail();
            $store = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => "品 {$amount}", 'unit_amount' => $amount, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($listing, $store, 5);
            $buyer = $customer ? Party::create(['kind' => PartyKind::Customer, 'name' => '山田 太郎', 'email' => 'yamada@example.com']) : null;
            $service = app(TransactionService::class);
            $sale = $service->open('sale', $seller, $buyer, [LineDraft::fromListing($listing, 1, 1000, $store)], actor: 'system');

            if ($paid) {
                $sale = $service->transition($sale, Phase::Payment);
            }

            if ($ref !== null) {
                // test_mode は本物の処理（PaymentService）が鍵のモードから書く列。番号の書き方では見分けない
                PaymentAttempt::create(['transaction_id' => $sale->id, 'provider' => 'stripe', 'provider_ref' => $ref, 'checkout_url' => "https://checkout.stripe.com/c/pay/{$ref}", 'amount' => max(1, $amount), 'currency' => 'JPY', 'status' => 'paid', 'expires_at' => now()->addHour(), 'paid_at' => now(), 'test_mode' => $test]);
            }

            foreach ($then as $phase) {
                $sale = $service->transition($sale, $phase);
            }
        });
    }

    private function signOut(): void
    {
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function step(string $tenant, string $key): bool
    {
        $setup = $this->send('GET', "/api/tenants/{$tenant}/setup")->assertOk()->json();
        $this->assertIsArray($setup);

        return $this->stepOf($setup, $key);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function stepOf(array $setup, string $key): bool
    {
        foreach ($setup['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['done'];
            }
        }

        $this->fail("手順 {$key} がありません");
    }
}
