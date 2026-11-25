<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Plugin\Services\ListingVisibility;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Cart\CartException;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use Carbon\CarbonImmutable;
use EcSugar\PluginSalePeriod\Models\SaleWindow;
use EcSugar\PluginSalePeriod\SalePeriodSchedule;
use EcSugar\PluginSalePeriod\SaleWindows;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「公開の予約と販売期間」。商品ごとの「この日時から出す」「この日時で終わる」が、
 * ショップの一覧・商品の画面・カート・注文の確定でどう効くかを確かめる:
 * 期間の前（一覧に出さない・商品の画面は 404・カートに入らない）／中（ふだんどおり）／後（売り切れ・カートに入らない）、
 * 境界の 1 秒（出す日時と終わる日時のちょうど）、カートに入れた後に期間が切れた注文の確定、App を止めた店では効かないこと、
 * 管理画面の欄の確かめと App の画面の一覧。
 *
 * 時刻は日本時間で入れて UTC で置く。期間は App の表だけに持ち、商品の状態（listings.status）は変えない
 */
final class SalePeriodAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    /** 出す日時（日本時間）。テストはこの 1 時間前から始める */
    private const string OPENS = '2026-12-01 10:00';

    /** 終わる日時（日本時間） */
    private const string CLOSES = '2026-12-10 23:59';

    private string $listing;

    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->travelTo(new CarbonImmutable('2026-12-01 09:00:00', 'Asia/Tokyo'));
        $this->configureAdminHost();
        $this->tenant('spd');
        [$this->listing, $this->variant] = $this->inside('spd', function (): array {
            app(PluginStates::class)->set('sale-period', true, 'test');
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '冬限定のブレンド', 'unit_amount' => 1800, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->orderBy('created_at')->firstOrFail(), 5);

            return [$listing->id, (string) ListingVariant::query()->where('listing_id', $listing->id)->value('id')];
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_before_the_start_the_shop_hides_the_listing_and_the_cart_refuses_it(): void
    {
        $this->schedule(self::OPENS, null);

        // 一覧にも新着にも出ない。商品の画面は無いのと同じ（公開中の商品でも）
        $this->get('http://spd.localhost/products')->assertOk()->assertDontSee('冬限定のブレンド');
        $this->get('http://spd.localhost/')->assertOk()->assertDontSee('冬限定のブレンド');
        $this->get("http://spd.localhost/products/{$this->listing}")->assertNotFound();
        tenancy()->end();

        // カートにも入らない（出していない商品なので、核の窓口が先に断る）
        $this->assertNotNull($this->cartRefusal());

        // 商品そのものは公開のまま（核の状態は変えない）
        $this->assertSame('active', $this->inside('spd', fn (): string => (string) Listing::query()->findOrFail($this->listing)->status->value));
    }

    public function test_the_listing_appears_at_the_exact_start_and_not_one_second_before(): void
    {
        $this->schedule(self::OPENS, null);
        $opens = new CarbonImmutable(self::OPENS, 'Asia/Tokyo');

        // 出す日時の 1 秒前はまだ出さない
        $this->travelTo($opens->subSecond());
        $this->get("http://spd.localhost/products/{$this->listing}")->assertNotFound();
        tenancy()->end();
        $this->assertNotNull($this->cartRefusal());

        // ちょうどの秒に出て、ふだんどおり売れる
        $this->travelTo($opens);
        $this->shop('/products')->assertOk()->assertSee('冬限定のブレンド');
        $this->shop("/products/{$this->listing}")->assertOk()->assertSee('data-test="add-to-cart"', false)->assertDontSee('>売り切れ<', false);
        $this->assertNull($this->cartRefusal());
    }

    public function test_at_the_exact_end_the_product_page_is_sold_out_and_the_cart_refuses_it(): void
    {
        $this->schedule(null, self::CLOSES);
        $closes = new CarbonImmutable(self::CLOSES, 'Asia/Tokyo');

        // 終わる日時の 1 秒前はふだんどおり
        $this->travelTo($closes->subSecond());
        $this->shop("/products/{$this->listing}")->assertOk()->assertSee('data-test="add-to-cart"', false);
        $this->assertNull($this->cartRefusal());

        // ちょうどの秒から売り切れ。一覧には残るが、札も売り切れ
        $this->travelTo($closes);
        $page = $this->shop("/products/{$this->listing}")->assertOk();
        $page->assertSee('売り切れ')->assertDontSee('data-test="add-to-cart"', false);
        $this->shop('/products')->assertOk()->assertSee('冬限定のブレンド')->assertSee('売り切れ');

        // カートは断りの文で断る
        $this->assertSame(SalePeriodSchedule::AFTER, $this->cartRefusal());
    }

    public function test_an_order_is_refused_when_the_period_ended_after_the_cart_was_filled(): void
    {
        $this->schedule(null, self::CLOSES);
        $closes = new CarbonImmutable(self::CLOSES, 'Asia/Tokyo');
        $this->travelTo($closes->subMinute());

        // 期間の中にカートへ入れ、確かめの画面の指紋まで作る
        [$cart, $fingerprint] = $this->inside('spd', function (): array {
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, $this->variant, 1);

            return [$cart, CheckoutSummary::fingerprintOf($carts->view($cart))];
        });

        // 期間が切れた後は、カートの行に断りの文が出て、注文の確定が錠の中で断る
        $this->travelTo($closes);
        $this->inside('spd', function () use ($cart, $fingerprint): void {
            $view = app(CartService::class)->view($cart);
            $this->assertTrue($view->hasUnavailable());
            $this->assertSame(SalePeriodSchedule::AFTER, $view->lines[0]['notice']);

            $input = CheckoutInput::fromArray(['buyer' => self::buyer(), 'recipient' => null]);
            $this->assertNotNull($input);

            try {
                app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);
                $this->fail('期間が切れた商品で注文が確定した');
            } catch (OrderException $e) {
                $this->assertSame(PlaceOrder::UNAVAILABLE, $e->getMessage());
            }
        });
    }

    public function test_a_shop_that_turned_the_app_off_sells_the_listing_as_usual(): void
    {
        $this->schedule(self::OPENS, self::CLOSES);
        $this->travelTo((new CarbonImmutable(self::CLOSES, 'Asia/Tokyo'))->addDay());

        // 使っている間は売り切れ
        $this->shop("/products/{$this->listing}")->assertOk()->assertSee('売り切れ');
        $this->assertSame(SalePeriodSchedule::AFTER, $this->cartRefusal());

        // 止めると期間は効かない（行は残る）
        $this->inside('spd', fn () => app(PluginStates::class)->set('sale-period', false, 'test'));
        $this->shop("/products/{$this->listing}")->assertOk()->assertSee('data-test="add-to-cart"', false);
        $this->assertNull($this->cartRefusal());
        $this->assertSame(1, $this->inside('spd', fn (): int => SaleWindow::query()->count()));

        // 出す日時の前の商品も、止めた店ではふだんどおり出る
        $this->travelTo(new CarbonImmutable('2026-11-01 10:00:00', 'Asia/Tokyo'));
        $this->shop('/products')->assertOk()->assertSee('冬限定のブレンド');
    }

    public function test_the_owner_sets_the_period_on_the_product_and_sees_it_on_the_app_page(): void
    {
        [$owner, $key] = $this->enrolledOperator();
        $panel = "/api/tenants/spd/listings/{$this->listing}/apps/sale-period";
        $records = '/api/tenants/spd/apps/sale-period/records';

        // 形の違う日時・ありえない日時・終わりが先の日時は、欄の名前つきで断る
        $this->send('PUT', $panel, ['values' => ['starts_at' => '2026/12/01 10:00', 'ends_at' => null]])->assertUnprocessable()->assertJsonValidationErrors(['values.starts_at']);
        $this->send('PUT', $panel, ['values' => ['starts_at' => '2026-13-45 10:00', 'ends_at' => null]])->assertUnprocessable()->assertJsonValidationErrors(['values.starts_at']);
        $this->send('PUT', $panel, ['values' => ['starts_at' => '2026-12-10 10:00', 'ends_at' => '2026-12-01 10:00']])->assertUnprocessable()->assertJsonValidationErrors(['values.ends_at']);
        $this->send('PUT', $panel, ['values' => ['starts_at' => '2035-12-01 10:00', 'ends_at' => null]])->assertUnprocessable()->assertJsonValidationErrors(['values.starts_at']);
        $this->assertSame(0, $this->inside('spd', fn (): int => SaleWindow::query()->count()));

        // 決めると、欄の案内が今の状態を日本時間で出す。記録は商品の ID と日時だけ（店の人の文は入らない）
        $this->send('PUT', $panel, ['values' => ['starts_at' => self::OPENS, 'ends_at' => self::CLOSES]])->assertOk()
            ->assertJsonPath('app.note', '今はショップに表示されていません（2026/12/01 10:00 から表示されます・2026/12/10 23:59 に販売を終えます）。');
        $this->send('GET', "/api/tenants/spd/listings/{$this->listing}/apps")->assertOk()
            ->assertJsonPath('apps.0.plugin', 'sale-period')
            ->assertJsonPath('apps.0.values.starts_at', self::OPENS)
            ->assertJsonPath('apps.0.values.ends_at', self::CLOSES);

        $set = $this->inside('spd', fn (): DomainEvent => DomainEvent::query()->where('type', 'sale_period.set')->sole());
        $this->assertSame([$owner->actor(), $this->listing, '2026-12-01T01:00:00Z'], [$set->actor, $set->payload['listing_id'], $set->payload['starts_at']]);
        $this->assertStringNotContainsString('冬限定', json_encode($set->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // App の画面: 公開を待っている 1 件（商品へのリンクつき）
        $rows = $this->send('GET', $records)->assertOk()->assertJsonPath('meta.total', 1);
        $id = (string) $rows->json('rows.0.id');
        $rows->assertJsonPath('rows.0.cells.listing', ['label' => '冬限定のブレンド', 'kind' => 'listing', 'id' => $this->listing])
            ->assertJsonPath('rows.0.cells.state.label', '公開待ち');
        $this->send('GET', $records.'?filter=closed')->assertOk()->assertJsonPath('meta.total', 0);

        // 期間をやめるのは取り消せない操作なので、直前の再確認の前は 423
        $this->send('POST', "{$records}/{$id}/actions/clear")->assertStatus(423);
        $this->assertSame(1, $this->inside('spd', fn (): int => SaleWindow::query()->count()));

        // 再確認の後に行が消え、商品はふだんどおり出る
        $this->reconfirm($owner, $key);
        $this->send('POST', "{$records}/{$id}/actions/clear")->assertOk();
        $this->assertSame(0, $this->inside('spd', fn (): int => SaleWindow::query()->count()));
        $this->assertSame(['sale_period.set', 'sale_period.cleared'],
            $this->inside('spd', fn (): array => DomainEvent::query()->whereIn('type', ['sale_period.set', 'sale_period.cleared'])->orderBy('sequence')->pluck('type')->all()));
        $this->shop("/products/{$this->listing}")->assertOk()->assertSee('data-test="add-to-cart"', false);
    }

    /**
     * 期間を決める（日本時間の文。null はその側を決めない）
     */
    private function schedule(?string $startsAt, ?string $endsAt): void
    {
        $this->inside('spd', fn () => app(SaleWindows::class)->save(Listing::query()->findOrFail($this->listing), $startsAt, $endsAt, 'operator:test'));
    }

    /**
     * カートに 1 点入れてみて、断られた文（入れられたら null）
     */
    private function cartRefusal(): ?string
    {
        return $this->inside('spd', function (): ?string {
            $carts = app(CartService::class);
            [$cart] = $carts->create();

            try {
                $carts->add($cart, $this->variant, 1);

                return null;
            } catch (CartException $e) {
                return $e->getMessage();
            }
        });
    }

    /**
     * ショップの画面を開く（店の文脈に入ったまま戻るので閉じる）
     *
     * @return TestResponse<Response>
     */
    private function shop(string $path): TestResponse
    {
        $response = $this->get("http://spd.localhost{$path}");
        tenancy()->end();

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private static function buyer(): array
    {
        return ['name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '09012345678', 'postal' => '1500001',
            'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];
    }

    public function test_the_memo_does_not_leak_between_shops_in_one_process(): void
    {
        // 1 つの処理の中でテナントを切り替えると request() は同じ実体のまま。
        // 覚えの鍵に店が入っていないと、店 A の「今は出さない商品」が店 B に出てしまう（必ず守ること 3）
        $this->inside('spd', fn () => app(SaleWindows::class)->save(
            Listing::query()->findOrFail($this->listing), self::OPENS, null, 'operator:test',
        ));
        $this->tenant('spe');
        $this->inside('spe', fn () => app(PluginStates::class)->set('sale-period', true, 'test'));

        // **実体を 1 つだけ取って**店をまたぐ（Catalog は 1 つの実体を持ち回るので、これが実際の形。
        // app() を呼び直すと毎回新しい実体になり、覚えが再利用されないので漏れを捕まえられない）
        $visibility = app(ListingVisibility::class);

        // 店 spd では公開の日時の前なので出さない（今は 09:00、出すのは 10:00）
        $this->inside('spd', function () use ($visibility): void {
            $this->assertSame([$this->listing], $visibility->hidden());
        });

        // 同じ実体のまま店 spe へ。店 spd の答えが残っていてはいけない
        $this->inside('spe', function () use ($visibility): void {
            $this->assertSame([], $visibility->hidden(), '店をまたいで覚えが漏れている');
        });

        // 戻っても店 spd の答えは正しい
        $this->inside('spd', function () use ($visibility): void {
            $this->assertSame([$this->listing], $visibility->hidden());
        });
    }
}
