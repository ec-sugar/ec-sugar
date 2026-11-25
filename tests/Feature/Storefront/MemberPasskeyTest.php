<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerPasskey;
use App\Core\Party\Services\CustomerPasskeys;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Support\WebAuthn;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 会員のパスキー（ADR 0044 の 3・ADR 0023）。任意で足せて、ログインと本人の再確認に使える。
 * 足す・消すは再確認の後だけ。結びつく先はショップのホストとオリジン。ほかの店・ほかのオリジン・本人の確かめの無いもの・使い回しは通らない。
 */
final class MemberPasskeyTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private ?string $member = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->tenant('mpa');
        $this->tenant('mpb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_member_adds_a_passkey_after_confirming_and_logs_in_with_it(): void
    {
        $this->signIn('mpa', 'hanako@example.com');

        // 再確認の前は足せない（画面もオプションも）
        $this->send('mpa', 'GET', '/account/passkeys')->assertRedirect('http://mpa.localhost/account/confirm?next=passkeys');
        $this->send('mpa', 'POST', '/account/passkeys/options')->assertForbidden();
        $this->confirmWithCode('mpa', 'passkeys');
        $this->send('mpa', 'GET', '/account/passkeys')->assertOk()->assertSee('data-test="passkeys-empty"', false);

        $authenticator = $this->addPasskey('mpa', 'iPhone');
        $html = (string) $this->send('mpa', 'GET', '/account/passkeys')->assertOk()->getContent();
        $this->assertStringContainsString('iPhone', $html);

        $this->inside('mpa', function (): void {
            $passkey = CustomerPasskey::query()->sole();
            // 名前は暗号化。イベントには ID だけ
            $this->assertStringStartsWith(TenantCipher::PREFIX, (string) DB::table('customer_passkeys')->where('id', $passkey->id)->value('name'));
            $this->assertSame(['passkey_id' => $passkey->id], (array) DomainEvent::query()->where('type', 'customer_passkey.added')->sole()->payload);
        });

        // ログアウトして、メールを入れずにパスキーで入る
        $this->send('mpa', 'POST', '/account/logout');
        $options = $this->ceremony('mpa', '/account/login/passkey/options');
        $this->assertSame([], $options['allowCredentials'] ?? []);
        $this->send('mpa', 'POST', '/account/login/passkey', ['credential' => json_encode($authenticator->assert($options, $this->handle('mpa')))])->assertRedirect('http://mpa.localhost/account');
        $this->assertNotNull($this->member);
        $this->send('mpa', 'GET', '/account')->assertOk()->assertSee('hanako@example.com');
        $this->inside('mpa', fn () => $this->assertNotNull(CustomerPasskey::query()->sole()->last_used_at));
    }

    public function test_wrong_origin_unverified_reused_and_foreign_passkeys_are_refused(): void
    {
        $this->signIn('mpa', 'hanako@example.com');
        $this->confirmWithCode('mpa', 'passkeys');
        $authenticator = $this->addPasskey('mpa', 'iPhone');
        $this->send('mpa', 'POST', '/account/logout');
        $handle = $this->handle('mpa');

        // ほかのオリジン（偽のサイト）・本人の確かめ（指紋など）の無い答え
        $options = $this->ceremony('mpa', '/account/login/passkey/options');
        $this->refused('mpa', $authenticator->assert($options, $handle, origin: 'https://mpa.evil.example'));
        $options = $this->ceremony('mpa', '/account/login/passkey/options');
        $this->refused('mpa', $authenticator->assert($options, $handle, userVerified: false));

        // 使った答えの使い回し（チャレンジは 1 回分だけ）
        $options = $this->ceremony('mpa', '/account/login/passkey/options');
        $good = $authenticator->assert($options, $handle);
        $this->send('mpa', 'POST', '/account/login/passkey', ['credential' => json_encode($good)])->assertRedirect('http://mpa.localhost/account');
        $this->send('mpa', 'POST', '/account/logout');
        $this->refused('mpa', $good);

        // 壊れた・大きすぎる答え、オプションをもらっていない答え
        $this->ceremony('mpa', '/account/login/passkey/options');
        $this->refused('mpa', ['id' => 'x']);
        $this->ceremony('mpa', '/account/login/passkey/options');
        $this->send('mpa', 'POST', '/account/login/passkey', ['credential' => str_repeat('a', 30_000)])->assertRedirect('http://mpa.localhost/account/login');

        // ほかの店では、同じパスキーで入れない（その店の DB に無い・結びつく先のホストが違う）
        $options = $this->ceremony('mpb', '/account/login/passkey/options');
        $this->send('mpb', 'POST', '/account/login/passkey', ['credential' => json_encode($authenticator->assert($options, $handle))])->assertRedirect('http://mpb.localhost/account/login');
        $this->assertNull($this->member);
    }

    public function test_a_passkey_confirms_only_its_own_member_and_leaving_removes_it(): void
    {
        // 太郎のパスキー
        $this->signIn('mpa', 'taro@example.com');
        $this->confirmWithCode('mpa', 'passkeys');
        $taros = $this->addPasskey('mpa', '太郎の端末');
        $taroHandle = $this->handle('mpa', 'taro@example.com');
        $this->send('mpa', 'POST', '/account/logout');

        // 花子: パスキーを足して、再確認をパスキーで（コードを送らない）
        $this->signIn('mpa', 'hanako@example.com');
        $this->confirmWithCode('mpa', 'passkeys');
        $hanakos = $this->addPasskey('mpa', '花子の端末');
        $this->travel(11)->minutes();
        $this->send('mpa', 'GET', '/account/confirm?next=leave')->assertOk()->assertSee('data-test="passkey-confirm"', false);
        $mails = count(Mail::sent(LoginCodeMail::class));

        // 太郎のパスキーでは、花子の再確認にならない
        $options = $this->ceremony('mpa', '/account/confirm/passkey/options');
        $this->assertCount(1, $options['allowCredentials']);
        $this->send('mpa', 'POST', '/account/confirm/passkey', ['credential' => json_encode($taros->assert($options, $taroHandle)), 'next' => 'leave'])
            ->assertRedirect('http://mpa.localhost/account/confirm?next=leave');
        $this->send('mpa', 'GET', '/account/leave')->assertRedirect('http://mpa.localhost/account/confirm?next=leave');

        // 花子のパスキーなら再確認になり、行き先は決まった名前だけ
        $options = $this->ceremony('mpa', '/account/confirm/passkey/options');
        $this->send('mpa', 'POST', '/account/confirm/passkey', ['credential' => json_encode($hanakos->assert($options, $this->handle('mpa'))), 'next' => 'https://evil.example/'])
            ->assertRedirect('http://mpa.localhost/account/leave');
        $this->assertCount($mails, Mail::sent(LoginCodeMail::class));

        // 消せる。消したパスキーではもう入れない
        $id = $this->inside('mpa', fn (): string => (string) CustomerPasskey::query()->where('customer_account_id', CustomerAccount::query()->whereBlind('email', 'hanako@example.com')->value('id'))->value('id'));
        $taroId = $this->inside('mpa', fn (): string => (string) CustomerPasskey::query()->whereKeyNot($id)->value('id'));
        // ほかの会員のパスキーは消せない
        $this->send('mpa', 'POST', "/account/passkeys/{$taroId}/delete")->assertRedirect('http://mpa.localhost/account/passkeys');
        $this->inside('mpa', fn () => $this->assertSame(2, CustomerPasskey::query()->count()));
        $this->send('mpa', 'POST', "/account/passkeys/{$id}/delete")->assertRedirect('http://mpa.localhost/account/passkeys');
        $this->inside('mpa', fn () => $this->assertSame(1, CustomerPasskey::query()->count()));

        // 退会でパスキーも消える（太郎は退会して、花子は残す）
        $this->send('mpa', 'POST', '/account/logout');
        $this->signIn('mpa', 'taro@example.com', '198.51.100.50');
        $this->confirmWithCode('mpa', 'leave');
        $this->send('mpa', 'POST', '/account/leave')->assertRedirect('http://mpa.localhost');
        $this->inside('mpa', fn () => $this->assertSame(0, CustomerPasskey::query()->count()));
    }

    public function test_a_challenge_is_used_once_even_for_passkeys_without_a_counter(): void
    {
        // 同期のパスキーは数え上げを持たない（いつも 0）。数え上げに頼らず、チャレンジが 1 回分だけであることで使い回しを止める
        $this->signIn('mpa', 'hanako@example.com');
        $this->confirmWithCode('mpa', 'passkeys');
        $authenticator = $this->addPasskey('mpa', 'iPhone');
        $this->send('mpa', 'POST', '/account/logout');

        $options = $this->ceremony('mpa', '/account/login/passkey/options');
        $answer = $authenticator->assert($options, $this->handle('mpa'), counter: 0);
        $this->send('mpa', 'POST', '/account/login/passkey', ['credential' => json_encode($answer)])->assertRedirect('http://mpa.localhost/account');
        $this->member = null;
        $this->send('mpa', 'POST', '/account/login/passkey', ['credential' => json_encode($answer)])->assertRedirect('http://mpa.localhost/account/login');
        $this->assertNull($this->member);
    }

    public function test_the_core_refuses_another_members_passkey_even_without_a_list_of_candidates(): void
    {
        // 候補の一覧（allowCredentials）に頼らず、核が「その会員のパスキーか」を確かめる
        $this->signIn('mpa', 'taro@example.com');
        $this->confirmWithCode('mpa', 'passkeys');
        $taros = $this->addPasskey('mpa', '太郎の端末');
        $this->send('mpa', 'POST', '/account/logout');
        $this->signIn('mpa', 'hanako@example.com', '198.51.100.60');
        $handle = $this->handle('mpa', 'taro@example.com');

        $this->inside('mpa', function () use ($taros, $handle): void {
            $passkeys = app(CustomerPasskeys::class);
            $hanako = CustomerAccount::query()->whereBlind('email', 'hanako@example.com')->firstOrFail();
            $options = $passkeys->requestOptions(null, 'mpa.localhost');
            $json = WebAuthn::toJson($options);
            $answer = json_encode($taros->assert(WebAuthn::toBrowserArray($options), $handle), JSON_THROW_ON_ERROR);
            $this->assertNull($passkeys->verify($answer, $json, 'http://mpa.localhost', 'mpa.localhost', $hanako));
        });
    }

    private function addPasskey(string $shop, string $name): SoftwareAuthenticator
    {
        $authenticator = new SoftwareAuthenticator("{$shop}.localhost", "http://{$shop}.localhost");
        $options = $this->ceremony($shop, '/account/passkeys/options');
        $this->assertSame("{$shop}.localhost", $options['rp']['id']);
        $this->send($shop, 'POST', '/account/passkeys', ['credential' => json_encode($authenticator->register($options)), 'name' => $name])
            ->assertRedirect("http://{$shop}.localhost/account/passkeys");
        $this->assertStringContainsString('パスキーを追加しました', (string) $this->send($shop, 'GET', '/account/passkeys')->getContent());

        return $authenticator;
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    private function refused(string $shop, array $credential): void
    {
        $this->send($shop, 'POST', '/account/login/passkey', ['credential' => json_encode($credential)])->assertRedirect("http://{$shop}.localhost/account/login");
        $this->assertStringContainsString('パスキーを確認できませんでした', (string) $this->send($shop, 'GET', '/account/login')->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function ceremony(string $shop, string $path): array
    {
        $options = $this->send($shop, 'POST', $path)->assertOk()->json('options');
        $this->assertIsArray($options);

        return $options;
    }

    private function handle(string $shop, string $email = 'hanako@example.com'): string
    {
        return $this->inside($shop, static fn (): string => app(TenantCipher::class)->index('customer-passkey-handle|'.CustomerAccount::query()->whereBlind('email', $email)->value('id')));
    }

    private function signIn(string $shop, string $email, string $ip = '127.0.0.1'): void
    {
        $this->send($shop, 'POST', '/account/login', ['email' => $email], $ip)->assertRedirect("http://{$shop}.localhost/account/login/code");
        $this->send($shop, 'POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code], $ip)->assertRedirect("http://{$shop}.localhost/account");
    }

    private function confirmWithCode(string $shop, string $next): void
    {
        $this->send($shop, 'POST', '/account/confirm/send', ['next' => $next]);
        $this->send($shop, 'POST', '/account/confirm', ['code' => Mail::sent(LoginCodeMail::class)->last()->code, 'next' => $next])->assertRedirect();
    }

    /**
     * 会員の印を持ち越して送る。要求ごとにログインの仕組みと Cookie の控えを作り直す（本番の php-fpm と同じ）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $shop, string $method, string $path, array $data = [], string $ip = '127.0.0.1'): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $response = $method === 'GET'
            ? $this->withServerVariables(['REMOTE_ADDR' => $ip])->get($url)
            : $this->withServerVariables(['REMOTE_ADDR' => $ip])->post($url, $data, ['Accept' => str_ends_with($path, '/options') ? 'application/json' : 'text/html']);
        tenancy()->end();
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }
}
