<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Shop\Services\ShopSettingsService;
use App\Http\Middleware\HostPrefixedCookies;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 1 店舗の形から複数の店の形へ広げた本番（ADR 0090）。ショップのドメインは ec-sugar.jp、前からの店は ID shop・ホスト shop.ec-sugar.jp、
 * 足したデモの店は ID demosake・ホスト demo-sake.ec-sugar.jp（ID とホストの 1 段目が違う）。同じドメインに管理画面と、別のサーバーのサイト（www・dev）がある。
 *
 * - 前からの店は同じホストで開き、足した店は自分のホストで開く。知らない名前（www）では店を開かない
 * - Cookie（セッション・CSRF・カート・会員の印）はどれも Domain を付けない（店ごとのホストだけ。ブラウザはほかの店・管理画面・www へ送らない）。
 *   env の SESSION_DOMAIN がショップのドメイン全体（.ec-sugar.jp）でも、ショップでは付けない
 * - ある店のカートと会員の印を、ほかの店へ持ち込んでも何も開かない（合い言葉は店の DB で引く）
 * - https では Cookie を __Host- の名前でだけやり取りする（見直しの中 1）。隣のホスト（デモの店・www）から Domain つき・Path つきで置かれた
 *   頭の無い Cookie は読まない（セッション固定にならない）。前の名前のカート・会員の印は、1 店舗の形の間だけ一度読んで __Host- の名前へ移す
 * - 名前と連絡先を初めてセッションに置くときに、セッションの番号を作り直す
 */
