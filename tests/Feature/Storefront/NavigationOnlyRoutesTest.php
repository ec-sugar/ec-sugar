<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Storefront\Http\Middleware\NavigationOnly;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Tests\Support\AppContract;
use Tests\TestCase;

/**
 * 個人情報の画面の印（navigation-only。ADR 0088）の付け忘れの見張り。人の目で「付け忘れ」に気づく前提をやめる（RouteAuthorizationTest と同じ形）。
 *
 * 規則（機械で決める。一覧に書かなくても落ちる）:
 *   1. ショップで会員のログインを求める経路（auth:customer）は、印を付ける
 *   2. 道筋に区切り account を持つショップの経路（核のマイページ・App の /p/<名前>/account…）は、印を付ける。ログインの前の account/login… は除く
 *   3. 道筋に {orderId} を持つショップの経路（注文の画面）は、印を付ける
 *   4. 印は 1 つの経路に 1 つだけ。セッションのある店頭の群（storefront）の中だけ（画面の鍵はセッションの合い言葉から作る）
 *   5. 印は回数の上限（throttle）より前に動く（タグの JavaScript の fetch で、お客さんの回数の枠を減らさせない）
 *   6. 画面の鍵も通す経路（navigation-only:page-key）は、下の一覧の分だけ（核の JavaScript が同じ画面の一部を読む所）
 *   7. 道筋が demo/ で始まるショップの経路（デモの店だけの画面。来訪者の受け箱・デモの会員。ADR 0086）は、印を付ける
 * そのうえで、印の付いた経路の全部が、下の一覧（MARKED）とちょうど同じであること（外すのも足すのも、ここを直して見直す）
 */
