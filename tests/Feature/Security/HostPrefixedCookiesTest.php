<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\HostPrefixedCookies;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 管理画面の Cookie も、https では __Host- の名前でだけやり取りする（見直しの中 1、ADR 0090 の 6.）。
 * 管理画面のホスト（admin.ec-sugar.jp）も店と同じドメインの下にあるので、隣のホストから頭の無い名前で置かれたセッションの Cookie を読まない。
 * 管理画面のセッションの Cookie は前の名前から移さない（運営は移った時に一度ログインし直す）。他サイトからの通信に Cookie を返さない守り
 * （ConfigureAdminSession）は __Host- の名前でも効く。手元の http では前の名前のまま（make up と e2e を壊さない）。
 * 管理画面の JavaScript は https で __Host-XSRF-TOKEN だけを読む
 */
final class HostPrefixedCookiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cookies_are_host_prefixed_on_https_and_plain_on_http(): void
    {
        $https = $this->admin('https', '/auth/csrf');
        $names = [];
        foreach ($https->headers->getCookies() as $cookie) {
            $names[] = $cookie->getName();
            $this->assertStringStartsWith('__Host-', $cookie->getName());
            $this->assertTrue($cookie->isSecure());
            $this->assertSame('/', $cookie->getPath());
            $this->assertNull($cookie->getDomain());
        }
        $this->assertContains('__Host-'.config('admin.session_cookie'), $names);
        $this->assertContains('__Host-XSRF-TOKEN', $names);
        $this->assertSame('strict', $https->getCookie('__Host-'.config('admin.session_cookie'), false)?->getSameSite());

        $http = $this->admin('http', '/auth/csrf');
        $this->assertNotNull($http->getCookie((string) config('admin.session_cookie'), false));
        $this->assertNotNull($http->getCookie('XSRF-TOKEN', false));
    }

    public function test_a_plain_admin_session_cookie_tossed_from_a_neighbour_is_not_used(): void
    {
        $name = (string) config('admin.session_cookie');
        $own = (string) $this->admin('https', '/auth/csrf')->getCookie(HostPrefixedCookies::PREFIX.$name, false)?->getValue();
        $this->assertNotSame('', $own);

        $honest = $this->admin('https', '/auth/csrf', [HostPrefixedCookies::PREFIX.$name => $own]);
        $this->assertSame($this->sessionId($own), $this->sessionId((string) $honest->getCookie(HostPrefixedCookies::PREFIX.$name, false)?->getValue()));

        $tossed = $this->admin('https', '/auth/csrf', [$name => $own]);
        $this->assertNotSame($this->sessionId($own), $this->sessionId((string) $tossed->getCookie(HostPrefixedCookies::PREFIX.$name, false)?->getValue()));
        // 古い名前の Cookie（このホストだけのもの）は消す
        $this->assertTrue((bool) $tossed->getCookie($name, false)?->isCleared());
    }

    public function test_requests_from_other_sites_get_no_admin_cookies_on_https_either(): void
    {
        foreach (['cross-site', 'same-site'] as $site) {
            $this->assertSame([], $this->admin('https', '/auth/csrf', headers: ['Sec-Fetch-Site' => $site])->headers->getCookies(), $site);
        }
    }

    public function test_the_admin_script_reads_only_the_prefixed_csrf_cookie_on_https(): void
    {
        $script = (string) file_get_contents(base_path('resources/admin/api.js'));

        $this->assertMatchesRegularExpression("/protocol === 'https:' \\? '__Host-XSRF-TOKEN=' : 'XSRF-TOKEN='/", $script);
    }

    /**
     * @param  array<string, string>  $cookies  ブラウザが持っている形のまま（暗号にした値）
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function admin(string $scheme, string $path, array $cookies = [], array $headers = []): TestResponse
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = $cookies;
        $response = $this->withHeaders($headers)->get("{$scheme}://".config('admin.domain').$path);
        $this->unencryptedCookies = [];
        $this->defaultHeaders = [];

        return $response;
    }

    private function sessionId(string $raw): string
    {
        return CookieValuePrefix::remove((string) app('encrypter')->decrypt($raw, false));
    }
}
