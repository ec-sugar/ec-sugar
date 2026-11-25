<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Party\Models\CustomerAddress;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Access\PageKey;
use App\Storefront\Http\Middleware\NavigationOnly;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use App\Storefront\View\StorefrontView;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AppContract;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 個人情報の画面は、ブラウザが画面を開く時だけ通す（ADR 0088）。
 * ショップのふつうの画面で動く App のタグ（同じオリジンの JavaScript）が、お客さんの Cookie を持ったまま fetch で
 * マイページ・届け先・注文の画面を読む・送るのを、ブラウザの付ける Sec-Fetch-Mode・Sec-Fetch-Dest で断る。
 * 本物のブラウザの fetch・window.open で止まることは tests/e2e/member-login.spec.js で確かめる
 */
final class NavigationOnlyTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** ブラウザが画面を開く（リンク・アドレス欄・フォームの送信） */
    private const array NAVIGATE = ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Site' => 'same-origin'];

    /**
     * 同じオリジンの JavaScript が読む形（fetch の既定・no-cors・same-origin、枠・オブジェクト・宛先の無い navigate）
     *
     * @var array<string, array<string, string>>
     */
    private const array SCRIPTED = [
        'fetch（cors）' => ['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Site' => 'same-origin'],
        'fetch（no-cors）' => ['Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Site' => 'same-origin'],
        'fetch（same-origin）' => ['Sec-Fetch-Mode' => 'same-origin', 'Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Site' => 'same-origin'],
        '枠（iframe）' => ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'iframe', 'Sec-Fetch-Site' => 'same-origin'],
        'object' => ['Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'object', 'Sec-Fetch-Site' => 'same-origin'],
        '宛先の無い navigate' => ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Site' => 'same-origin'],
        '空の Mode' => ['Sec-Fetch-Mode' => '', 'Sec-Fetch-Dest' => 'document'],
    ];

    private ?string $member = null;

    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->tenant('noa');
        $this->tenant('nob');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_member_pages_open_as_pages_but_give_nothing_to_scripts(): void
    {
        $this->signIn('noa', 'hanako@example.com');
        $this->send('noa', 'POST', '/account/addresses', ['address' => ['name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => '']], self::NAVIGATE)
            ->assertRedirect('http://noa.localhost/account/addresses');
        $address = $this->inside('noa', static fn (): string => (string) CustomerAddress::query()->value('id'));

        $pages = ['/account' => 'hanako@example.com', '/account/addresses' => '神宮前 1-2-3', "/account/addresses/{$address}" => '神宮前 1-2-3'];

        foreach ($pages as $path => $secret) {
            // ブラウザが画面を開く: 今までどおり中身を出す。どこにも残さず、開いた窓をふつうの画面から切り離す
            $page = $this->send('noa', 'GET', $path, [], self::NAVIGATE)->assertOk()->assertSee($secret);
            $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'), $path);
            $this->assertSame('noopener-allow-popups', $page->headers->get('Cross-Origin-Opener-Policy'), $path);
            $this->assertContains('Sec-Fetch-Mode', $page->getVary(), $path);

            // Sec-Fetch を送らない古いブラウザ: 画面が開けなくならないよう通す（ADR 0088）
            $this->send('noa', 'GET', $path)->assertOk()->assertSee($secret);

            // 同じオリジンの JavaScript の読み方は、どれも中身の無い 403
            foreach (self::SCRIPTED as $how => $headers) {
                $refused = $this->send('noa', 'GET', $path, [], $headers);
                $this->assertSame(Response::HTTP_FORBIDDEN, $refused->getStatusCode(), "{$path} {$how}");
                $this->assertSame('', (string) $refused->getContent(), "{$path} {$how}");
                $this->assertStringContainsString('no-store', (string) $refused->headers->get('Cache-Control'), "{$path} {$how}");
            }
        }

        // ふつうの画面（タグを差し込む画面）は今までどおり fetch でも読める（守りは印の経路だけ）
        $this->send('noa', 'GET', '/', [], self::SCRIPTED['fetch（cors）'])->assertOk();
        $this->assertSame('same-origin', $this->send('noa', 'GET', '/', [], self::NAVIGATE)->headers->get('Cross-Origin-Opener-Policy'));
    }

    public function test_a_script_cannot_change_member_data_but_the_page_form_can(): void
    {
        $this->signIn('noa', 'hanako@example.com');
        $address = ['name' => '攻撃 太郎', 'phone' => '09000000000', 'postal' => '1000001', 'prefecture' => '東京都', 'city' => '千代田区', 'line1' => '1-1', 'line2' => ''];

        // タグの JavaScript が届け先を足そうとしても断る（Laravel 13 は同じオリジンの要求に CSRF の合い言葉を求めないので、ここで止める）
        foreach (['fetch（cors）', 'fetch（same-origin）', '枠（iframe）'] as $how) {
            $this->send('noa', 'POST', '/account/addresses', ['address' => $address], self::SCRIPTED[$how])->assertForbidden();
        }
        $this->assertSame(0, $this->inside('noa', static fn (): int => CustomerAddress::query()->count()));

        // 画面のフォームから送ると足せる
        $this->send('noa', 'POST', '/account/addresses', ['address' => $address], self::NAVIGATE)->assertRedirect('http://noa.localhost/account/addresses');
        $this->assertSame(1, $this->inside('noa', static fn (): int => CustomerAddress::query()->count()));
    }

    public function test_logged_out_and_other_shops_behave_as_before(): void
    {
        // ログインしていない: 画面を開くとログインの画面へ（今まで通り）。fetch でも中身は無い
        $this->send('noa', 'GET', '/account', [], self::NAVIGATE)->assertRedirect('http://noa.localhost/account/login');
        $this->assertStringNotContainsString('hanako', (string) $this->send('noa', 'GET', '/account', [], self::SCRIPTED['fetch（cors）'])->getContent());

        // ほかの店の会員の印では、画面を開いてもログインの画面へ（今まで通り。店の境界は守りの前に決まる）
        $this->signIn('noa', 'hanako@example.com');
        $this->send('nob', 'GET', '/account', [], self::NAVIGATE)->assertRedirect('http://nob.localhost/account/login');
        $this->send('noa', 'GET', '/account', [], self::NAVIGATE)->assertOk()->assertSee('hanako@example.com');
    }

    public function test_the_page_key_lets_only_the_marked_page_read_its_own_parts(): void
    {
        Route::middleware(['storefront', 'public', 'navigation-only'])->get('/_probe/page', static fn (Request $request): string => 'key='.PageKey::forPage($request));
        Route::middleware(['storefront', 'public', 'navigation-only:page-key'])->post('/_probe/part', static fn (): string => 'the-part');
        Route::middleware(['storefront', 'public'])->get('/_probe/plain', static fn (Request $request): string => 'key='.PageKey::forPage($request));

        $key = substr((string) $this->send('noa', 'GET', '/_probe/page', [], self::NAVIGATE)->assertOk()->getContent(), 4);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $key);
        // 印の無い画面（タグを差し込む画面）には鍵を書かない
        $this->send('noa', 'GET', '/_probe/plain', [], self::NAVIGATE)->assertOk()->assertContent('key=');

        $fetch = self::SCRIPTED['fetch（cors）'];
        $this->send('noa', 'POST', '/_probe/part', [], $fetch)->assertForbidden();
        // 1 文字だけ違う鍵（頭の文字を必ず別の文字に替える。'x' を決め打ちすると、本物が x で始まった時に同じ鍵になって通ってしまう）
        $this->send('noa', 'POST', '/_probe/part', [], $fetch + [PageKey::HEADER => ($key[0] === 'x' ? 'y' : 'x').substr($key, 1)])->assertForbidden();
        $this->send('noa', 'POST', '/_probe/part', [], $fetch + [PageKey::HEADER => str_repeat('a', 200)])->assertForbidden();
        $this->send('noa', 'POST', '/_probe/part', [], $fetch + [PageKey::HEADER => $key])->assertOk()->assertContent('the-part');
        // ほかのサイトからは、鍵があっても通さない
        $this->send('noa', 'POST', '/_probe/part', [], ['Sec-Fetch-Site' => 'cross-site'] + $fetch + [PageKey::HEADER => $key])->assertForbidden();
        // ほかの店では、同じセッションでも鍵が合わない
        $this->send('nob', 'POST', '/_probe/part', [], $fetch + [PageKey::HEADER => $key])->assertForbidden();
        // 別のセッション（ほかのブラウザ）では合わない。テストの中ではセッションの入れ物が使い回されるので、中身も空にして新しい合い言葉にする
        $this->session = null;
        app('session')->driver()->flush();
        $this->send('noa', 'POST', '/_probe/part', [], $fetch + [PageKey::HEADER => $key])->assertForbidden();
    }

    public function test_a_marked_page_never_carries_app_tags(): void
    {
        // 核の関所のスクリプトは Vite::asset で道筋を出す。テストではビルドが無いので、入口の名前のままの道筋を返す偽物にする（AppTagsTest と同じ）
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString('');
            }

            public function asset($asset, $buildDirectory = null): string
            {
                return '/build/'.$asset;
            }
        });
        $this->inside('noa', function (): void {
            app(PluginStates::class)->set('tag-sample', true);
            app(PluginSettings::class)->update('tag-sample', ['tag_id' => 'TS-ABC123', 'api_secret' => 'very-secret-key-0001']);
        });
        Route::middleware(['storefront', 'public', 'navigation-only'])->get('/_probe/home-marked', static fn () => app(StorefrontView::class)->render('home'));
        Route::middleware(['storefront', 'public'])->get('/_probe/home-plain', static fn () => app(StorefrontView::class)->render('home'));

        // 印の無いふつうの画面には差し込む（帯を出さない店の既定）。印の経路では、ふつうの画面の名前で描いても差し込まない
        $this->send('noa', 'GET', '/_probe/home-plain', [], self::NAVIGATE)->assertOk()->assertSee('/apps/tag-sample/tag-sample.js', false);
        $marked = $this->send('noa', 'GET', '/_probe/home-marked', [], self::NAVIGATE)->assertOk();
        $this->assertStringNotContainsString('/apps/tag-sample/', (string) $marked->getContent());
        $this->assertSame(config('storefront.csp'), $marked->headers->get('Content-Security-Policy'));
    }

    public function test_the_passkey_page_reads_its_options_with_the_page_key(): void
    {
        $this->signIn('noa', 'hanako@example.com');
        $this->send('noa', 'POST', '/account/confirm/send', ['next' => 'passkeys'], self::NAVIGATE);
        $this->send('noa', 'POST', '/account/confirm', ['code' => Mail::sent(LoginCodeMail::class)->last()->code, 'next' => 'passkeys'], self::NAVIGATE)->assertRedirect();

        $html = (string) $this->send('noa', 'GET', '/account/passkeys', [], self::NAVIGATE)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/data-sugar-page-key="([A-Za-z0-9_-]{43})"/', $html, $found));
        $fetch = self::SCRIPTED['fetch（cors）'];

        // タグの JavaScript（鍵を知らない）にはオプション（会員の名前を含む）を渡さない。passkeys.js（鍵を添える）には渡す
        $this->send('noa', 'POST', '/account/passkeys/options', [], $fetch)->assertForbidden();
        $this->send('noa', 'POST', '/account/passkeys/options', [], $fetch + [PageKey::HEADER => $found[1]])->assertOk()->assertJsonPath('options.user.name', 'hanako@example.com');

        // ログインの画面（印の無い画面）のパスキーのフォームには鍵を書かない
        $this->member = null;
        $this->assertStringContainsString('data-sugar-page-key=""', (string) $this->send('noa', 'GET', '/account/login', [], self::NAVIGATE)->assertOk()->getContent());
    }

    /**
     * 印の付いた経路の全部（核と App。App は使うにした店で）が、同じオリジンの fetch に中身を出さない。
     * 画面を開く要求は、見出しの無い要求（今までの動き）と同じ答え
     */
    public function test_every_marked_route_refuses_scripts(): void
    {
        $this->inside('noa', function (): void {
            foreach (app(PluginRegistry::class)->all() as $plugin) {
                if ($plugin->storefrontRoutes !== null) {
                    app(PluginStates::class)->set($plugin->name, true);
                }
            }
        });
        $this->signIn('noa', 'hanako@example.com');
        $router = app(Router::class);
        $count = 0;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $marked = array_filter($router->gatherRouteMiddleware($route), static fn (mixed $m): bool => is_string($m) && str_starts_with($m, NavigationOnly::class));

            if ($marked === []) {
                continue;
            }

            $count++;
            $method = $route->methods()[0];
            $path = '/'.$this->fill($route);

            foreach (['fetch（cors）', 'fetch（no-cors）', '枠（iframe）'] as $how) {
                $refused = $this->send('noa', $method, $path, [], self::SCRIPTED[$how]);
                $this->assertSame(Response::HTTP_FORBIDDEN, $refused->getStatusCode(), "{$method} {$path} {$how}");
                $this->assertSame('', (string) $refused->getContent(), "{$method} {$path} {$how}");
            }

            if ($method === 'GET') {
                $before = $this->send('noa', 'GET', $path)->getStatusCode();
                $this->assertSame($before, $this->send('noa', 'GET', $path, [], self::NAVIGATE)->getStatusCode(), "GET {$path} を画面として開く");
            }
        }

        // 公開用の 1.0 の木（定期便・予約・チケット・抽選販売の App が無い。ADR 0098）は、その App の経路の分だけ少ない
        $this->assertGreaterThan(AppContract::absentUnpublished() === [] ? 100 : 60, $count);
    }

    /**
     * 経路の引数を、形に合う値で埋める（ULID の形・合い言葉の 43 文字）
     */
    private function fill(LaravelRoute $route): string
    {
        return (string) preg_replace_callback('/\{(\w+)\??\}/', static fn (array $m): string => match ($m[1]) {
            'token' => str_repeat('A', 43),
            default => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        }, $route->uri());
    }

    private function signIn(string $shop, string $email): void
    {
        $this->send($shop, 'POST', '/account/login', ['email' => $email], self::NAVIGATE)->assertRedirect("http://{$shop}.localhost/account/login/code");
        $this->send($shop, 'POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code], self::NAVIGATE)->assertRedirect("http://{$shop}.localhost/account");
        $this->assertNotNull($this->member);
    }

    /**
     * 会員の印とセッションの Cookie を持ち越して送る（本物のブラウザと同じ）。見出しは要求ごとに付け替える
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function send(string $shop, string $method, string $path, array $data = [], array $headers = []): TestResponse
    {
        $this->defaultCookies = [];
        $this->flushHeaders();

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        if ($this->session !== null) {
            $this->withCookie((string) config('session.cookie'), $this->session);
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $response = $this->withHeaders($headers)->call($method, $url, $data, $this->prepareCookiesForRequest(), [], $this->transformHeadersToServerVars($this->defaultHeaders));
        tenancy()->end();

        foreach ([MemberCookie::NAME => 'member', (string) config('session.cookie') => 'session'] as $name => $property) {
            $cookie = $response->getCookie($name);
            if ($cookie !== null) {
                $this->{$property} = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
            }
        }

        return $response;
    }
}
