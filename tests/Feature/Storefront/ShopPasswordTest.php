<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Shop\Models\ShopSettings;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\ClientSource;
use App\Storefront\Access\ShopPasswordCookie;
use App\Storefront\Http\Controllers\PasswordController;
use App\Storefront\Http\Middleware\RequireShopAccess;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RegistersSampleTheme;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ショップの公開の状態とパスワード。外とつながる境界なので、関所の内と外・Cookie の偽り・回数の制限まで確かめる。
 * 準備中のショップは「準備中です」の画面のほかを見せず、正しいパスワードを入れた人にだけ、その店・今の世代の Cookie を渡す。
 * 決済代行の通知とヘルスチェックは準備中でも止めない
 */
final class ShopPasswordTest extends TestCase
{
    use DatabaseTruncation;
    use RegistersSampleTheme;
    use WithTenants;

    private const string PASSWORD = 'preview-2026';

    /** 作った直後の店は準備中のまま確かめる */
    protected bool $openNewShops = false;

    private string $listingId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->tenant('spa');
        $this->tenant('spb');
        $this->listingId = $this->inside('spa', static function (): string {
            $seller = Seller::query()->firstOrFail();

            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '秘密の新商品', 'unit_amount' => 1200, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);

            return $listing->id;
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_new_shop_is_preparing_and_hides_every_screen(): void
    {
        $this->assertSame(ShopStatus::Preparing, $this->inside('spa', static fn (): ShopStatus => app(StorefrontAccessService::class)->status()));

        foreach (['/', '/products', "/products/{$this->listingId}", '/categories/new', '/cart', '/checkout', '/checkout/confirm', '/orders', "/orders/{$this->listingId}", '/legal/external', '/consent', '/no-such-page'] as $path) {
            $response = $this->send('spa', 'GET', $path)->assertRedirect('http://spa.localhost/password');
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $path);
            $this->assertStringNotContainsString('秘密の新商品', (string) $response->getContent(), $path);
        }

        // 書き込みも通さない（カートを作らせない・注文を確定させない・照会させない）
        foreach (['/cart/items', '/checkout', '/checkout/confirm', '/checkout/pay', '/orders', '/consent'] as $path) {
            $this->send('spa', 'POST', $path, ['listing_id' => $this->listingId, 'quantity' => 1])->assertRedirect('http://spa.localhost/password')->assertStatus(303);
        }
        $this->inside('spa', fn () => $this->assertSame(0, DB::table('carts')->count()));

        // JavaScript からの呼び出しには 403 を JSON で
        $this->flushHeaders();
        $this->withHeaders(['Accept' => 'application/json'])->post('http://spa.localhost/cart/items', ['listing_id' => $this->listingId, 'quantity' => 1])
            ->assertForbidden()->assertJsonPath('message', 'ショップは準備中です。');
        tenancy()->end();
        $this->flushHeaders();

        // 「準備中です」の画面は、どのテーマでもそのテーマの画面で。外枠（メニュー・カート）と商品は出さない
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('spa')->forceFill(['theme' => $theme])->save();
            $response = $this->send('spa', 'GET', '/password')->assertOk()
                ->assertSee('data-test="password-page"', false)->assertSee('準備中です')->assertSee('<meta name="robots" content="noindex, nofollow">', false)
                ->assertSee('店 spa')->assertDontSee('秘密の新商品')->assertDontSee('data-cart-link', false)->assertDontSee('/products', false);
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $theme);
            $this->assertStringContainsString("plugins/theme-{$theme}/resources/views/password.blade.php", $this->viewPathOf($response), $theme);
        }

        // テーマに画面が無ければ核の既定の画面で
        $this->registerSampleTheme();
        Tenant::query()->findOrFail('spa')->forceFill(['theme' => 'sample'])->save();
        $response = $this->send('spa', 'GET', '/password')->assertOk()->assertSee('data-test="password-page"', false)->assertSee('準備中です');
        $this->assertStringContainsString('resources/views/storefront/password.blade.php', $this->viewPathOf($response));
    }

    public function test_only_the_listed_routes_stay_outside_the_gate(): void
    {
        // 外に出すのは決めた経路だけ（一覧は 1 か所）
        // 商品のプレビューは署名つきのリンクで、その商品の画面だけ（ListingPreviewTest）
        // テーマの下書きのプレビュー（ADR 0039 区切り 2）も署名つきのリンクで、そのテーマのそのページだけ（ThemePreviewTest）
        // App の配信の停止のリンクは合い言葉の合う 1 人の購読を止めるだけ（NewsletterAppTest）
        // カゴ落ちのお知らせの配信の停止のリンクも、合い言葉の合う 1 通の宛先の同意を止めるだけ（CartReminderAppTest）
        // LINE の Webhookは、署名の合う知らせでつないだ会員の「友だちか」の印を変えるだけ（LineNotifyTest）
        $this->assertSame(['storefront.password', 'storefront.password.unlock', 'storefront.checkout.complete', 'storefront.orders.show', 'storefront.legal', 'storefront.preview', 'storefront.preview.theme', 'storefront.preview.theme.section', 'storefront.plugin.newsletter.unsubscribe', 'storefront.plugin.newsletter.unsubscribe.submit', 'storefront.plugin.cart-reminder.unsubscribe', 'storefront.plugin.cart-reminder.unsubscribe.submit', 'storefront.plugin.favorites.unsubscribe', 'storefront.plugin.favorites.unsubscribe.submit', 'storefront.plugin.line.webhook'], RequireShopAccess::OUTSIDE);

        // 特商法の表記・Stripe から戻る先（注文が無いのでトップへ。関所へは送らない）は見られる。noindex は付く
        foreach (['/legal/commerce' => null, '/checkout/complete' => 'http://spa.localhost'] as $path => $redirect) {
            $response = $this->send('spa', 'GET', $path);
            $redirect === null ? $response->assertOk() : $response->assertRedirect($redirect);
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $path);
        }

        // ご注文の確認は、署名の正しいリンクだけ（注文が無いので照会の画面へ送られ、その照会の画面は内側）
        $order = '01aaaaaaaaaaaaaaaaaaaaaaaa';
        $signed = URL::temporarySignedRoute('storefront.orders.show', now()->addDay(), ['orderId' => $order], absolute: false);
        $response = $this->send('spa', 'GET', $signed)->assertRedirect('http://spa.localhost/orders');
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->send('spa', 'GET', '/orders')->assertRedirect('http://spa.localhost/password');
        $this->send('spa', 'POST', '/orders', ['order_number' => $order, 'email' => 'a@example.com'])->assertRedirect('http://spa.localhost/password');
        foreach (["/orders/{$order}", preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $signed), str_replace($order, '01bbbbbbbbbbbbbbbbbbbbbbbb', $signed)] as $path) {
            $this->send('spa', 'GET', (string) $path)->assertRedirect('http://spa.localhost/password');
        }
        $this->travel(2)->days();
        $this->send('spa', 'GET', $signed)->assertRedirect('http://spa.localhost/password');
    }

    public function test_another_apps_unsubscribe_route_stays_inside_the_gate(): void
    {
        // ほかの App が同じ名前（unsubscribe）の経路を足しても、関所の外に出ない（外に出すのはメルマガの 2 つだけ）
        Route::middleware(['storefront', 'public'])->prefix('p/other')->name('storefront.plugin.other.')->group(static function (): void {
            Route::get('/unsubscribe/{token}', static fn () => 'ほかの App の画面')->name('unsubscribe');
            Route::post('/unsubscribe/{token}', static fn () => 'ほかの App の画面')->name('unsubscribe.submit');
        });
        app('router')->getRoutes()->refreshNameLookups();

        $this->send('spa', 'GET', '/p/other/unsubscribe/abc')->assertRedirect('http://spa.localhost/password');
        $this->send('spa', 'POST', '/p/other/unsubscribe/abc')->assertRedirect('http://spa.localhost/password');
    }

    public function test_the_right_password_opens_the_shop_and_returns_to_the_page_asked_for(): void
    {
        $this->setPassword('spa', self::PASSWORD);

        $this->send('spa', 'GET', '/products')->assertRedirect('http://spa.localhost/password');
        $response = $this->unlock('spa', self::PASSWORD)->assertRedirect('http://spa.localhost/products');

        $cookie = $response->getCookie(ShopPasswordCookie::NAME, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain());
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $cookie->getExpiresTime(), 5);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $response->getCookie(ShopPasswordCookie::NAME)?->getValue());

        $pass = $this->passFrom($response);
        $page = $this->send('spa', 'GET', '/products', cookie: $pass)->assertOk()->assertSee('秘密の新商品');
        $this->assertSame('noindex, nofollow', $page->headers->get('X-Robots-Tag'));
        $this->send('spa', 'GET', "/products/{$this->listingId}", cookie: $pass)->assertOk();

        // 入れた人は注文まで試せる（仮の決め: カートに入れて注文の入力へ進める）
        $this->send('spa', 'POST', '/cart/items', ['listing_id' => $this->listingId, 'quantity' => 1], cookie: $pass)->assertRedirect()->assertSessionMissing('cart_error');
        $this->inside('spa', fn () => $this->assertSame(1, DB::table('carts')->count()));
    }

    public function test_a_wrong_password_and_a_missing_password_open_nothing(): void
    {
        $this->unlock('spa', 'anything-at-all')->assertRedirect('http://spa.localhost/password')->assertSessionHas('cart_error', 'パスワードが違います。');

        $this->setPassword('spa', self::PASSWORD);

        foreach (['wrong-password', '', strtoupper(self::PASSWORD), self::PASSWORD.' ', self::PASSWORD.str_repeat('x', 80)] as $wrong) {
            Cache::store()->flush();
            $response = $this->unlock('spa', $wrong)->assertRedirect('http://spa.localhost/password');
            $this->assertNull($response->getCookie(ShopPasswordCookie::NAME, false), $wrong);
        }
        $this->unlock('spa', ['array'])->assertRedirect('http://spa.localhost/password');
    }

    public function test_wrong_passwords_are_limited_per_source_and_per_shop(): void
    {
        $this->setPassword('spa', self::PASSWORD);
        $this->setPassword('spb', self::PASSWORD);

        for ($i = 0; $i < PasswordController::PER_SOURCE; $i++) {
            $this->unlock('spa', "wrong-{$i}-xx", ip: '203.0.113.1')->assertSessionHas('cart_error', 'パスワードが違います。');
        }

        // 止まったら、正しいパスワードでも比べずに断る
        $refused = $this->unlock('spa', self::PASSWORD, ip: '203.0.113.1')->assertSessionHas('cart_error', fn (string $m): bool => str_contains($m, '多すぎ'));
        $this->assertNull($refused->getCookie(ShopPasswordCookie::NAME, false));

        // ほかの接続元・同じ接続元のほかの店は止まらない
        $this->assertNotNull($this->unlock('spa', self::PASSWORD, ip: '203.0.113.2')->getCookie(ShopPasswordCookie::NAME, false));
        $this->assertNotNull($this->unlock('spb', self::PASSWORD, ip: '203.0.113.1')->getCookie(ShopPasswordCookie::NAME, false));
    }

    public function test_ipv6_addresses_in_the_same_64_count_as_one_source(): void
    {
        $this->setPassword('spa', self::PASSWORD);

        // 同じ /64 の中で後ろを変えても、同じ接続元として数える
        for ($i = 0; $i < PasswordController::PER_SOURCE; $i++) {
            $this->unlock('spa', "wrong-{$i}-xx", ip: "2001:db8:1:2::{$i}")->assertSessionHas('cart_error', 'パスワードが違います。');
        }
        $this->assertNull($this->unlock('spa', self::PASSWORD, ip: '2001:db8:1:2:ffff:ffff:ffff:ffff')->assertSessionHas('cart_error', fn (string $m): bool => str_contains($m, '多すぎ'))->getCookie(ShopPasswordCookie::NAME, false));

        // となりの /64 は別の接続元
        $this->assertNotNull($this->unlock('spa', self::PASSWORD, ip: '2001:db8:1:3::1')->getCookie(ShopPasswordCookie::NAME, false));

        $this->assertSame('2001:db8:1:2::/64', ClientSource::of('2001:0db8:0001:0002:abcd::9'));
        $this->assertSame('192.0.2.1', ClientSource::of('::ffff:192.0.2.1'));
        $this->assertSame('192.0.2.1', ClientSource::of('192.0.2.1'));
    }

    public function test_the_shop_wide_limit_only_slows_down_and_never_locks_out(): void
    {
        $this->setPassword('spa', self::PASSWORD);
        $this->setPassword('spb', self::PASSWORD);

        // 接続元を変えた続けざまの試しは、店全体で間隔を空けるまで比べない（正しいパスワードでも「少し待って」）
        $this->unlock('spa', 'wrong-first-xx', ip: '198.51.100.1')->assertSessionHas('cart_error', 'パスワードが違います。');
        foreach (['198.51.100.2' => 'wrong-second-x', '198.51.100.3' => self::PASSWORD] as $ip => $password) {
            $this->assertNull($this->send('spa', 'POST', '/password', ['password' => $password], ip: $ip)
                ->assertSessionHas('cart_error', fn (string $m): bool => str_contains($m, '少し待って'))->getCookie(ShopPasswordCookie::NAME, false));
        }
        // ほかの店は待たない
        $this->assertNotNull($this->send('spb', 'POST', '/password', ['password' => self::PASSWORD], ip: '198.51.100.3')->getCookie(ShopPasswordCookie::NAME, false));

        // 待たされた分は接続元の回数に数えない。少し待てば、正しいパスワードで入れる（締め出さない）
        for ($i = 0; $i < 2 * PasswordController::PER_SOURCE; $i++) {
            $this->send('spa', 'POST', '/password', ['password' => self::PASSWORD], ip: '198.51.100.3')->assertSessionHas('cart_error', fn (string $m): bool => str_contains($m, '少し待って'));
        }
        $this->travel(PasswordController::SHOP_INTERVAL_SECONDS + 1)->seconds();
        $this->assertNotNull($this->send('spa', 'POST', '/password', ['password' => self::PASSWORD], ip: '198.51.100.3')->getCookie(ShopPasswordCookie::NAME, false));
    }

    public function test_changing_the_password_shuts_out_every_earlier_cookie(): void
    {
        $this->setPassword('spa', self::PASSWORD);
        $pass = $this->passFrom($this->unlock('spa', self::PASSWORD));
        $this->send('spa', 'GET', '/', cookie: $pass)->assertOk();

        // 同じ値を置き直しても世代が進む（前の Cookie はすべて無効）
        $this->setPassword('spa', self::PASSWORD);
        $this->send('spa', 'GET', '/', cookie: $pass)->assertRedirect('http://spa.localhost/password');

        $pass = $this->passFrom($this->unlock('spa', self::PASSWORD));
        $this->setPassword('spa', 'another-pass-1');
        $this->send('spa', 'GET', '/', cookie: $pass)->assertRedirect('http://spa.localhost/password');
        $this->unlock('spa', self::PASSWORD)->assertSessionHas('cart_error', 'パスワードが違います。');

        // 外すと、誰も入れない（前の Cookie も）
        $pass = $this->passFrom($this->unlock('spa', 'another-pass-1'));
        $this->setPassword('spa', null);
        $this->send('spa', 'GET', '/', cookie: $pass)->assertRedirect('http://spa.localhost/password');
        $this->unlock('spa', 'another-pass-1')->assertSessionHas('cart_error', 'パスワードが違います。');
    }

    public function test_a_cookie_from_another_shop_a_forged_cookie_or_an_old_cookie_opens_nothing(): void
    {
        $this->setPassword('spa', self::PASSWORD);
        $this->setPassword('spb', self::PASSWORD);
        $passA = $this->passFrom($this->unlock('spa', self::PASSWORD));

        // 同じパスワード・同じ世代でも、ほかの店の Cookie では入れない
        $this->send('spb', 'GET', '/', cookie: $passA)->assertRedirect('http://spb.localhost/password');
        $this->send('spa', 'GET', '/', cookie: $passA)->assertOk();

        // 中身を書き換えた・署名の無い・形の崩れた Cookie
        [$tenant, $generation, $expires, $mac] = explode('|', $passA);
        foreach ([
            "spb|{$generation}|{$expires}|{$mac}",
            "{$tenant}|{$generation}|".((int) $expires + 999999)."|{$mac}",
            "{$tenant}|{$generation}|{$expires}|".str_repeat('0', 64),
            "{$tenant}|{$generation}|{$expires}",
            "{$tenant}|{$generation}|{$expires}|{$mac}|x",
            '',
        ] as $forged) {
            $this->send('spa', 'GET', '/', cookie: $forged)->assertRedirect('http://spa.localhost/password');
        }

        // 作り直した店（同じ店の ID・同じパスワード・同じ世代でも、店の設定の行が作り直されている）では前の Cookie は効かない
        $before = $this->inside('spa', static fn (): array => [ShopSettings::query()->value('id'), ShopSettings::query()->value('storefront_password_generation')]);
        $this->inside('spa', static function (): void {
            ShopSettings::query()->delete();
            app(StorefrontAccessService::class)->setPassword(self::PASSWORD, 'cli:test');
        });
        $after = $this->inside('spa', static fn (): array => [ShopSettings::query()->value('id'), ShopSettings::query()->value('storefront_password_generation')]);
        $this->assertNotSame($before[0], $after[0]);
        $this->assertSame($before[1], $after[1]);
        $this->send('spa', 'GET', '/', cookie: $passA)->assertRedirect('http://spa.localhost/password');
        $passA = $this->passFrom($this->unlock('spa', self::PASSWORD));
        $this->send('spa', 'GET', '/', cookie: $passA)->assertOk();

        // 期限を過ぎた Cookie（中身の期限で見る）
        $this->travel(ShopPasswordCookie::DAYS + 1)->days();
        $this->send('spa', 'GET', '/', cookie: $passA)->assertRedirect('http://spa.localhost/password');
    }

    public function test_an_open_shop_is_seen_by_everyone(): void
    {
        $this->setPassword('spa', self::PASSWORD);
        $this->inside('spa', static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, 'cli:test'));

        $response = $this->send('spa', 'GET', '/')->assertOk()->assertSee('秘密の新商品');
        $this->assertNull($response->headers->get('X-Robots-Tag'));
        $this->send('spa', 'GET', "/products/{$this->listingId}")->assertOk();
        $this->send('spa', 'GET', '/password')->assertRedirect('http://spa.localhost');
        $this->unlock('spa', 'wrong')->assertRedirect('http://spa.localhost');

        // 準備中に戻すと、また閉じる（ほかの店は準備中のまま）
        $this->send('spb', 'GET', '/')->assertRedirect('http://spb.localhost/password');
        $this->inside('spa', static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'cli:test'));
        $this->send('spa', 'GET', '/')->assertRedirect('http://spa.localhost/password');
    }

    public function test_webhooks_health_checks_and_photos_are_not_behind_the_gate(): void
    {
        // 決済代行の通知は署名で確かめる（署名が無いので 400。関所へは送らない）
        $this->call('POST', 'http://spa.localhost/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);
        tenancy()->end();
        $this->get('http://spa.localhost/up')->assertOk();
        $this->get('http://localhost/up')->assertOk();
        // 写真は ID を知る人だけが引ける（ID は関所の内側の画面にしか出ない）。関所へは送らない
        $this->get('http://spa.localhost/media/listings/01aaaaaaaaaaaaaaaaaaaaaaaa/large')->assertNotFound();
        tenancy()->end();
    }

    public function test_the_migration_opens_existing_shops_and_leaves_new_ones_preparing(): void
    {
        $migration = require database_path('migrations/tenant/2026_10_30_000200_add_storefront_access_to_shop_settings.php');

        // 設定の行がある店（spa）と、行が無いが商品のある店（spa の行を消した形）は公開に
        $this->inside('spa', static function () use ($migration): void {
            $migration->down();
            $migration->up();
        });
        $this->assertSame(ShopStatus::Open, $this->inside('spa', static fn (): ShopStatus => app(StorefrontAccessService::class)->status()));

        $this->inside('spa', static function () use ($migration): void {
            $migration->down();
            ShopSettings::query()->delete();
            $migration->up();
        });
        $this->assertSame('open', $this->inside('spa', static fn (): mixed => ShopSettings::query()->value('storefront_status')));
        $this->send('spa', 'GET', '/')->assertOk();

        // 中身の無い店で行も無ければ作らない（準備中として扱う）
        $this->inside('spb', static function () use ($migration): void {
            $migration->down();
            ShopSettings::query()->delete();
            $migration->up();
        });
        $this->assertFalse($this->inside('spb', static fn (): bool => ShopSettings::query()->exists()));
        $this->send('spb', 'GET', '/')->assertRedirect('http://spb.localhost/password');
    }

    /**
     * パスワードを送る。店全体の間隔（SHOP_INTERVAL_SECONDS）を空けてから送る（間隔の制限そのものは別のテストで確かめる）
     *
     * @return TestResponse<Response>
     */
    private function unlock(string $shop, mixed $password, ?string $ip = null): TestResponse
    {
        $this->travel(PasswordController::SHOP_INTERVAL_SECONDS + 1)->seconds();

        return $this->send($shop, 'POST', '/password', ['password' => $password], ip: $ip);
    }

    private function setPassword(string $shop, ?string $password): void
    {
        $this->inside($shop, static fn () => app(StorefrontAccessService::class)->setPassword($password, 'cli:test'));
    }

    /**
     * 応答の Cookie の中身（Laravel の暗号化を外したもの。次の要求ではテストが暗号化し直して送る）
     *
     * @param  TestResponse<Response>  $response
     */
    private function passFrom(TestResponse $response): string
    {
        $value = $response->getCookie(ShopPasswordCookie::NAME)?->getValue();
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function viewPathOf(TestResponse $response): string
    {
        $view = $response->original;

        return $view instanceof View ? (string) realpath($view->getPath()) : '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $shop, string $method, string $path, array $data = [], ?string $ip = null, ?string $cookie = null): TestResponse
    {
        $url = "http://{$shop}.localhost{$path}";
        // 要求ごとに送るものを決め直す（前の要求の Cookie・見出しを持ち越さない。セッションは続く）
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withServerVariables($ip === null ? [] : ['REMOTE_ADDR' => $ip]);

        if ($cookie !== null) {
            $this->withCookie(ShopPasswordCookie::NAME, $cookie);
        }

        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();

        return $response;
    }
}