final class NavigationOnlyRoutesTest extends TestCase
{
    /**
     * 印の付く経路（メソッドと道筋）→ 引数（page-key か空）。経路の一覧は ADR 0088 にも同じ考えで書いた
     *
     * @var array<string, string>
     */
    private const array MARKED = [
        // 核の会員の画面（auth:customer）と、ログインのコードの画面
        'GET account' => '',
        'GET account/addresses' => '',
        'POST account/addresses' => '',
        'GET account/addresses/{addressId}' => '',
        'POST account/addresses/{addressId}' => '',
        'POST account/addresses/{addressId}/default' => '',
        'POST account/addresses/{addressId}/delete' => '',
        'GET account/confirm' => '',
        'POST account/confirm' => '',
        'POST account/confirm/passkey' => '',
        'POST account/confirm/passkey/options' => 'page-key',
        'POST account/confirm/send' => '',
        'GET account/email' => '',
        'POST account/email' => '',
        'POST account/email/verify' => '',
        'GET account/leave' => '',
        'POST account/leave' => '',
        'GET account/login/code' => '',
        'POST account/logout' => '',
        'GET account/passkeys' => '',
        'POST account/passkeys' => '',
        'POST account/passkeys/options' => 'page-key',
        'POST account/passkeys/{passkeyId}/delete' => '',
        // 注文の入力と確かめ（購入の完了はタグを差し込む画面なので除く）
        'GET checkout' => '',
        'POST checkout' => '',
        'GET checkout/confirm' => '',
        'POST checkout/confirm' => '',
        'POST checkout/pay' => '',
        // ご注文の確認
        'GET orders/{orderId}' => '',
        // デモの店の受け箱（来訪者に届くはずだったメール。ログインのコード・注文の名前と届け先を出す）と、デモの会員でログイン（帯のフォームから。ADR 0086）
        'GET demo/mails' => '',
        'POST demo/member' => '',
        // App「booking」
        'GET p/booking/account' => '',
        'GET p/booking/account/{id}/cancel' => '',
        'POST p/booking/account/{id}/cancel' => '',
        'GET p/booking/done' => '',
        'GET p/booking/{listingId}' => '',
        'POST p/booking/{listingId}' => '',
        'GET p/booking/{listingId}/confirm' => '',
        'POST p/booking/{listingId}/place' => '',
        // App「contact」
        'GET p/contact' => '',
        'POST p/contact' => '',
        'GET p/contact/order/{orderId}' => '',
        'POST p/contact/order/{orderId}' => '',
        'GET p/contact/product/{listingId}' => '',
        'POST p/contact/product/{listingId}' => '',
        // App「downloads」
        'GET p/downloads/account' => '',
        'GET p/downloads/file/{grantId}' => '',
        'GET p/downloads/orders/{orderId}' => '',
        'POST p/downloads/orders/{orderId}/{grantId}' => '',
        // App「favorites」
        'GET p/favorites/list' => '',
        'POST p/favorites/settings' => '',
        // App「gift-cards」
        'GET p/gift-cards' => '',
        'POST p/gift-cards' => '',
        'GET p/gift-cards/confirm' => '',
        'GET p/gift-cards/done' => '',
        'POST p/gift-cards/place' => '',
        // App「gift」
        'GET p/gift/account' => '',
        'GET p/gift/orders/{orderId}' => '',
        'GET p/gift/orders/{orderId}/cancel' => '',
        'POST p/gift/orders/{orderId}/cancel' => '',
        'POST p/gift/orders/{orderId}/renew' => '',
        'GET p/gift/{token}' => '',
        'POST p/gift/{token}' => '',
        'GET p/gift/{token}/confirm' => '',
        'POST p/gift/{token}/confirm' => '',
        // App「line」
        'GET p/line/account' => '',
        'GET p/line/code' => '',
        'POST p/line/code' => '',
        'POST p/line/connect' => '',
        'POST p/line/disconnect' => '',
        'GET p/line/email' => '',
        'POST p/line/email' => '',
        // App「lottery」
        'GET p/lottery/account' => '',
        'POST p/lottery/account/card' => '',
        'GET p/lottery/done' => '',
        // チケットのリセールの抽選（会員の申し込みの流れ）
        'GET p/lottery/resale/{listingId}' => '',
        'POST p/lottery/resale/{listingId}' => '',
        'GET p/lottery/resale/{listingId}/confirm' => '',
        'POST p/lottery/resale/{listingId}/place' => '',
        'GET p/lottery/{listingId}' => '',
        'POST p/lottery/{listingId}' => '',
        'GET p/lottery/{listingId}/confirm' => '',
        'POST p/lottery/{listingId}/place' => '',
        // App「member-ranks」
        'GET p/member-ranks/account' => '',
        // App「newsletter」
        'GET p/newsletter' => '',
        'POST p/newsletter' => '',
        'GET p/newsletter/account' => '',
        'POST p/newsletter/account' => '',
        // App「order-print」
        'GET p/order-print/receipt/{orderId}' => '',
        'POST p/order-print/receipt/{orderId}' => '',
        // App「referral」
        'GET p/referral/invite' => '',
        'POST p/referral/invite/renew' => '',
        // App「restock」
        'GET p/restock/{listingId}' => '',
        'POST p/restock/{listingId}' => '',
        // App「reviews」
        'GET p/reviews/account' => '',
        'GET p/reviews/orders/{orderId}' => '',
        'GET p/reviews/orders/{orderId}/{listingId}' => '',
        'POST p/reviews/orders/{orderId}/{listingId}' => '',
        // App「subscription」
        'GET p/subscription/account' => '',
        'POST p/subscription/account/card' => '',
        'GET p/subscription/account/{id}/cancel' => '',
        'POST p/subscription/account/{id}/cancel' => '',
        'POST p/subscription/account/{id}/interval' => '',
        'POST p/subscription/account/{id}/pause' => '',
        'POST p/subscription/account/{id}/resume' => '',
        'POST p/subscription/account/{id}/skip' => '',
        'POST p/subscription/account/{id}/update' => '',
        'GET p/subscription/done' => '',
        'GET p/subscription/{listingId}' => '',
        'POST p/subscription/{listingId}' => '',
        'GET p/subscription/{listingId}/confirm' => '',
        'POST p/subscription/{listingId}/place' => '',
        // App「tickets」
        'GET p/tickets/account' => '',
        'GET p/tickets/account/{id}' => '',
        'POST p/tickets/account/{id}/companion' => '',
        'POST p/tickets/account/{id}/companion/clear' => '',
        'POST p/tickets/account/{id}/passkey/options' => 'page-key',
        'GET p/tickets/account/{id}/qr' => 'page-key',
        'POST p/tickets/account/{id}/unlock' => '',
        // 公式の定価リセールに出す・取り下げる（マイページのフォームから）
        'POST p/tickets/account/{id}/resale' => '',
        'POST p/tickets/account/{id}/resale/withdraw' => '',
        'GET p/tickets/done' => '',
        'GET p/tickets/{listingId}' => '',
        'POST p/tickets/{listingId}' => '',
        'GET p/tickets/{listingId}/confirm' => '',
        'POST p/tickets/{listingId}/passkey/options' => 'page-key',
        'POST p/tickets/{listingId}/place' => '',
    ];

