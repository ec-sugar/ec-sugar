<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Catalog\Models\Category;
use App\Core\Event\EventStore;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PlaceOrder;
use Carbon\CarbonImmutable;
use EcSugar\PluginRecommendations\Rankings;
use EcSugar\PluginRecommendations\RecentlyViewed;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「おすすめの区画」（ADR 0071）。ページビルダーに足す 3 つの区画（関連商品・最近見た商品・売れ筋ランキング）。
 * 使う店でだけ置ける、出すのは出ていていま買える商品だけ（下書き・売り切れ・公開を終えた商品は出さない）、
 * 最近見た商品の Cookie の改ざん（知らない ID・ほかの店の ID・多すぎる・形の違う値）、ランキングの数え方（払われずに消えた注文・取消を数えない）、
 * ショップのページでは注文を数えない
 */
final class RecommendationsAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    /** @var array<string, string> 名前 => 商品の ID */
    private array $items = [];

    private ?string $recent = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        FakeStripe::install();
        $this->configureAdminHost();
        $this->tenant('rca');
        $this->enrolledOperator();
        $this->items = $this->inside('rca', function (): array {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            $cups = Category::create(['name' => 'うつわ', 'slug' => 'cups', 'position' => 0]);
            $bags = Category::create(['name' => 'かばん', 'slug' => 'bags', 'position' => 1]);
            $items = [];

            // 出ていて買える: マグ・湯のみ（うつわ）、トート（かばん）。売り切れ: 小皿（うつわ）。下書き: 大皿（うつわ）
            foreach ([['mug', 'うつわ', $cups, 20], ['yunomi', 'うつわ', $cups, 20], ['tote', 'かばん', $bags, 20], ['kozara', 'うつわ', $cups, 0], ['ozara', 'うつわ', $cups, 20, ListingStatus::Draft]] as $row) {
                [$name, , $category, $stock] = $row;
                $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => "品 {$name}", 'unit_amount' => 1000, 'status' => $row[4] ?? ListingStatus::Active]);
                DB::table('listing_categories')->insert(['listing_id' => $listing->id, 'category_id' => $category->id]);

                if ($stock > 0) {
                    app(StockService::class)->receive($listing, Location::query()->firstOrFail(), $stock);
                }

                $items[$name] = $listing->id;
                $this->travel(1)->minutes();
            }

            return $items;
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_sections_can_be_placed_only_where_the_app_is_used_and_show_buyable_products_only(): void
    {
        $page = $this->send('GET', '/api/tenants/rca/themes/default/pages/home')->assertOk();
        $this->assertNotContains('app_recommendations_related', $page->json('page.types'));

        $this->use();
        $page = $this->send('GET', '/api/tenants/rca/themes/default/pages/home')->assertOk();
        foreach (['app_recommendations_related', 'app_recommendations_recent', 'app_recommendations_ranking'] as $type) {
            $this->assertContains($type, $page->json('page.types'));
            $this->assertSame('おすすめのセクション', $page->json("schema.{$type}.app"));
        }
        $this->publishHome();

        // まだ何も見ていない・まだ売れていない: 関連商品は新着（いま買えるものだけ）、最近見た・ランキングは出さない
        $html = $this->page('/');
        $this->assertSame([$this->items['tote'], $this->items['yunomi'], $this->items['mug']], $this->cards($html, 'app_recommendations_related'));
        $this->assertStringNotContainsString('data-section-type="app_recommendations_recent"', $html);
        $this->assertStringNotContainsString('data-section-type="app_recommendations_ranking"', $html);

        // 使うのをやめた店では出さず、見た記録も書かない
        $this->inside('rca', fn () => app(PluginStates::class)->set('recommendations', false, 'test'));
        $this->assertStringNotContainsString('app_recommendations_', $this->page('/'));
        $this->assertNull($this->shop('GET', "/products/{$this->items['mug']}")->getCookie(RecentlyViewed::COOKIE));
    }

    public function test_recently_viewed_products_are_kept_in_the_browser_only_and_related_follows_the_last_one(): void
    {
        $this->use();
        $this->publishHome();

        // 見た順に Cookie の先頭へ（HttpOnly。お店には何も残さない）
        $first = $this->shop('GET', "/products/{$this->items['tote']}");
        $this->assertTrue($first->getCookie(RecentlyViewed::COOKIE)?->isHttpOnly());
        $this->shop('GET', "/products/{$this->items['mug']}");
        $this->shop('GET', "/products/{$this->items['kozara']}");
        $this->assertSame([$this->items['kozara'], $this->items['mug'], $this->items['tote']], explode('.', (string) $this->recent));

        // 最近見た: 新しい順で、売り切れ（小皿）は出さない。関連: 最後に見た商品（小皿）と同じうつわの、いま買えるもの → 足りない分は新着
        $html = $this->page('/');
        $this->assertSame([$this->items['mug'], $this->items['tote']], $this->cards($html, 'app_recommendations_recent'));
        $this->assertSame([$this->items['yunomi'], $this->items['mug'], $this->items['tote']], $this->cards($html, 'app_recommendations_related'));

        // 見た記録はお店の表に書かない（App の表は数えたランキングだけ）
        $this->assertSame(0, $this->inside('rca', fn (): int => DB::table('recommendations_ranks')->count()));
    }

    public function test_a_tampered_recent_cookie_shows_only_this_shops_buyable_products(): void
    {
        $this->use();
        $this->publishHome();
        $this->tenant('rcb');
        $other = $this->inside('rcb', fn (): string => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ほかの店の品', 'unit_amount' => 900, 'status' => ListingStatus::Active])->id);

        // 知らない ID・ほかの店の ID・下書き・売り切れ・形の違う値・大文字は落ち、出ていていま買えるものだけが残る
        $this->recent = implode('.', [strtolower((string) Str::ulid()), $other, $this->items['ozara'], $this->items['kozara'], '../../etc', strtoupper($this->items['tote']), $this->items['mug']]);
        $this->assertSame([$this->items['mug']], $this->cards($this->page('/'), 'app_recommendations_recent'));

        // 多すぎる: 先頭の 20 件だけを読む（21 件目より後ろの本物の ID は出ない）
        $this->recent = implode('.', [...array_map(static fn (): string => strtolower((string) Str::ulid()), range(1, RecentlyViewed::MAX)), $this->items['mug']]);
        $this->assertStringNotContainsString('data-section-type="app_recommendations_recent"', $this->page('/'));

        // 長すぎる値は丸ごと捨てる
        $this->recent = $this->items['mug'].'.'.str_repeat('a', 2000);
        $this->assertStringNotContainsString('data-section-type="app_recommendations_recent"', $this->page('/'));

        // 見た時に足すときも、形の違う値は落として 20 件まで
        $this->recent = implode('.', [...array_map(static fn (): string => strtolower((string) Str::ulid()), range(1, RecentlyViewed::MAX)), 'x']);
        $this->shop('GET', "/products/{$this->items['tote']}");
        $ids = explode('.', (string) $this->recent);
        $this->assertCount(RecentlyViewed::MAX, $ids);
        $this->assertSame($this->items['tote'], $ids[0]);
    }

    public function test_the_ranking_counts_paid_orders_of_the_last_thirty_days_and_not_expired_or_cancelled_ones(): void
    {
        $this->use();
        $this->publishHome();

        // 払った: 湯のみ 3・マグ 1・トート 2（トートは後で取り消す）。払われずに消えた: マグ 9
        $this->pay($this->order(['yunomi' => 3]));
        $this->pay($this->order(['mug' => 1]));
        $cancelled = $this->order(['tote' => 2]);
        $this->pay($cancelled);
        $this->expire($this->order(['mug' => 9]));
        $this->inside('rca', fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($cancelled), 'operator:test'));

        // ショップは数える前は出さず、数えた後は数えた表を読むだけ
        $this->assertStringNotContainsString('data-section-type="app_recommendations_ranking"', $this->page('/'));
        $this->artisan('recommendations:rank')->assertSuccessful();
        $this->assertSame([[$this->items['yunomi'], 1, 3], [$this->items['mug'], 2, 1]], $this->ranks());
        $this->assertSame([$this->items['yunomi'], $this->items['mug']], $this->cards($this->page('/'), 'app_recommendations_ranking'));

        // 何度数えても同じ。30 日より前の注文は数えない
        $this->artisan('recommendations:rank')->assertSuccessful();
        $this->assertSame([[$this->items['yunomi'], 1, 3], [$this->items['mug'], 2, 1]], $this->ranks());
        $this->travel(31)->days();
        $this->inside('rca', fn () => app(Rankings::class)->count(CarbonImmutable::now()));
        $this->assertSame([], $this->ranks());
    }

    public function test_the_shop_page_does_not_count_orders_and_reads_little(): void
    {
        $this->use();
        $this->publishHome();
        $this->pay($this->order(['yunomi' => 1]));
        $this->artisan('recommendations:rank')->assertSuccessful();
        $this->shop('GET', "/products/{$this->items['mug']}");

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $html = $this->page('/');
        $this->assertNotSame([], $this->cards($html, 'app_recommendations_ranking'));
        $this->assertNotSame([], $this->cards($html, 'app_recommendations_recent'));

        // 注文の明細と取引は読まない（数えるのは 1 時間ごとの処理だけ）
        $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'transaction_lines') || str_contains($sql, '"transactions"'))));
        // 数えた表は 1 回だけ読む（区画ごとに数え直さない）
        $this->assertCount(1, array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'recommendations_ranks')));
    }

    private function use(): void
    {
        $this->inside('rca', fn () => app(PluginStates::class)->set('recommendations', true, 'test'));
    }

    /**
     * トップの後ろに 3 つの区画を足して公開する
     */
    private function publishHome(): void
    {
        $page = $this->send('GET', '/api/tenants/rca/themes/default/pages/home')->assertOk();
        $add = array_map(static fn (string $type): array => ['type' => $type, 'pattern' => 'grid', 'values' => ['heading' => $type, 'count' => 'eight']],
            ['app_recommendations_related', 'app_recommendations_recent', 'app_recommendations_ranking']);
        $saved = $this->send('PUT', '/api/tenants/rca/themes/default/pages/home/draft', ['version_id' => $page->json('version.id'), 'revision' => (int) ($page->json('version.revision') ?? 0), 'sections' => [...$page->json('sections'), ...$add]])->assertOk();
        $this->send('POST', '/api/tenants/rca/themes/default/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => $saved->json('version.revision')])->assertOk();
    }

    /**
     * 区画に並んだ商品の ID（並びの順）
     *
     * @return list<string>
     */
    private function cards(string $html, string $type): array
    {
        if (preg_match('#data-section-type="'.$type.'".*?</section>#s', $html, $section) !== 1) {
            return [];
        }

        preg_match_all('#data-test="recommended-card" data-listing="([0-9a-z]{26})"#', $section[0], $m);

        return $m[1];
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function ranks(): array
    {
        return $this->inside('rca', fn (): array => DB::table('recommendations_ranks')->orderBy('position')->get()
            ->map(static fn (object $r): array => [(string) $r->listing_id, (int) $r->position, (int) $r->units])->all());
    }

    /**
     * @param  array<string, int>  $items  名前ごとの数
     */
    private function order(array $items): string
    {
        return $this->inside('rca', function () use ($items): string {
            $carts = app(CartService::class);
            [$cart] = $carts->create();

            foreach ($items as $name => $quantity) {
                $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->items[$name])->value('id'), $quantity);
            }

            $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
            $this->assertNotNull($input);

            return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), CheckoutSummary::fingerprintOf($carts->view($cart)))->id;
        });
    }

    private function pay(string $order): void
    {
        $this->inside('rca', function () use ($order): void {
            $sale = Transaction::query()->findOrFail($order);
            app(PaymentService::class)->startCheckout($sale, 'x', 'y');
            $ref = PaymentAttempt::query()->where('transaction_id', $order)->sole()->provider_ref;
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$order, 'checkout.session.completed', $ref, $order, $sale->total_amount, 'jpy', 'pi_'.$order));
        });
    }

    /**
     * 払われないまま期限で取り消す（期限切れの片付けと同じ道）
     */
    private function expire(string $order): void
    {
        $this->inside('rca', function () use ($order): void {
            DB::transaction(function () use ($order): void {
                app(EventStore::class)->lock();
                app(PaymentNotices::class)->cancelUnpaidOrder(Transaction::query()->lockForUpdate()->findOrFail($order), 'system:expiry');
            });
        });
    }

    private function page(string $path): string
    {
        return (string) $this->shop('GET', $path)->assertOk()->getContent();
    }

    /**
     * ショップへ（最近見た商品の Cookie を持ち回る。Cookie はテストの道具がショップの鍵で暗号化して送る）
     *
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->recent !== null) {
            $this->withCookie(RecentlyViewed::COOKIE, $this->recent);
        }

        $this->app['cookie']->flushQueuedCookies();
        $response = $method === 'GET' ? $this->get("http://rca.localhost{$path}") : $this->post("http://rca.localhost{$path}");
        tenancy()->end();
        $cookie = $response->getCookie(RecentlyViewed::COOKIE);

        if ($cookie !== null) {
            $this->recent = (string) $cookie->getValue();
        }

        return $response;
    }
}
