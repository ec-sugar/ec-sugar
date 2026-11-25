<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Models\Tenant;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ショップのヘッダーの会員の入口（テーマ API 2 の $account）。4 つのテーマで、ログイン前は「ログイン」、
 * ログイン後は「マイページ」。会員のメールはふつうの画面の HTML に出ない。別の店の印では会員にならない
 */
final class MemberEntryTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const array THEMES = ['default', 'kinari', 'fashion', 'botanica'];

    private const string EMAIL = 'hanako.entry@example.com';

    private ?string $member = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->tenant('mea');
        $this->tenant('meb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_header_shows_login_before_and_my_page_after_logging_in_on_every_theme(): void
    {
        foreach (self::THEMES as $theme) {
            $this->useTheme('mea', $theme);
            $link = $this->accountLink($this->send('mea', '/'), $theme);
            $this->assertStringContainsString('href="/account/login"', $link, $theme);
            $this->assertStringContainsString('ログイン', $link, $theme);
            $this->assertStringNotContainsString('マイページ', $link, $theme);
        }

        $this->logIn('mea');

        foreach (self::THEMES as $theme) {
            $this->useTheme('mea', $theme);

            foreach (['/', '/products', '/cart'] as $path) {
                $html = (string) $this->send('mea', $path)->assertOk()->getContent();
                $link = $this->accountLink($html, "{$theme} {$path}");
                $this->assertStringContainsString('href="/account"', $link, "{$theme} {$path}");
                $this->assertStringContainsString('マイページ', $link, "{$theme} {$path}");
                // ふつうの画面に会員のメールを出さない（マイページの本文だけが出す）
                $this->assertStringNotContainsString(self::EMAIL, $html, "{$theme} {$path}");
                $this->assertStringNotContainsString('hanako.entry', $html, "{$theme} {$path}");
            }

            // マイページでは、入口に今いる印
            $this->assertStringContainsString('aria-current="page"', $this->accountLink($this->send('mea', '/account'), "{$theme} /account"), $theme);
        }
    }

    public function test_a_member_of_another_shop_sees_login(): void
    {
        $this->logIn('mea');

        // 同じ印を別の店のホストへ持ち込んでも、その店では会員でない（印は店の DB にある）
        foreach (self::THEMES as $theme) {
            $this->useTheme('meb', $theme);
            $link = $this->accountLink($this->send('meb', '/'), $theme);
            $this->assertStringContainsString('href="/account/login"', $link, $theme);
            $this->assertStringNotContainsString('マイページ', $link, $theme);
        }
    }

    private function useTheme(string $shop, string $theme): void
    {
        Tenant::query()->findOrFail($shop)->forceFill(['theme' => $theme])->save();
    }

    /**
     * 画面の HTML から、会員の入口のリンク（<a …>…</a>）を 1 つだけ取り出す
     *
     * @param  string|TestResponse<Response>  $html
     */
    private function accountLink(string|TestResponse $html, string $where): string
    {
        $html = is_string($html) ? $html : (string) $html->assertOk()->getContent();
        $count = preg_match_all('#<a\b[^>]*data-test="account-link"[^>]*>.*?</a>#s', $html, $links);
        $this->assertSame(1, $count, "{$where}: 会員の入口が 1 つでない");

        return $links[0][0];
    }

    private function logIn(string $shop): void
    {
        $this->send($shop, '/account/login', ['email' => self::EMAIL])->assertRedirect("http://{$shop}.localhost/account/login/code");
        $code = Mail::sent(LoginCodeMail::class)->last()?->code;
        $this->assertIsString($code);
        $this->send($shop, '/account/login/code', ['code' => $code])->assertRedirect("http://{$shop}.localhost/account");
        $this->assertNotNull($this->member, '印の Cookie が無い');
    }

    /**
     * 会員の印の Cookie を持ち越して送る（MemberLoginTest と同じ形。$data があれば POST）
     *
     * @param  array<string, mixed>|null  $data
     * @return TestResponse<Response>
     */
    private function send(string $shop, string $path, ?array $data = null): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        // 本番（php-fpm）と同じく、要求ごとにログインの仕組みを作り直す
        $this->app['auth']->forgetGuards();
        $url = "http://{$shop}.localhost{$path}";
        $response = $data === null ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }
}