    public function test_every_personal_route_is_marked_and_the_list_matches(): void
    {
        $this->assertSame([], $this->violations());
        $marked = $this->marked();
        ksort($marked);
        // 公開用の 1.0 の木（ADR 0098）に入らない App（定期便・予約・チケット・抽選販売）は、入っていなければ経路も無い
        $absent = AppContract::absentUnpublished();
        $expected = array_filter(self::MARKED, static fn (string $key): bool => preg_match('#\A[A-Z]+ p/([a-z0-9-]+)/#', $key, $m) !== 1 || ! in_array($m[1], $absent, true), ARRAY_FILTER_USE_KEY);
        ksort($expected);
        $this->assertSame($expected, $marked, '印の付いた経路が一覧と違う（足した・外した経路を一覧と ADR 0088 で見直す）');
    }

    public function test_the_audit_catches_a_forgotten_mark(): void
    {
        // 付け忘れ・付け間違いの経路を足すと違反として見つかる（このテスト自体が効いていることの確かめ）
        Route::middleware(['storefront', 'auth:customer'])->get('/_probe/member-only', static fn () => 'x');
        Route::middleware(['storefront', 'public'])->get('/p/_probe/account', static fn () => 'x');
        Route::middleware(['storefront', 'public'])->get('/_probe/orders/{orderId}', static fn (string $orderId) => $orderId);
        Route::middleware(['storefront', 'public'])->get('/demo/_probe', static fn () => 'x');
        Route::middleware(['storefront', 'public', 'navigation-only', 'navigation-only:page-key'])->get('/_probe/twice', static fn () => 'x');
        Route::middleware(['storefront-media', 'public', 'navigation-only'])->get('/_probe/no-session', static fn () => 'x');
        Route::middleware(['storefront', 'public', 'navigation-only:page-key'])->get('/_probe/stray-key', static fn () => 'x');
        // 印の付いた経路と、ログインの前の経路は違反にしない
        Route::middleware(['storefront', 'auth:customer', 'navigation-only'])->get('/_probe/fine', static fn () => 'x');
        Route::middleware(['storefront', 'public'])->get('/account/login/_probe', static fn () => 'x');

        $violations = implode("\n", $this->violations());

        $this->assertStringContainsString('GET _probe/member-only: 会員のログインを求めるのに印が無い', $violations);
        $this->assertStringContainsString('GET p/_probe/account: 道筋に account があるのに印が無い', $violations);
        $this->assertStringContainsString('GET _probe/orders/{orderId}: 注文の画面なのに印が無い', $violations);
        $this->assertStringContainsString('GET demo/_probe: デモの店の画面なのに印が無い', $violations);
        $this->assertStringContainsString('GET _probe/twice: 印が 2 つある', $violations);
        $this->assertStringContainsString('GET _probe/no-session: セッションのある店頭の群の外に印がある', $violations);
        $this->assertStringContainsString('GET _probe/stray-key: 画面の鍵を通す経路が一覧に無い', $violations);
        $this->assertStringNotContainsString('_probe/fine', $violations);
        $this->assertStringNotContainsString('account/login/_probe', $violations);
        // 一覧と比べる方も、足した経路を見つける
        $this->assertArrayHasKey('GET _probe/fine', array_diff_key($this->marked(), self::MARKED));
    }

