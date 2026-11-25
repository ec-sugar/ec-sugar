<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Admin\Http\Middleware\EnsureOperatorIsActive;
use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\Middleware\RestrictAdminIp;
use App\Core\Listing\Models\Listing;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Tests\Support\RouteAudit;
use Tests\TestCase;

/**
 * 全経路の認可を機械的に確かめる。経路を足したら、ログインを求めるか公開の印を付けるまでこのテストが落ちる。
 * 新しい経路・機能に付け忘れやすい守り（秘密の道筋の宣言・回数の上限・CSRF・App の印）も同じ一度の調べで見る
 */
final class RouteAuthorizationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_every_route_requires_login_or_is_explicitly_public(): void
    {
        $this->assertSame([], $this->audit()->violations());
    }

    public function test_the_exception_lists_only_hold_routes_that_still_need_them(): void
    {
        $this->assertSame([], $this->audit()->staleExceptions());
    }

    public function test_the_audit_catches_forgotten_shared_guards(): void
    {
        // 新しい経路・機能で付け忘れやすい守り（ADR 0081）。付け忘れた経路を足すと違反として見つかる
        Route::middleware(['storefront', 'public'])->get('/_probe/link/{token}', static fn () => 'x');
        Route::middleware(['storefront', 'public', 'secret-link'])->get('/_probe/declared/{token}', static fn () => 'x');
        Route::middleware(['storefront', 'public', 'signed'])->get('/_probe/laravel-signed', static fn () => 'x');
        Route::middleware(['storefront', 'public'])->get('/_probe/signed-target', static fn () => 'x')->name('storefront.probe.signed-target');
        Route::domain((string) config('admin.domain'))->middleware(['admin', 'auth:operator', 'secret-link'])->get('/_probe/admin-secret', static fn () => 'x');
        Route::middleware(['storefront', 'public'])->post('/_probe/unthrottled', static fn () => 'x');
        Route::middleware(['storefront', 'public', 'throttle:storefront-cart'])->post('/_probe/throttled', static fn () => 'x');
        Route::middleware(['storefront', 'public', 'throttle:storefront-cart'])->post('/_probe/without-csrf', static fn () => 'x')->withoutMiddleware(ValidateCsrfToken::class);
        Route::middleware(['storefront', 'public'])->get('/_probe/app-without-mark', static fn () => 'x')->name('storefront.plugin.faq.probe');
        Route::middleware(['storefront', 'public', 'plugin:faq'])->get('/_probe/app-mark-elsewhere', static fn () => 'x')->name('storefront.probe.elsewhere');
        Route::middleware(['storefront', 'public', 'plugin:gift'])->get('/_probe/app-other-mark', static fn () => 'x')->name('storefront.plugin.faq.other');
        app(Router::class)->getRoutes()->refreshNameLookups();

        $audit = new RouteAudit(app(Router::class), (string) config('admin.domain'), ['storefront.probe.signed-target' => 'probe.php']);
        $violations = implode("\n", $audit->violations());

        $this->assertStringContainsString('_probe/link/{token}: パスにトークン（token）があるのに、秘密のリンクの宣言（secret-link）が無い', $violations);
        $this->assertStringNotContainsString('_probe/declared/{token}', $violations);
        $this->assertStringContainsString('_probe/laravel-signed: 署名を検証する（signed）のに、秘密のリンクの宣言（secret-link）が無い', $violations);
        $this->assertStringContainsString('_probe/signed-target: 署名つきの URL の行き先（probe.php）なのに、秘密のリンクの宣言（secret-link）が無い', $violations);
        $this->assertStringContainsString('_probe/admin-secret: 秘密のリンクの宣言はショップの経路にだけ付ける', $violations);
        $this->assertStringContainsString('_probe/unthrottled: ショップで書き込む経路なのに、回数の上限（throttle）を通らない', $violations);
        $this->assertStringNotContainsString('_probe/throttled', $violations);
        $this->assertStringContainsString('_probe/without-csrf: ショップのセッションの群なのに、CSRF の確認を外している', $violations);
        $this->assertStringContainsString('_probe/app-without-mark: App faq の経路なのに、その App を使う店だけに開く印（plugin:faq）を通らない', $violations);
        $this->assertStringContainsString('_probe/app-mark-elsewhere: App の印（plugin:faq）があるのに、経路の名前が storefront.plugin.<名前>. で始まらない', $violations);
        $this->assertStringContainsString('_probe/app-other-mark: App faq の経路なのに、その App を使う店だけに開く印（plugin:faq）を通らない', $violations);
    }

    public function test_signed_link_targets_are_collected_from_the_code_and_must_be_written_as_names(): void
    {
        $code = <<<'PHP'
            <?php
            $a = SecretLinks::signed('storefront.orders.show', ['orderId' => $id], now()->addDays(30));
            $b = SecretLinks::path(
                'storefront.preview', $query);
            $c = SecretLinks::signed($name, [], now()->addHour());
            PHP;

        [$names, $bad] = RouteAudit::secretLinkCalls($code);

        $this->assertSame(['storefront.orders.show', 'storefront.preview'], $names);
        $this->assertSame([5], $bad);

        // 今のコードでは、どの行き先も名前で書いてあり、経路がある（宣言は test_every_route_requires_login_or_is_explicitly_public が確かめる）
        [$collected, $unreadable] = RouteAudit::signedRouteNames();
        $this->assertSame([], $unreadable);
        $this->assertArrayHasKey('storefront.orders.show', $collected);
        $this->assertArrayHasKey('storefront.plugin.reviews.order', $collected);
        $this->assertArrayHasKey('storefront.preview.theme.section', $collected);
    }

    public function test_every_admin_route_that_requires_login_answers_401_to_strangers(): void
    {
        $routes = $this->audit()->adminRoutesRequiringLogin();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            // 引数は ULID で埋める。決まった語のどれか（例: 写真の大きさ large|medium）の制約があれば、その最初の語。
            // ULID が合わない制約（例: プラグインの名前は英小文字で始まる）には、合う名前の形を使う
            $uri = preg_replace_callback('/\{(\w+)\??\}/', static function (array $m) use ($route): string {
                $pattern = $route->wheres[$m[1]] ?? '';
                $ulid = strtolower((string) Str::ulid());
                // 乱数 32 バイトの base64url（OAuth の許可の頼みの合い言葉。ADR 0101）
                $handle = str_repeat('a', 43);

                return match (true) {
                    $m[1] === 'tenant' => 'nope',
                    preg_match('/^[a-z]+(\|[a-z]+)*$/', $pattern) === 1 => explode('|', $pattern)[0],
                    $pattern !== '' && preg_match('#\A(?:'.$pattern.')\z#', $ulid) !== 1 && preg_match('#\A(?:'.$pattern.')\z#', 'probe') === 1 => 'probe',
                    $pattern !== '' && preg_match('#\A(?:'.$pattern.')\z#', $ulid) !== 1 && preg_match('#\A(?:'.$pattern.')\z#', $handle) === 1 => $handle,
                    default => $ulid,
                };
            }, $route->uri());
            $method = $route->methods()[0];

            $status = $this->json($method, 'http://'.config('admin.domain').'/'.$uri)->status();
            $this->assertSame(401, $status, RouteAudit::key($route));
            $this->assertFalse(tenancy()->initialized, RouteAudit::key($route));
        }
    }

    public function test_the_audit_catches_a_route_without_protection(): void
    {
        // 付け忘れの経路を足すと違反として見つかる（このテスト自体が効いていることの確かめ）
        Route::get('/_probe/forgotten', static fn () => 'x');
        Route::domain((string) config('admin.domain'))->get('/_probe/admin-without-fence', static fn () => 'x')->middleware('auth:operator');
        Route::domain((string) config('admin.domain'))->middleware(['admin', 'auth:operator', EnterTenant::class])->get('/_probe/{tenant}/wrong-place', static fn () => 'x');
        Route::domain((string) config('admin.domain'))->middleware(['admin', 'public', 'auth:operator'])->get('/_probe/both', static fn () => 'x');
        Route::domain((string) config('admin.domain'))->middleware(['admin', 'auth:web'])->get('/_probe/web-guard', static fn () => 'x');
        Route::domain((string) config('admin.domain'))->middleware(['admin', 'auth:operator', EnsureOperatorIsActive::class, EnterTenant::class])
            ->get('/api/tenants/{tenant}/_probe/{listing}', static fn (Listing $listing) => $listing->id);
        // 店頭の守りを通らない店頭の経路
        Route::middleware(['web', InitializeTenancyByDomain::class, 'public'])->get('/_probe/bare-store', static fn () => 'x');
        // 店頭で運営のガードを使う経路（店頭のログインは customer だけ。ADR 0044）
        Route::middleware(['storefront', 'auth:operator'])->get('/_probe/store-operator', static fn () => 'x');
        Route::middleware(['storefront', 'auth:customer'])->get('/_probe/store-customer', static fn () => 'x');

        $violations = implode("\n", $this->audit()->violations());

        $this->assertStringContainsString('GET */_probe/forgotten: ログインも公開の印も無い', $violations);
        $this->assertStringContainsString('_probe/admin-without-fence: 管理画面なのに接続元の制限を通らない', $violations);
        $this->assertStringContainsString('_probe/{tenant}/wrong-place: EnterTenant は管理画面の /api/tenants/{tenant}/… にだけ付ける', $violations);
        $this->assertStringContainsString('_probe/{tenant}/wrong-place: EnterTenant の前に auth:operator と EnsureOperatorIsActive が要る', $violations);
        $this->assertStringContainsString('_probe/both: ログインと公開の印が両方ある', $violations);
        $this->assertStringContainsString('_probe/web-guard: 管理画面のログインが operator のガードではない', $violations);
        $this->assertStringContainsString('_probe/{listing}: テナントの経路でモデルを結びつけている', $violations);
        $this->assertStringContainsString('_probe/bare-store: 店頭の経路なのに、店頭の防御ヘッダが一番外側に無い', $violations);
        $this->assertStringContainsString('_probe/bare-store: 店頭の経路なのに中央のホストを断る処理を通らない', $violations);
        $this->assertStringContainsString('_probe/bare-store: 店頭の経路なのに壊れた文字を断る処理を通らない', $violations);
        $this->assertStringContainsString('_probe/store-operator: 店頭のログインが customer のガードではない', $violations);
        $this->assertStringNotContainsString('_probe/store-customer', $violations);
    }

    public function test_only_the_listed_admin_routes_skip_the_address_fence(): void
    {
        // 接続元の制限の外にいる管理画面の経路は、一覧（/mcp と OAuth の機械の受け口）とちょうど同じ（ADR 0101 の要点の 6）
        $router = app(Router::class);
        $free = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $middleware = array_map(static fn (mixed $m): string => is_string($m) ? $m : get_debug_type($m), $router->gatherRouteMiddleware($route));

            if ($route->getDomain() === config('admin.domain') && ! in_array(RestrictAdminIp::class, $middleware, true)) {
                $free[] = RouteAudit::adminKey($route);
            }
        }

        sort($free);
        $expected = array_keys(RouteAudit::WITHOUT_ADMIN_IP);
        sort($expected);
        $this->assertSame($expected, $free);

        // 許可の画面（頼みの受付・ログイン・SPA の殻と API）は制限の中
        foreach (['GET /oauth/authorize', 'POST /auth/login', 'GET /{page?}', 'GET /api/oauth-requests/{requestId}', 'POST /api/oauth-requests/{requestId}/approve'] as $inside) {
            $this->assertNotContains($inside, $free, $inside);
        }

        // 一覧に無い経路が制限を外すと、見張りが落ちる
        Route::domain((string) config('admin.domain'))->middleware(['admin', 'public'])->withoutMiddleware(RestrictAdminIp::class)->get('/_probe/open', static fn () => 'x');
        $this->assertStringContainsString('_probe/open: 管理画面なのに接続元の制限を通らない', implode("\n", $this->audit()->violations()));
    }

    private function audit(): RouteAudit
    {
        return new RouteAudit(app(Router::class), (string) config('admin.domain'));
    }
}
