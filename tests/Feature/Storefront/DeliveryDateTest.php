<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Admin\Http\Presenters\TransactionPresenter;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\DeliverySlot;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Shop\Services\DeliveryDates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderReader;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * お届け日時の指定（ADR 0059）。店が受けるときだけ注文の入力に「お届け日」「時間帯」が出て（4 つのテーマとも核の部品）、
 * サーバーが今の時刻と店の決まりで範囲を確かめる（範囲外・知らない時間帯・形の誤りは断る。確認の画面の後に範囲外になったら確定しない）。
 * 選んだ日時は受渡に入り、確認の画面・ご注文の状況・メール・管理画面に出る。指定なしも選べる
 */
final class DeliveryDateTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private ?string $token = null;

    private string $knit;

    /** @var array<string, mixed> */
    private array $valid = [
        'buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
        'same_as_buyer' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        // 2026-11-04（水）10:00（日本の時間）
        $this->travelTo(new CarbonImmutable('2026-11-04 10:00:00', 'Asia/Tokyo'));
        $this->tenant = Tenant::create(['id' => 'dld', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'dld.localhost']);
        $this->knit = $this->inside(function (): string {
            $seller = Seller::query()->value('id');
            $knit = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($knit, Location::query()->orderBy('id')->firstOrFail(), 5);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => null]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);

            return $knit->id;
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_shop_that_does_not_take_dates_shows_no_fields_and_ignores_posted_values(): void
    {
        $this->addToCart();

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', '/checkout')->assertOk()->assertDontSee('data-test="delivery-fields"', false);
        }

        // 欄を出していないので、送られてきた値は使わない（指定なしで進む）
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '2026-11-06', 'slot' => 'am']])->assertRedirect('http://dld.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertDontSee('data-test="delivery-summary"', false);
        $this->place();

        $this->inside(function (): void {
            $fulfillment = Fulfillment::query()->sole();
            $this->assertNull($fulfillment->delivery());
            $this->assertArrayNotHasKey('delivery', (array) DomainEvent::query()->where('type', 'fulfillment.created')->sole()->payload);
        });
    }

    public function test_the_chosen_date_and_slot_reach_the_fulfillment_and_every_place_the_order_is_shown(): void
    {
        $this->offer(['slots' => ['am', '19-21'], 'cutoff' => '12:00', 'closed_weekdays' => [0], 'span_days' => 7]);
        $this->addToCart();

        // 4 つのテーマとも、核の部品が選べる日（11-06〜11-12）と店の時間帯だけを出す
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
            $this->assertStringContainsString('data-test="delivery-fields"', $html, $theme);
            $this->assertSame(['', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'], $this->selectOptions($html, 'delivery[date]'), $theme);
            $this->assertSame(['', 'am', '19-21'], $this->selectOptions($html, 'delivery[slot]'), $theme);
            $this->assertStringContainsString('最短のお届け日は 2026年11月6日（金） です。', $html, $theme);
        }

        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '2026-11-08', 'slot' => '19-21']])->assertRedirect('http://dld.localhost/checkout/confirm');
        // 戻ったときは選んだ値が選ばれている
        $this->assertStringContainsString('<option value="2026-11-08" selected', (string) $this->send('GET', '/checkout')->getContent());

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', '/checkout/confirm')->assertOk()->assertSee('2026年11月8日（日） 19時〜21時');
        }

        $this->place();

        $link = $this->inside(function (): string {
            $fulfillment = Fulfillment::query()->sole();
            $this->assertSame('2026-11-08', $fulfillment->delivery_date);
            $this->assertSame(DeliverySlot::From19To21, $fulfillment->delivery_slot);
            // 記録にも残る（個人情報ではない）
            $this->assertSame(['date' => '2026-11-08', 'slot' => '19-21'], DomainEvent::query()->where('type', 'fulfillment.created')->sole()->payload['delivery']);

            // 管理画面の注文の受渡・お客さんの注文の様子・メール
            $transaction = Transaction::query()->sole();
            $this->assertSame('2026年11月8日（日） 19時〜21時', app(TransactionPresenter::class)->present($transaction)['fulfillments'][0]['delivery']['label']);
            $view = app(OrderReader::class)->read($transaction);
            $this->assertSame('2026年11月8日（日） 19時〜21時', $view->deliveryWish);
            $mail = view('mail.order-text', ['kind' => 'order_paid', 'shopName' => '店', 'order' => $view, 'link' => 'https://dld.localhost/x'])->render();
            $this->assertStringContainsString('お届け希望日時: 2026年11月8日（日） 19時〜21時', $mail);
            $this->assertStringContainsString('2026年11月8日（日） 19時〜21時', view('mail.order', ['kind' => 'order_paid', 'shopName' => '店', 'order' => $view, 'link' => 'https://dld.localhost/x'])->render());

            return OrderReader::link($transaction, 'http://dld.localhost');
        });

        // ご注文の状況のページ（4 つのテーマとも核の部品）
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY))->assertOk()->assertSee('2026年11月8日（日） 19時〜21時');
        }
    }

    public function test_no_preference_is_allowed_and_shown_as_such(): void
    {
        $this->offer(['slots' => ['am']]);
        $this->addToCart();

        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '', 'slot' => '']])->assertRedirect('http://dld.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertSee('指定なし（最短でお届けします）');
        // 時間帯だけでもよい
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['slot' => 'am']])->assertRedirect('http://dld.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertSee('日付の指定なし 午前中');
        $this->place();

        $this->inside(function (): void {
            $this->assertEquals(new DeliveryChoice(null, DeliverySlot::Morning), Fulfillment::query()->sole()->delivery());
        });
    }

    public function test_out_of_range_unknown_or_malformed_choices_are_refused(): void
    {
        $this->offer(['slots' => ['am'], 'span_days' => 3, 'closed_dates' => ['2026-11-05']]);
        $this->addToCart();
        // 誤りは入力の画面へ戻す（直前に開いた画面）
        $this->send('GET', '/checkout')->assertOk();
        // 11-05 は休み → 11-06 に発送 → 11-07〜11-09

        $refused = [
            [['date' => '2026-11-06'], 'delivery.date'],       // 最初の日より前
            [['date' => '2026-11-10'], 'delivery.date'],       // 最後の日より後
            [['date' => '2026-11-04'], 'delivery.date'],       // 今日
            [['date' => '2026-02-30'], 'delivery.date'],       // 暦に無い日
            [['date' => '2026/11/07'], 'delivery.date'],       // 形の違い
            [['date' => ['2026-11-07']], 'delivery.date'],     // 文字でない
            [['slot' => '14-16'], 'delivery.slot'],            // 店が受けていない時間帯
            [['slot' => '12-14'], 'delivery.slot'],            // 知らない時間帯
            [['slot' => 'AM'], 'delivery.slot'],               // 大文字（区分の値はそのままでだけ）
        ];

        foreach ($refused as [$delivery, $field]) {
            $this->send('POST', '/checkout', [...$this->valid, 'delivery' => $delivery])
                ->assertRedirect('http://dld.localhost/checkout')->assertSessionHasErrors([$field]);
        }

        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => 'tomorrow'])->assertRedirect('http://dld.localhost/checkout')->assertSessionHasErrors(['delivery']);
        // 入力の画面には理由が出る（店の決まりで断ったもの）
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '2026-11-10']]);
        $this->send('GET', '/checkout')->assertOk()->assertSee('お選びのお届け日（2026年11月10日（火））は、現在お選びいただけません。', false);
        // 何も確認の画面へ進めていない
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://dld.localhost/checkout');

        // 範囲の端はどちらも選べる
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '2026-11-07', 'slot' => 'am']])->assertRedirect('http://dld.localhost/checkout/confirm');
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '2026-11-09']])->assertRedirect('http://dld.localhost/checkout/confirm');
    }

    public function test_a_date_that_falls_out_of_range_after_the_confirm_page_is_not_placed(): void
    {
        $this->travelTo(new CarbonImmutable('2026-11-04 11:58:00', 'Asia/Tokyo'));
        $this->offer(['cutoff' => '12:00']);
        $this->addToCart();

        // 締め切りの前: いちばん早い 11-06 を選ぶ
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '2026-11-06']])->assertRedirect('http://dld.localhost/checkout/confirm');
        $form = $this->confirm();

        // 確認の画面を開いたまま締め切りを過ぎた（最初の日が 11-07 へ）: 確定しない
        $this->travelTo(new CarbonImmutable('2026-11-04 12:00:30', 'Asia/Tokyo'));
        $this->send('POST', '/checkout/confirm', $form)->assertRedirect('http://dld.localhost/checkout/confirm');
        $this->inside(function (): void {
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, CheckoutOrder::query()->count());
            $this->assertSame(0, (int) InventoryStock::query()->sum('reserved'));
        });
        // 確認の画面は入力へ戻し、選び直してもらう
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://dld.localhost/checkout');
        $this->send('GET', '/checkout')->assertOk()->assertSee('お選びのお届け日（2026年11月6日（金））は、現在お選びいただけません。', false);

        // 選び直せば確定できる
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '2026-11-07']])->assertRedirect('http://dld.localhost/checkout/confirm');
        $this->place();
        $this->inside(fn () => $this->assertSame('2026-11-07', Fulfillment::query()->sole()->delivery_date));
    }

    public function test_the_shop_stopping_dates_after_the_input_sends_the_customer_back(): void
    {
        $this->offer([]);
        $this->addToCart();
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '2026-11-06']])->assertRedirect('http://dld.localhost/checkout/confirm');
        $form = $this->confirm();

        $this->inside(fn () => app(DeliveryDates::class)->update([...self::policy(), 'dates' => false], 'test'));

        $this->send('POST', '/checkout/confirm', $form)->assertRedirect('http://dld.localhost/checkout/confirm');
        $this->inside(fn () => $this->assertSame(0, Transaction::query()->count()));
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://dld.localhost/checkout');
        // 欄は出ないが、理由は出る
        $this->send('GET', '/checkout')->assertOk()->assertSee('お届け日の指定は、現在受け付けていません。', false)->assertDontSee('data-test="delivery-fields"', false);
    }

    public function test_gift_orders_do_not_carry_a_date(): void
    {
        $input = CheckoutInput::validate([...$this->valid, 'gift' => ['on' => '1', 'sender' => '', 'message' => ''], 'delivery' => ['date' => '2026-11-06', 'slot' => 'am']]);
        $this->assertNotNull($input->gift);
        $this->assertNull($input->delivery);
        // セッションから戻しても同じ
        $this->assertNull(CheckoutInput::fromArray([...$input->toArray(), 'delivery' => ['date' => '2026-11-06', 'slot' => 'am']])?->delivery);
    }

    public function test_the_core_and_the_database_keep_dates_to_shipping_and_known_slots(): void
    {
        $this->inside(function (): void {
            $seller = Seller::query()->firstOrFail();
            $listing = Listing::query()->findOrFail($this->knit);
            $location = Location::query()->orderBy('id')->firstOrFail();
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');
            $line = $sale->lines()->firstOrFail();

            try {
                app(FulfillmentService::class)->create($sale, FulfillmentMethod::Pickup, [$line->id => 1], $location, null, 'test', new DeliveryChoice('2026-11-06', null));
                $this->fail('店頭受取に日時を付けた');
            } catch (TransactionException) {
                $this->addToAssertionCount(1);
            }

            $fulfillment = app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => 1], $location, null, 'test', new DeliveryChoice('2026-11-06', DeliverySlot::Morning));

            foreach ([
                ['delivery_slot' => '12-14'],
                ['method' => 'pickup'],
            ] as $values) {
                try {
                    DB::transaction(fn () => DB::table('fulfillments')->where('id', $fulfillment->id)->update($values));
                    $this->fail('DB が通した: '.json_encode($values));
                } catch (QueryException $e) {
                    $this->assertStringContainsString('fulfillments_delivery_', $e->getMessage());
                }
            }
        });
    }

    public function test_a_chosen_date_withholds_konbini_but_a_slot_alone_does_not(): void
    {
        // お届け日を選んだ注文には、後から払う手段（コンビニ払い）を出さない（ADR 0058・0059。払いの期限まで発送を待つと、選んだ日に届かない）
        $stripe = FakeStripe::install();
        $this->offer(['slots' => ['am']]);
        $this->inside(fn () => app(PaymentMethodSettings::class)->update(['konbini' => true, 'paypay' => false, 'konbini_days' => 3], 'test'));

        foreach ([
            '日付を選ぶ' => [['date' => '2026-11-08', 'slot' => ''], ['card']],
            '日付と時間帯を選ぶ' => [['date' => '2026-11-08', 'slot' => 'am'], ['card']],
            '選ばない' => [['date' => '', 'slot' => ''], ['card', 'konbini']],
            '時間帯だけ' => [['date' => '', 'slot' => 'am'], ['card', 'konbini']],
        ] as $case => [$delivery, $types]) {
            $this->addToCart();
            $this->send('POST', '/checkout', [...$this->valid, 'delivery' => $delivery])->assertRedirect('http://dld.localhost/checkout/confirm');

            // 確かめの画面の「お支払いの方法と時期」も同じ手段で書く
            $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
            $withheld = $types === ['card'];
            $this->assertSame(! $withheld, str_contains($html, 'クレジットカード・コンビニ払い（'), $case);
            $this->assertSame($withheld, str_contains($html, 'お届け日をご指定のご注文は、コンビニ払いをお選びいただけません'), $case);
            $this->assertSame(! $withheld, str_contains($html, '23:59 までにコンビニでお支払いください'), $case);

            // 支払いの画面へ渡す手段
            $this->place();
            $sessions = array_values(array_filter($stripe->requests, static fn (array $r): bool => $r['method'] === 'post' && str_ends_with($r['url'], '/v1/checkout/sessions')));
            $this->assertSame(array_values(array_diff(CheckoutMethods::EXCLUDABLE, $types)), $sessions[array_key_last($sessions)]['params']['excluded_payment_method_types'] ?? null, $case);
        }
    }

    public function test_the_confirm_page_fingerprint_covers_the_payment_methods_and_the_delivery_wish(): void
    {
        $this->offer(['slots' => ['am']]);
        $this->addToCart();
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '', 'slot' => 'am']])->assertRedirect();
        $shown = $this->confirm();

        // 確かめた後に店がコンビニ払いを使い始めた: 見せた「支払いの方法」と違うので確定しない
        $this->inside(fn () => app(PaymentMethodSettings::class)->update(['konbini' => true, 'paypay' => false, 'konbini_days' => 3], 'test'));
        $this->send('POST', '/checkout/confirm', $shown)->assertRedirect('http://dld.localhost/checkout/confirm');
        $this->inside(fn () => $this->assertSame(0, Transaction::query()->count()));

        // 見せたお届けの希望と違う希望で確定しようとしても（同じ画面の指紋で別の入力）、確定しない
        $shown = $this->confirm();
        $this->send('POST', '/checkout', [...$this->valid, 'delivery' => ['date' => '', 'slot' => '']])->assertRedirect();
        $this->send('POST', '/checkout/confirm', $shown)->assertRedirect('http://dld.localhost/checkout/confirm');
        $this->inside(fn () => $this->assertSame(0, Transaction::query()->count()));

        // 見せ直した画面からは確定できる
        $this->place();
        $this->inside(fn () => $this->assertSame(1, Transaction::query()->count()));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function offer(array $values): void
    {
        $this->inside(fn () => app(DeliveryDates::class)->update([...self::policy(), ...$values], 'test'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function policy(): array
    {
        return ['dates' => true, 'slots' => [], 'ship_days' => 1, 'transit_days' => 1, 'span_days' => 14, 'cutoff' => null, 'closed_weekdays' => [], 'closed_dates' => []];
    }

    /**
     * @return list<string>
     */
    private function selectOptions(string $html, string $name): array
    {
        $this->assertNotFalse(preg_match('/<select[^>]*name="'.preg_quote($name, '/').'"[^>]*>(.*?)<\/select>/s', $html, $select), $name);
        preg_match_all('/<option value="([^"]*)"/', $select[1] ?? '', $values);

        return $values[1];
    }

    private function addToCart(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
    }

    /**
     * 確認の画面を開き、確定のフォームの値を取り出す
     *
     * @return array<string, string>
     */
    private function confirm(): array
    {
        $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);

        return ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''];
    }

    private function place(): void
    {
        $response = $this->send('POST', '/checkout/confirm', $this->confirm());
        $response->assertStatus(303);
        $this->assertStringStartsWith('https://checkout.stripe.com/', (string) $response->headers->get('Location'));
    }

    /**
     * Cookie（カートの合い言葉とセッション）を持ち越して送る
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $url = "http://dld.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        return $response;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(Closure $work): mixed
    {
        tenancy()->initialize($this->tenant);

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }
}