    /**
     * @return list<string>
     */
    private function violations(): array
    {
        $violations = [];
        $keys = array_keys(array_filter(self::MARKED, static fn (string $allow): bool => $allow === NavigationOnly::WITH_PAGE_KEY));

        foreach ($this->routes() as $key => [$route, $middleware]) {
            $marks = $this->marks($middleware);
            $uri = $route->uri();
            $segments = explode('/', $uri);

            if (in_array(Authenticate::class.':customer', $middleware, true) && $marks === []) {
                $violations[] = "{$key}: 会員のログインを求めるのに印が無い";
            }

            if (in_array('account', $segments, true) && ! str_starts_with($uri, 'account/login') && $marks === []) {
                $violations[] = "{$key}: 道筋に account があるのに印が無い";
            }

            if (str_contains($uri, '{orderId}') && $marks === []) {
                $violations[] = "{$key}: 注文の画面なのに印が無い";
            }

            if ($segments[0] === 'demo' && $marks === []) {
                $violations[] = "{$key}: デモの店の画面なのに印が無い";
            }

            if (count($marks) > 1) {
                $violations[] = "{$key}: 印が 2 つある";
            }

            if ($marks !== [] && ! in_array(StartSession::class, $middleware, true)) {
                $violations[] = "{$key}: セッションのある店頭の群の外に印がある";
            }

            $throttle = array_key_first(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, ThrottleRequests::class)));
            $mark = array_key_first(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, NavigationOnly::class)));

            if ($throttle !== null && $mark !== null && $mark > $throttle) {
                $violations[] = "{$key}: 印が回数の上限より後ろにある";
            }

            if (in_array(NavigationOnly::class.':'.NavigationOnly::WITH_PAGE_KEY, $marks, true) && ! in_array($key, $keys, true)) {
                $violations[] = "{$key}: 画面の鍵を通す経路が一覧に無い";
            }
        }

        return $violations;
    }

    /**
     * @return array<string, string> 印の付いた経路 → 引数
     */
    private function marked(): array
    {
        $marked = [];

        foreach ($this->routes() as $key => [, $middleware]) {
            foreach ($this->marks($middleware) as $mark) {
                $marked[$key] = $mark === NavigationOnly::class ? '' : substr($mark, strlen(NavigationOnly::class) + 1);
            }
        }

        return $marked;
    }

    /**
     * ショップ（ドメインで店を特定する）の経路と、グループを展開した後のミドルウェア
     *
     * @return array<string, array{0: LaravelRoute, 1: list<string>}>
     */
    private function routes(): array
    {
        $router = app(Router::class);
        $routes = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $middleware = array_values(array_map(static fn (mixed $m): string => is_string($m) ? $m : get_debug_type($m), $router->gatherRouteMiddleware($route)));

            if (in_array(InitializeTenancyByDomain::class, $middleware, true) && ! $route->isFallback) {
                $routes[$route->methods()[0].' '.$route->uri()] = [$route, $middleware];
            } else {
                // 印はショップの外（管理画面・中央の通知）には付けない（店とセッションの無い所では画面の鍵を作れない）
                $this->assertSame([], $this->marks($middleware), $route->uri().': ショップの外に印がある');
            }
        }

        return $routes;
    }

    /**
     * @param  list<string>  $middleware
     * @return list<string>
     */
    private function marks(array $middleware): array
    {
        return array_values(array_filter($middleware, static fn (string $m): bool => $m === NavigationOnly::class || str_starts_with($m, NavigationOnly::class.':')));
    }
}
