<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Access\SecretLinks;
use App\Storefront\Http\Middleware\SecretLink;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RouteAudit;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 秘密の道筋の宣言（ADR 0081）。宣言した経路は、核がどの応答にも「参照元を渡さない・残さない・検索に載せない」をまとめて掛ける。
 * 宣言した全部の経路（App のも）・エラーと転送の応答（403・404・419・429）・ふつうの経路には掛けない・署名を核が確かめる書き方・
 * 署名つきの URL を作る部品の守り（宣言・期限の上限・店の文脈）を確かめる。部品の誤りは全部の機能に広がるので、ここで厚く見る
 */
final class SecretLinkTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->tenant('sla');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_every_declared_route_answers_with_the_three_guards_with_or_without_the_apps(): void
    {
        $routes = $this->declaredRoutes();
        $names = array_map(static fn (LaravelRoute $route): string => (string) $route->getName(), $routes);

        // 核の経路と、App の経路（使っていない店では 404、使う店では受け口の答え）の両方がある
        $this->assertContains('storefront.orders.show', $names);
        $this->assertContains('storefront.preview', $names);
        $this->assertContains('storefront.plugin.newsletter.confirm', $names);
        $this->assertContains('storefront.plugin.gift.receive.submit', $names);
        $this->assertContains('storefront.plugin.product-feed.feed', $names);

        foreach ([false, true] as $apps) {
            if ($apps) {
                $this->inside('sla', static function (): void {
                    foreach (app(PluginRegistry::class)->all() as $plugin) {
                        app(PluginStates::class)->set($plugin->name, true, 'test');
                    }
                });
            }

            foreach ($routes as $route) {
                $method = $route->methods()[0];
                $response = $this->send($method, $this->fill($route));
                $what = RouteAudit::key($route).($apps ? '（App を使う店）' : '（App を使わない店）').' '.$response->status();

                $this->assertLessThan(500, $response->status(), $what);
                $this->assertGuarded($response, $what);
            }
        }
    }

    public function test_ordinary_pages_keep_the_ordinary_policy(): void
    {
        $this->inside('sla', static fn () => app(PluginStates::class)->set('contact', true, 'test'));

        foreach (['/', '/products', '/cart', '/orders', '/p/contact'] as $path) {
            $response = $this->send('GET', $path);

            $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'), $path);
            $this->assertNotSame(SecretLink::NO_INDEX, $response->headers->get('X-Robots-Tag'), $path);
        }

        // 見つからない道筋（どの経路にも当たらない）も、ふつうの扱い
        $this->assertSame('strict-origin-when-cross-origin', $this->send('GET', '/_nothing/'.Str::random(43))->headers->get('Referrer-Policy'));
    }

    public function test_error_and_redirect_answers_on_a_declared_route_carry_the_guards(): void
    {
        Route::middleware(['storefront', 'public', 'secret-link'])->group(static function (): void {
            Route::get('/_probe/forbidden/{token}', static fn () => abort(403));
            Route::get('/_probe/missing/{token}', static fn () => abort(404));
            Route::get('/_probe/expired/{token}', static fn () => throw new TokenMismatchException);
            Route::get('/_probe/away/{token}', static fn () => redirect('/'));
        });
        // 回数の上限で断るのは、宣言より外側の throttle（受け口まで来ない応答）
        Route::middleware(['storefront', 'public', 'throttle:1,1', 'secret-link'])->get('/_probe/limited/{token}', static fn () => 'ok');

        $token = Str::random(43);

        foreach (['forbidden' => 403, 'missing' => 404, 'expired' => 419, 'away' => 302] as $path => $status) {
            $response = $this->send('GET', "/_probe/{$path}/{$token}");
            $this->assertSame($status, $response->status(), $path);
            $this->assertGuarded($response, $path);
        }

        $this->assertGuarded($this->send('GET', "/_probe/limited/{$token}")->assertOk(), 'limited 200');
        $limited = $this->send('GET', "/_probe/limited/{$token}");
        $this->assertSame(429, $limited->status());
        $this->assertGuarded($limited, 'limited 429');
    }

    public function test_the_signed_form_lets_only_a_good_signature_of_this_shop_through(): void
    {
        $this->tenant('slb');
        Route::middleware(['storefront', 'public', SecretLink::SIGNED])->get('/_probe/signed/{id}', static fn (string $id) => "ok {$id}")->name('storefront.probe.signed');
        app(Router::class)->getRoutes()->refreshNameLookups();

        $path = $this->inside('sla', static fn (): string => SecretLinks::signed('storefront.probe.signed', ['id' => 'a1'], now()->addHour()));
        $this->assertStringContainsString('shop=sla', $path);

        $this->assertGuarded($this->send('GET', $path)->assertOk()->assertSee('ok a1'), '正しい署名');

        foreach ([
            '署名なし' => '/_probe/signed/a1',
            '書き換え' => str_replace('/a1?', '/a2?', $path),
            '店の書き換え' => str_replace('shop=sla', 'shop=slb', $path),
        ] as $what => $tampered) {
            $response = $this->send('GET', $tampered);
            $this->assertSame(404, $response->status(), $what);
            $this->assertGuarded($response, $what);
        }

        // ほかの店（slb）で、sla で作った署名を使う
        $this->assertSame(404, $this->send('GET', $path, 'slb')->status());

        // 店の ID の無い署名は、前に送ったリンクを受ける経路のほかは通さない
        $legacy = URL::temporarySignedRoute('storefront.probe.signed', now()->addHour(), ['id' => 'a1'], absolute: false);
        $this->assertSame(404, $this->send('GET', $legacy)->status());

        // 期限切れ
        $this->travel(61)->minutes();
        $this->assertSame(404, $this->send('GET', $path)->status());
    }

    public function test_links_signed_before_the_shop_id_was_added_are_accepted_only_on_the_old_routes_and_only_for_a_while(): void
    {
        $route = app(Router::class)->getRoutes()->getByName('storefront.orders.show');
        $this->assertInstanceOf(LaravelRoute::class, $route);
        $valid = fn (string $path): bool => $this->inside('sla', static function () use ($path, $route): bool {
            $request = Request::create($path);
            $request->setRouteResolver(static fn () => $route);

            return SecretLinks::valid($request);
        });

        // 前に送った形（店の ID なし・期限 30 日）
        $this->travelTo('2026-11-20 12:00:00');
        $legacy = URL::temporarySignedRoute('storefront.orders.show', now()->addDays(30), ['orderId' => strtoupper((string) Str::ulid())], absolute: false);
        $this->assertTrue($valid($legacy));

        // 同じ形でも、前のリンクを受ける経路でなければ通さない
        $review = app(Router::class)->getRoutes()->getByName('storefront.plugin.reviews.order');
        $this->assertInstanceOf(LaravelRoute::class, $review);
        $this->assertFalse($this->inside('sla', static function () use ($review): bool {
            $request = Request::create(URL::temporarySignedRoute('storefront.plugin.reviews.order', now()->addDay(), ['orderId' => strtoupper((string) Str::ulid())], absolute: false));
            $request->setRouteResolver(static fn () => $review);

            return SecretLinks::valid($request);
        }));

        // 前に送ったリンクが切れた後（期限の中のリンクでも）は通さない
        $this->travelTo('2026-12-08 00:00:01');
        $this->assertFalse($valid($legacy));
    }

    public function test_signing_needs_a_declared_route_a_short_expiry_and_a_shop(): void
    {
        $this->assertRefused(static fn () => SecretLinks::signed('storefront.products', [], now()->addHour()), \LogicException::class, '宣言の無い経路');
        $this->assertRefused(static fn () => SecretLinks::path('storefront.home', []), \LogicException::class, '宣言の無い経路（自分で署名する形）');
        $this->assertRefused(static fn () => SecretLinks::signed('storefront.nothing', [], now()->addHour()), \LogicException::class, '無い経路');
        $this->assertRefused(static fn () => SecretLinks::signed('storefront.orders.show', ['orderId' => 'x'], now()->addDays(SecretLinks::MAX_DAYS + 1)), \InvalidArgumentException::class, '長すぎる期限');
        $this->assertRefused(static fn () => SecretLinks::signed('storefront.orders.show', ['orderId' => 'x'], now()->subMinute()), \InvalidArgumentException::class, '過ぎた期限');

        // 上限ちょうどは作れる
        $this->assertStringContainsString('signature=', $this->inside('sla', static fn (): string => SecretLinks::signed('storefront.orders.show', ['orderId' => 'x'], now()->addDays(SecretLinks::MAX_DAYS))));

        $this->expectException(\LogicException::class);
        SecretLinks::signed('storefront.orders.show', ['orderId' => 'x'], now()->addHour());
    }

    public function test_an_unknown_way_of_declaring_is_refused(): void
    {
        Route::middleware(['storefront', 'public', 'secret-link:maybe'])->get('/_probe/odd/{token}', static fn () => 'x');

        // 書き方を間違えた宣言は通さない（500 にしても中身は出さず、守りの 3 つは掛かる）
        $response = $this->send('GET', '/_probe/odd/'.Str::random(43));
        $this->assertSame(500, $response->status());
        $this->assertGuarded($response, '書き方の違う宣言');
    }

    /**
     * 店の中で作ろうとして、決まった例外で断られる
     *
     * @param  \Closure(): string  $work
     * @param  class-string<\Throwable>  $exception
     */
    private function assertRefused(\Closure $work, string $exception, string $what): void
    {
        try {
            $this->inside('sla', $work);
            $this->fail("{$what}: 作れてしまった");
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->assertInstanceOf($exception, $e, $what);
        }
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertGuarded(TestResponse $response, string $what): void
    {
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'), "{$what}: 参照元");
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), "{$what}: 残さない");
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'), "{$what}: 共有の保存先に置かない");
        $this->assertSame(SecretLink::NO_INDEX, $response->headers->get('X-Robots-Tag'), "{$what}: 検索に載せない");
    }

    /**
     * 秘密の道筋の宣言を持つショップの経路（全部）
     *
     * @return list<LaravelRoute>
     */
    private function declaredRoutes(): array
    {
        $router = app(Router::class);

        return array_values(array_filter($router->getRoutes()->getRoutes(), static fn (LaravelRoute $route): bool => SecretLink::declaredOn($route)
            && in_array(InitializeTenancyByDomain::class, $router->gatherRouteMiddleware($route), true)));
    }

    /**
     * 経路の引数を、経路の形に合うでたらめな値で埋めた道筋
     */
    private function fill(LaravelRoute $route): string
    {
        return '/'.ltrim((string) preg_replace_callback('/\{(\w+)\??\}/', static function (array $m) use ($route): string {
            $pattern = $route->wheres[$m[1]] ?? null;
            $candidates = [strtoupper((string) Str::ulid()), strtolower((string) Str::ulid()), rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
                bin2hex(random_bytes(20)), 'google', 'probe'];

            foreach ($candidates as $candidate) {
                if ($pattern === null || preg_match('#\A(?:'.$pattern.')\z#', $candidate) === 1) {
                    return $pattern === null && str_contains(strtolower($m[1]), 'token') ? $candidates[2] : $candidate;
                }
            }

            return 'probe';
        }, $route->uri()), '/');
    }

    /**
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, string $shop = 'sla'): TestResponse
    {
        $response = $method === 'GET' ? $this->get("http://{$shop}.localhost{$path}") : $this->call($method, "http://{$shop}.localhost{$path}");
        tenancy()->end();

        return $response;
    }
}
