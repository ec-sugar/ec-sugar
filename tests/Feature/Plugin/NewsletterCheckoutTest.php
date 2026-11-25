<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Crypto\BlindIndex;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Dom\HTMLDocument;
use EcSugar\PluginNewsletter\Mail\ConfirmMail;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use EcSugar\PluginNewsletter\SubscriberStatus;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 注文の入力の「お知らせのメールを受け取る」（App「メルマガ・一斉メール」。核の口 CheckoutOptInHandler）。
 * いつも選んでいない状態で出し、選んだ人の注文の確定の後にだけ購読を受け付ける（ゲストは確かめのメール、会員はすぐ）。使わない店では出さず、送られても受け取らない
 */
final class NewsletterCheckoutTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const array BUYER = ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];

    private ?string $cart = null;

    private ?string $member = null;

    private string $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        FakeStripe::install();
        $this->tenant('nca', 'かくざとう');
        $this->listing = $this->inside('nca', function (): string {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 800, 'free_over' => null], 'test');
            app(PluginStates::class)->set('newsletter', true, 'test');
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '焼き菓子', 'unit_amount' => 3000, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 20);

            return $listing->id;
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_box_starts_unchecked_in_every_theme_and_only_a_chosen_box_subscribes(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->listing, 'quantity' => '1']);

        foreach (['default', 'kinari', 'fashion', 'botanica'] as $theme) {
            $this->tenants[0]->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
            $box = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('form [data-test="opt-in-toggle"]');
            $this->assertNotNull($box, $theme);
            $this->assertFalse($box->hasAttribute('checked'), $theme);
        }

        // 選ばずに注文した: 購読は受け付けない
        $this->order(false);
        $this->assertSame(0, $this->inside('nca', fn (): int => NewsletterSubscriber::query()->count()));
        Mail::assertNotSent(ConfirmMail::class);

        // 選んで注文した: 確かめのメール（ゲスト）。同意の経路は checkout。確かめるまで届けない
        $this->send('POST', '/cart/items', ['listing_id' => $this->listing, 'quantity' => '1']);
        $this->order(true);
        Mail::assertSent(ConfirmMail::class, static fn (ConfirmMail $m): bool => $m->hasTo('hanako@example.com'));
        $row = $this->subscriber();
        $this->assertSame(SubscriberStatus::Pending, $row->status);
        $this->assertSame('checkout', $row->source);
    }

    public function test_a_member_ordering_with_their_own_email_is_added_at_once(): void
    {
        $this->send('POST', '/account/login', ['email' => 'hanako@example.com']);
        $this->send('POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code]);
        $this->send('POST', '/cart/items', ['listing_id' => $this->listing, 'quantity' => '1']);
        $this->order(true);

        Mail::assertNotSent(ConfirmMail::class);
        $row = $this->subscriber();
        $this->assertSame(SubscriberStatus::Active, $row->status);
        $this->assertNotNull($row->customer_account_id);
    }

    public function test_a_shop_without_the_app_shows_no_box_and_ignores_a_sent_one(): void
    {
        $this->inside('nca', fn () => app(PluginStates::class)->set('newsletter', false, 'test'));
        $this->send('POST', '/cart/items', ['listing_id' => $this->listing, 'quantity' => '1']);
        $this->assertStringNotContainsString('data-test="opt-in"', (string) $this->send('GET', '/checkout')->assertOk()->getContent());

        $this->order(true);
        $this->assertSame(0, $this->inside('nca', fn (): int => NewsletterSubscriber::query()->count()));
        Mail::assertNotSent(ConfirmMail::class);
    }

    /**
     * 入力 → 確認 → 確定（Stripe の支払いの画面へ移るところまで）
     */
    private function order(bool $optIn): void
    {
        $this->send('POST', '/checkout', ['buyer' => self::BUYER, 'same_as_buyer' => '1', 'opt_in' => $optIn ? '1' : '0'])->assertRedirect('http://nca.localhost/checkout/confirm');
        $confirm = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertStatus(303);
    }

    private function subscriber(): NewsletterSubscriber
    {
        return $this->inside('nca', fn () => NewsletterSubscriber::query()->where('email_index', BlindIndex::of('email', 'hanako@example.com'))->sole());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        $this->app['auth']->forgetGuards();
        $url = "http://nca.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }
}