final class MultiShopHostsTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string SHOP = 'shop.ec-sugar.jp';

    private const string DEMO = 'demo-sake.ec-sugar.jp';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        config([
            'admin.domain' => 'admin.ec-sugar.jp',
            'tenancy.central_domains' => ['admin.ec-sugar.jp', 'api.ec-sugar.jp'],
            'tls.shops' => 'multi',
            'tls.shop_domain' => 'ec-sugar.jp',
        ]);
        $this->shopAt('shop', self::SHOP, '自分の店');
        $this->shopAt('demosake', self::DEMO, '霞堤酒造');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_each_shop_opens_on_its_own_host_and_other_names_open_no_shop(): void
    {
        $this->open(self::SHOP, '/')->assertOk()->assertSee('自分の店')->assertDontSee('霞堤酒造');
        $this->open(self::DEMO, '/')->assertOk()->assertSee('霞堤酒造')->assertDontSee('自分の店');

        // 店の ID のホスト（demosake.ec-sugar.jp）・同じドメインのほかのサイト（www）・管理画面のホストでは店を開かない
        $this->open('demosake.ec-sugar.jp', '/')->assertNotFound();
        $this->open('www.ec-sugar.jp', '/')->assertNotFound();
        $this->assertNotSame(200, $this->open('api.ec-sugar.jp', '/cart')->getStatusCode(), '中央のホストで店のカートを開かない');
    }

    public function test_cookies_stay_on_each_shop_host_even_if_the_env_widens_the_session_domain(): void
    {
        // 誤って env の SESSION_DOMAIN をショップのドメイン全体にした
        config(['session.domain' => '.ec-sugar.jp']);
        $listing = $this->inside('shop', fn (): string => $this->listing('純米酒', 2200));

        $responses = [
            $this->open(self::SHOP, '/'),
            $this->open(self::SHOP, '/cart/items', ['listing_id' => $listing, 'quantity' => '1']),
            $this->open(self::DEMO, '/'),
            $this->open(self::DEMO, '/account/login', ['email' => 'hanako@example.com']),
        ];

        $names = [];
        foreach ($responses as $response) {
            foreach ($response->headers->getCookies() as $cookie) {
                $names[] = $cookie->getName();
                $this->assertNull($cookie->getDomain(), "{$cookie->getName()} の Cookie に Domain が付いた（ほかの店・管理画面・www へも送られる）");
            }
        }

        // セッション・CSRF・カートの Cookie を見た
        $this->assertContains((string) config('session.cookie'), $names);
        $this->assertContains('XSRF-TOKEN', $names);
        $this->assertContains(CartCookie::NAME, $names);
    }

    public function test_a_cart_and_a_member_mark_from_one_shop_open_nothing_on_another(): void
    {
        $listing = $this->inside('shop', fn (): string => $this->listing('純米酒', 2200));

        $added = $this->open(self::SHOP, '/cart/items', ['listing_id' => $listing, 'quantity' => '2']);
        $cart = (string) $added->getCookie(CartCookie::NAME)?->getValue();
        $this->assertNotSame('', $cart);
        $this->assertSame(1, substr_count((string) $this->open(self::SHOP, '/cart', cookies: [CartCookie::NAME => $cart])->getContent(), 'data-test="cart-line"'));

        // 同じ合い言葉の Cookie をデモの店へ持ち込んでも、カートは空で、デモの店の DB にカートを作らない
        $this->open(self::DEMO, '/cart', cookies: [CartCookie::NAME => $cart])->assertOk()->assertSee('data-test="cart-empty"', false);
        $this->inside('demosake', fn () => $this->assertSame(0, Cart::query()->count()));

        // 会員の印も、前からの店でログインした印をデモの店へ持ち込むとログインしていない扱い
        $this->open(self::SHOP, '/account/login', ['email' => 'hanako@example.com'])->assertRedirect('http://'.self::SHOP.'/account/login/code');
        $code = Mail::sent(LoginCodeMail::class)->last()->code;
        $login = $this->open(self::SHOP, '/account/login/code', ['code' => $code]);
        $login->assertRedirect('http://'.self::SHOP.'/account');
        $member = (string) $login->getCookie(MemberCookie::NAME)?->getValue();
        $this->assertNotSame('', $member);

        $this->open(self::SHOP, '/account', cookies: [MemberCookie::NAME => $member])->assertOk();
        $this->open(self::DEMO, '/account', cookies: [MemberCookie::NAME => $member])->assertRedirect('http://'.self::DEMO.'/account/login');
    }

    public function test_on_https_every_shop_cookie_is_host_prefixed(): void
    {
        $listing = $this->inside('shop', fn (): string => $this->listing('純米酒', 2200));

        foreach ([$this->secure(self::SHOP, '/'), $this->secure(self::SHOP, '/cart/items', ['listing_id' => $listing, 'quantity' => '1'])] as $response) {
            $cookies = $response->headers->getCookies();
            $this->assertNotSame([], $cookies);

            foreach ($cookies as $cookie) {
                $this->assertStringStartsWith('__Host-', $cookie->getName());
                $this->assertTrue($cookie->isSecure(), $cookie->getName());
                $this->assertSame('/', $cookie->getPath(), $cookie->getName());
                $this->assertNull($cookie->getDomain(), $cookie->getName());
            }
        }

        $names = array_map(static fn ($cookie): string => $cookie->getName(), $this->secure(self::SHOP, '/cart/items', ['listing_id' => $listing, 'quantity' => '1'])->headers->getCookies());
        $this->assertContains('__Host-'.config('session.cookie'), $names);
        $this->assertContains('__Host-XSRF-TOKEN', $names);
        $this->assertContains('__Host-'.CartCookie::NAME, $names);
    }

    public function test_a_session_cookie_tossed_from_a_neighbour_host_is_not_used(): void
    {
        $name = (string) config('session.cookie');
        // 攻める人が自分で店を開いてセッションを取り、隣のホスト（デモの店か www）から Domain=.ec-sugar.jp; Path=/checkout で置く（頭の無い名前）
        $attacker = $this->raw($this->secure(self::SHOP, '/'), $name);
        $this->assertNotNull($attacker);
        $attackerId = $this->sessionId($attacker);

        // 本物の __Host- の Cookie なら同じセッション（比べの元）
        $this->assertSame($attackerId, $this->sessionId((string) $this->raw($this->secure(self::SHOP, '/checkout', cookies: ['__Host-'.$name => $attacker]), $name)));

        // 置かれた頭の無い Cookie は読まない。お客さんは自分の新しいセッションで動く
        $victim = $this->raw($this->secure(self::SHOP, '/checkout', cookies: [$name => $attacker]), $name);
        $this->assertNotNull($victim);
        $this->assertNotSame($attackerId, $this->sessionId($victim));

        // 両方あれば __Host- の方（置かれた方に上書きされない）
        $mine = (string) $this->raw($this->secure(self::SHOP, '/'), $name);
        $this->assertSame($this->sessionId($mine), $this->sessionId((string) $this->raw($this->secure(self::SHOP, '/checkout', cookies: ['__Host-'.$name => $mine, $name => $attacker]), $name)));
    }

    public function test_the_session_number_changes_when_the_checkout_details_first_enter_the_session(): void
    {
        $name = (string) config('session.cookie');
        $listing = $this->inside('shop', function (): string {
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 11000]);

            return $this->listing('純米酒', 2200);
        });
        $added = $this->secure(self::SHOP, '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $cookies = ['__Host-'.CartCookie::NAME => (string) $this->raw($added, CartCookie::NAME), '__Host-'.$name => (string) $this->raw($added, $name)];
        $before = $this->sessionId($cookies['__Host-'.$name]);

        $input = ['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''], 'same_as_buyer' => '1'];
        $first = $this->secure(self::SHOP, '/checkout', $input, $cookies);
        $first->assertRedirect('https://'.self::SHOP.'/checkout/confirm');
        $after = $this->sessionId((string) $this->raw($first, $name));
        $this->assertNotSame($before, $after, '初めて入力を置いた時に、前のセッションの番号のままだった');

        // もう入力があるセッションで入れ直すときは作り直さない（確認の画面の合い言葉を保つ）
        $cookies['__Host-'.$name] = (string) $this->raw($first, $name);
        $this->assertSame($after, $this->sessionId((string) $this->raw($this->secure(self::SHOP, '/checkout', $input, $cookies), $name)));
    }

    public function test_old_cookie_names_move_once_in_the_single_shop_form_and_are_ignored_after_widening(): void
    {
        $listing = $this->inside('shop', fn (): string => $this->listing('純米酒', 2200));
        // 前のバージョン（http のまま・頭の無い名前）で作ったカートの Cookie の中身（暗号にしたもの）
        $old = (string) $this->open(self::SHOP, '/cart/items', ['listing_id' => $listing, 'quantity' => '2'])->getCookie(CartCookie::NAME, false)?->getValue();
        $this->assertNotSame('', $old);

        // 1 店舗の形の間: 古い名前を一度読み、__Host- の名前で出し直して、古い名前の Cookie を消す
        config(['tls.shops' => 'single']);
        $moved = $this->secure(self::SHOP, '/cart', cookies: [CartCookie::NAME => $old]);
        $this->assertSame(1, substr_count((string) $moved->getContent(), 'data-test="cart-line"'));
        $this->assertSame($old, $this->raw($moved, CartCookie::NAME));
        $expired = $moved->getCookie(CartCookie::NAME, false);
        $this->assertNotNull($expired);
        $this->assertTrue($expired->isCleared(), '古い名前の Cookie を消していない');
        $this->assertSame(1, substr_count((string) $this->secure(self::SHOP, '/cart', cookies: ['__Host-'.CartCookie::NAME => $old])->getContent(), 'data-test="cart-line"'));

        // 複数の店の形へ広げた後は、古い名前を読まない（隣のホストから置かれたものと見分けられない）
        config(['tls.shops' => 'multi']);
        $this->secure(self::SHOP, '/cart', cookies: [CartCookie::NAME => $old])->assertSee('data-test="cart-empty"', false);
        // 両方あれば __Host- の方
        $sake = $this->inside('shop', fn (): string => $this->listing('置かれたカートの品', 1100));
        $other = (string) $this->open(self::SHOP, '/cart/items', ['listing_id' => $sake, 'quantity' => '1'])->getCookie(CartCookie::NAME, false)?->getValue();
        $this->secure(self::SHOP, '/cart', cookies: ['__Host-'.CartCookie::NAME => $old, CartCookie::NAME => $other])->assertSee('純米酒')->assertDontSee('置かれたカートの品');
    }

    public function test_the_central_host_root_hands_out_no_cookies(): void
    {
        // 中央のホスト（本番は api.ec-sugar.jp。テストでは起動の時の localhost）の疎通の確かめは、セッションも Cookie も持たない
        $response = $this->get('http://localhost/');
        $response->assertOk()->assertJson(['scope' => 'central']);
        $this->assertSame([], $response->headers->getCookies());
    }

    private function shopAt(string $id, string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['id' => $id, 'name' => $name]);
        $tenant->domains()->create(['domain' => $domain]);
        $this->tenants[] = $tenant;

        return $tenant;
    }

    private function listing(string $title, int $amount): string
    {
        $listing = Listing::create(['seller_id' => Seller::query()->firstOrFail()->id, 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => 'active']);
        app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);

        return $listing->id;
    }

    /**
     * 店のホストへ送る。渡した Cookie だけを持たせる（前の要求の Cookie を持ち越さない。どの Cookie をどの店へ送るかを、この試験が決める）
     *
     * @param  array<string, string>|null  $data
     * @param  array<string, string>  $cookies
     * @return TestResponse<Response>
     */
    private function open(string $host, string $path, ?array $data = null, array $cookies = []): TestResponse
    {
        $this->defaultCookies = [];

        foreach ($cookies as $name => $value) {
            $this->withCookie($name, $value);
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$host}{$path}";
        $response = $data === null ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();

        return $response;
    }

    /**
     * https で店のホストへ送る。Cookie はブラウザが持っている形のまま（暗号にした値をそのまま、名前も渡したとおり）で送る
     *
     * @param  array<string, mixed>|null  $data  フォームの値（注文の入力は入れ子）
     * @param  array<string, string>  $cookies
     * @return TestResponse<Response>
     */
    private function secure(string $host, string $path, ?array $data = null, array $cookies = []): TestResponse
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = $cookies;
        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "https://{$host}{$path}";
        $response = $data === null ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $this->unencryptedCookies = [];

        return $response;
    }

    /**
     * 応答の __Host-<名前> の Cookie の、暗号にしたままの値
     *
     * @param  TestResponse<Response>  $response
     */
    private function raw(TestResponse $response, string $name): ?string
    {
        $cookie = $response->getCookie(HostPrefixedCookies::PREFIX.$name, false);

        return $cookie === null || $cookie->isCleared() ? null : (string) $cookie->getValue();
    }

    /**
     * セッションの Cookie の値（暗号にしたもの）からセッションの番号を取り出す
     */
    private function sessionId(string $raw): string
    {
        return CookieValuePrefix::remove((string) app('encrypter')->decrypt($raw, false));
    }
}
