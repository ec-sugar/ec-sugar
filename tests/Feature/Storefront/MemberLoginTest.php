<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerLoginCode;
use App\Core\Party\Models\CustomerSession;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Party\Services\CustomerLogin;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Members\MemberLogin;
use App\Storefront\Members\SendLoginCode;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 会員のログインとマイページ（ADR 0044 の 3）。メールの 6 桁のコードで入り、印は Cookie（店の DB にハッシュだけ）。
 * コードは 10 分・1 回だけ・5 回まで。メールの有る無しで答えを変えない。送る数と外れの数を止める。店をまたがない。
 * 履歴は注文の中身だけ（打ち間違えたメールの持ち主に、他人の届け先・名前・電話を見せない）。
 */
final class MemberLoginTest extends TestCase
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
        $this->shop('mla');
        $this->shop('mlb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_customer_logs_in_with_the_code_and_sees_only_the_contents_of_their_orders(): void
    {
        // ゲストの時の注文（同じメール、大文字違い）と、他人のメールの注文
        $mine = $this->order('mla', 'Hanako@Example.com');
        $this->order('mla', 'taro@example.com');

        // 印が無ければマイページはログインの画面へ
        $this->send('mla', 'GET', '/account')->assertRedirect('http://mla.localhost/account/login');

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('mla')->forceFill(['theme' => $theme])->save();
            $this->flushSession();
            $this->member = null;
            $this->send('mla', 'GET', '/account/login')->assertOk()->assertSee('data-test="account-login"', false);
            $code = $this->requestCode('mla', 'hanako@example.com');
            $this->send('mla', 'GET', '/account/login/code')->assertOk()->assertSee('hanako@example.com');
            $this->send('mla', 'POST', '/account/login/code', ['code' => $code])->assertRedirect('http://mla.localhost/account');
            $this->assertNotNull($this->member, "{$theme}: 印の Cookie が無い");

            $page = $this->send('mla', 'GET', '/account')->assertOk();
            // 会員の画面はブラウザにも残さない
            $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
            $html = (string) $page->getContent();
            // 同じメールのゲストの時の注文の中身は見える（日付・品・金額・状態）。他人のメールの注文は無い
            $this->assertSame(1, substr_count($html, 'data-test="account-order"'), $theme);
            foreach ([strtoupper($mine), '&lt;b&gt;ニット&lt;/b&gt;', '¥4,400', '入金待ち'] as $text) {
                $this->assertStringContainsString($text, $html, "{$theme}: {$text}");
            }
            // ゲストの時の名前・届け先・電話は出さない
            foreach (['山田', '渋谷', '09012345678'] as $pii) {
                $this->assertStringNotContainsString($pii, $html, "{$theme}: {$pii}");
            }
            // ログインの画面へ戻ると、マイページへ
            $this->send('mla', 'GET', '/account/login')->assertRedirect('http://mla.localhost/account');
        }

        $this->inside('mla', function (): void {
            // 会員になった印は 1 回だけ（テーマの数だけ入っても）。台帳はゲストの時の 1 つのまま
            $account = CustomerAccount::query()->whereNotNull('member_since')->sole();
            $this->assertSame(1, DomainEvent::query()->where('type', 'customer_account.joined')->where('aggregate_id', $account->id)->count());
            $this->assertSame(2, CustomerAccount::query()->count());
            // DB の印はハッシュだけ（Cookie の合い言葉は DB に無い）
            $this->assertNull(CustomerSession::query()->where('token_hash', (string) $this->member)->first());
        });
    }

    public function test_the_answer_is_the_same_whether_or_not_the_email_is_known(): void
    {
        $this->order('mla', 'hanako@example.com');

        // 知っているメールも知らないメールも、同じ道で同じ画面。どちらにもコードを送る（会員になるのはコードが合った時）
        foreach (['hanako@example.com', 'nobody@example.com'] as $email) {
            $this->send('mla', 'POST', '/account/login', ['email' => $email])->assertRedirect('http://mla.localhost/account/login/code');
            $this->send('mla', 'GET', '/account/login/code')->assertOk()->assertSee('コードをお送りしました');
        }
        Mail::assertSent(LoginCodeMail::class, 2);
        $this->inside('mla', fn () => $this->assertSame(1, CustomerAccount::query()->count()));

        // 形の違うメール・配列・長すぎるメールは断る（送らない）
        foreach (['not-an-email', str_repeat('a', 250).'@example.com'] as $bad) {
            $this->send('mla', 'POST', '/account/login', ['email' => $bad])->assertRedirect('http://mla.localhost/account/login');
        }
        $this->send('mla', 'POST', '/account/login', ['email' => ['x@example.com']])->assertRedirect('http://mla.localhost/account/login');
        Mail::assertSent(LoginCodeMail::class, 2);

        // コードのメールにリンクは無く、件名にコードを入れない
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail): bool {
            $text = view('mail.customer-login-code-text', ['shopName' => $mail->shopName, 'code' => $mail->code, 'minutes' => $mail->minutes])->render();
            $this->assertStringNotContainsString('http', $text);
            $this->assertStringNotContainsString($mail->code, (string) $mail->envelope()->subject);

            return true;
        });
    }

    public function test_a_code_works_once_within_ten_minutes_and_five_tries(): void
    {
        // 使った後は使えない
        $code = $this->requestCode('mla', 'hanako@example.com');
        $this->login('mla', $code);
        $this->send('mla', 'POST', '/account/logout')->assertRedirect('http://mla.localhost');
        $this->send('mla', 'POST', '/account/login', ['email' => 'hanako@example.com']);
        Mail::assertSent(LoginCodeMail::class, 2);
        $this->assertWrong('mla', $code);

        // 期限切れ（10 分）。同じメール × 接続元は 1 時間に 5 通までなので、場面ごとに接続元を変える
        $ip = '198.51.100.20';
        $code = $this->requestCode('mla', 'hanako@example.com', $ip);
        $this->travel(CustomerLogin::CODE_MINUTES + 1)->minutes();
        $this->send('mla', 'POST', '/account/login/code', ['code' => $code], $ip)->assertRedirect('http://mla.localhost/account/login');
        // DB のコードも期限で切れる（このブラウザの控えとは別に）
        $this->inside('mla', fn () => $this->assertNull(app(CustomerLogin::class)->verify('hanako@example.com', $code)));

        // 5 回外すと、正しいコードでも入れない
        $ip = '198.51.100.21';
        $code = $this->requestCode('mla', 'hanako@example.com', $ip);
        $wrong = str_pad((string) (((int) $code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
        for ($i = 0; $i < CustomerLogin::MAX_ATTEMPTS; $i++) {
            $this->assertWrong('mla', $wrong, $ip);
        }
        $this->assertWrong('mla', $code, $ip);

        // 新しいコードを出すと、前のコードは使えない。全角の数字と空白は受ける
        $ip = '198.51.100.22';
        $old = $this->requestCode('mla', 'hanako@example.com', $ip);
        $new = $this->requestCode('mla', 'hanako@example.com', $ip);
        if ($old !== $new) {
            $this->assertWrong('mla', $old, $ip);
        }
        $this->send('mla', 'POST', '/account/login/code', ['code' => mb_convert_kana(substr($new, 0, 3).' '.substr($new, 3), 'N')], $ip)->assertRedirect('http://mla.localhost/account');

        // 平文のコードは DB に無い
        $this->inside('mla', fn () => $this->assertSame(0, CustomerLoginCode::query()->where('code_hash', $new)->count()));
    }

    public function test_sending_is_limited_per_email_and_source_but_not_for_the_whole_shop(): void
    {
        // 同じメール × 同じ接続元は 1 時間に 5 通まで（超えても答えは同じ）
        for ($i = 0; $i < MemberLogin::PAIR_HOURLY_SENDS + 2; $i++) {
            $this->send('mla', 'POST', '/account/login', ['email' => 'hanako@example.com'], '198.51.100.1')->assertRedirect('http://mla.localhost/account/login/code');
        }
        Mail::assertSent(LoginCodeMail::class, MemberLogin::PAIR_HOURLY_SENDS);

        // 1 つの接続元からたくさんのメールへは 1 時間に 20 通まで
        for ($i = 0; $i < MemberLogin::SOURCE_HOURLY_SENDS; $i++) {
            $this->send('mla', 'POST', '/account/login', ['email' => "spam{$i}@example.com"], '198.51.100.1');
        }
        Mail::assertSent(LoginCodeMail::class, MemberLogin::SOURCE_HOURLY_SENDS);

        // ほかの接続元のお客さんは、そのまま送れる（店全体の 1 つの枠にしない）。ほかの店の枠も別
        $this->send('mla', 'POST', '/account/login', ['email' => 'jiro@example.com'], '203.0.113.7');
        $this->send('mlb', 'POST', '/account/login', ['email' => 'hanako@example.com'], '198.51.100.9');
        Mail::assertSent(LoginCodeMail::class, MemberLogin::SOURCE_HOURLY_SENDS + 2);
    }

    public function test_codes_for_one_email_are_limited_across_sources_and_misses_do_not_lock_out(): void
    {
        // たくさんの接続元から 1 人へ: 接続元をまたいで 1 時間に 10 通まで（当てにくる数は 10 通 × 5 回で止まる）
        for ($i = 0; $i < MemberLogin::EMAIL_HOURLY_SENDS + 3; $i++) {
            $this->send('mla', 'POST', '/account/login', ['email' => 'hanako@example.com'], '198.51.100.'.($i + 1));
        }
        Mail::assertSent(LoginCodeMail::class, MemberLogin::EMAIL_HOURLY_SENDS);

        // 外れを重ねても、本人の締め出しにはならない（コードごとの 5 回だけ）。1 時間たてば、本人は新しいコードで入れる
        for ($i = 0; $i < 30; $i++) {
            $this->send('mla', 'POST', '/account/login/code', ['code' => '000000'], '203.0.113.'.($i + 1));
        }
        $this->travel(61)->minutes();
        $this->login('mla', $this->requestCode('mla', 'hanako@example.com', '203.0.113.200'), '203.0.113.200');

        // 1 日では 20 通まで（10 通の 2 時間分で止まる）
        $this->send('mla', 'POST', '/account/logout');
        for ($i = 0; $i < MemberLogin::EMAIL_HOURLY_SENDS; $i++) {
            $this->send('mla', 'POST', '/account/login', ['email' => 'hanako@example.com'], '192.0.2.'.($i + 1));
        }
        $this->travel(61)->minutes();
        $this->send('mla', 'POST', '/account/login', ['email' => 'hanako@example.com'], '192.0.2.200');
        Mail::assertSent(LoginCodeMail::class, MemberLogin::EMAIL_DAILY_SENDS);
        // ほかのお客さんは送れる
        $this->requestCode('mla', 'jiro@example.com', '192.0.2.201');
    }

    public function test_the_queued_job_carries_no_email_or_code(): void
    {
        Queue::fake();
        $this->send('mla', 'POST', '/account/login', ['email' => 'hanako@example.com'])->assertRedirect('http://mla.localhost/account/login/code');
        Queue::assertPushed(SendLoginCode::class, function (SendLoginCode $job): bool {
            $payload = serialize($job);
            $this->assertStringNotContainsString('hanako', $payload);
            $this->assertMatchesRegularExpression('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $job->codeId);

            // 送る仕事が読むと、行の宛先とコードは消える（2 回目は読めない）
            $this->inside('mla', function () use ($job): void {
                $row = CustomerLoginCode::query()->findOrFail($job->codeId);
                $this->assertStringStartsWith(TenantCipher::PREFIX, (string) DB::table('customer_login_codes')->where('id', $row->id)->value('email'));
                $this->assertNotNull(app(CustomerLogin::class)->takeForSending($job->codeId));
                $this->assertNull(app(CustomerLogin::class)->takeForSending($job->codeId));
                $this->assertNull(DB::table('customer_login_codes')->where('id', $row->id)->value('sealed_code'));
            });

            return true;
        });
    }

    public function test_the_member_mark_does_not_cross_shops_and_dies_on_logout_and_disable(): void
    {
        $this->login('mla', $this->requestCode('mla', 'hanako@example.com'));
        $token = (string) $this->member;
        $this->send('mla', 'GET', '/account')->assertOk();

        // 同じ印を別の店のホストへ持ち込んでも入れない（印は店の DB にある）。別の店で同じメールでも別の会員
        $this->send('mlb', 'GET', '/account')->assertRedirect('http://mlb.localhost/account/login');
        $this->inside('mlb', fn () => $this->assertSame(0, CustomerAccount::query()->count()));

        // 改ざん・形の違う印
        foreach ([str_repeat('0', 64), 'not-a-token', ''] as $bad) {
            $this->member = $bad;
            $this->send('mla', 'GET', '/account')->assertRedirect('http://mla.localhost/account/login');
        }

        // ログアウトした印は、控えておいても効かない
        $this->member = $token;
        $this->send('mla', 'POST', '/account/logout')->assertRedirect('http://mla.localhost');
        $this->member = $token;
        $this->send('mla', 'GET', '/account')->assertRedirect('http://mla.localhost/account/login');

        // 止めた台帳: 今の印も効かず、コードでも入れない
        $this->login('mla', $this->requestCode('mla', 'hanako@example.com'));
        $this->inside('mla', fn () => CustomerAccount::query()->update(['disabled_at' => now()]));
        $this->send('mla', 'GET', '/account')->assertRedirect('http://mla.localhost/account/login');
        $this->assertWrong('mla', $this->requestCode('mla', 'hanako@example.com'));

        // 入り直すと、このブラウザの前の印は取り消す（止めた台帳を戻してから）
        $this->inside('mla', fn () => CustomerAccount::query()->update(['disabled_at' => null]));
        $this->login('mla', $this->requestCode('mla', 'hanako@example.com'));
        $before = (string) $this->member;
        $this->login('mla', $this->requestCode('mla', 'hanako@example.com', '198.51.100.40'), '198.51.100.40');
        $this->assertNotSame($before, $this->member);
        $this->inside('mla', fn () => $this->assertNotNull(CustomerSession::query()->where('token_hash', hash('sha256', $before))->value('revoked_at')));

        // 使って期限を延ばした時は、Cookie も出し直す（1 時間に 1 回まで）
        $this->travel(2)->hours();
        $this->assertNotNull($this->send('mla', 'GET', '/account')->assertOk()->getCookie(MemberCookie::NAME));
        $this->assertNull($this->send('mla', 'GET', '/account')->assertOk()->getCookie(MemberCookie::NAME));

        // 30 日使わなければ切れる。使っていれば延びる
        $this->inside('mla', fn () => CustomerAccount::query()->update(['disabled_at' => null]));
        $this->login('mla', $this->requestCode('mla', 'hanako@example.com'));
        $this->travel(20)->days();
        $this->send('mla', 'GET', '/account')->assertOk();
        $this->travel(20)->days();
        $this->send('mla', 'GET', '/account')->assertOk();
        $this->travel(31)->days();
        $this->send('mla', 'GET', '/account')->assertRedirect('http://mla.localhost/account/login');

        // 使い続けても、ログインから 90 日で切れる（盗まれた印を延ばし続けさせない）
        $this->login('mla', $this->requestCode('mla', 'hanako@example.com', '198.51.100.41'), '198.51.100.41');
        for ($day = 0; $day < 3; $day++) {
            $this->travel(29)->days();
            $this->send('mla', 'GET', '/account')->assertOk();
        }
        $this->travel(4)->days();
        $this->send('mla', 'GET', '/account')->assertRedirect('http://mla.localhost/account/login');
    }

    public function test_the_code_page_needs_a_recent_request_in_this_browser(): void
    {
        // メールを入れていないブラウザでは、コードの欄に進めない（どのメールのコードかを外から選ばせない）
        $this->send('mla', 'GET', '/account/login/code')->assertRedirect('http://mla.localhost/account/login');
        $this->send('mla', 'POST', '/account/login/code', ['code' => '123456'])->assertRedirect('http://mla.localhost/account/login');

    }

    public function test_a_used_code_and_an_older_code_never_work_again(): void
    {
        $this->inside('mla', function (): void {
            $login = app(CustomerLogin::class);
            // コードは送る仕事だけが読む（issue が返すのは行の ID）
            $issue = static fn (string $email): string => (string) $login->takeForSending($login->issue($email))['code'];

            // 使ったコードは、次のコードを出していなくても、もう一度は通らない
            $code = $issue('hanako@example.com');
            $this->assertNotNull($login->verify('hanako@example.com', $code));
            $this->assertNull($login->verify('hanako@example.com', $code));

            // 前のコード（A）は、新しいコード（B）を使った後に繰り上がって通ったりしない
            $a = $issue('hanako@example.com');
            $b = $issue('hanako@example.com');
            $this->assertNotNull($login->verify('hanako@example.com', $b));
            $this->assertNull($login->verify('hanako@example.com', $a));

            // 同時に出して前のコード（C1）が止まらずに残っても、新しいコード（C2）が合った時に止める（C1 が繰り上がって通らない）
            $c1 = $login->issue('hanako@example.com');
            $c1code = (string) $login->takeForSending($c1)['code'];
            $c2code = $issue('hanako@example.com');
            CustomerLoginCode::query()->whereKey($c1)->update(['expires_at' => now()->addMinutes(5)]);
            $this->assertNotNull($login->verify('hanako@example.com', $c2code));
            $this->assertNull($login->verify('hanako@example.com', $c1code));

            // 別のメールのコードでは入れない
            $c = $issue('jiro@example.com');
            $this->assertNull($login->verify('hanako@example.com', $c));
            $this->assertNull($login->verify('hanako@example.com', 'abcdef'));
        });
    }

    private function requestCode(string $shop, string $email, ?string $ip = null): string
    {
        $count = count(Mail::sent(LoginCodeMail::class));
        $this->send($shop, 'POST', '/account/login', ['email' => $email], $ip)->assertRedirect("http://{$shop}.localhost/account/login/code");
        $mails = Mail::sent(LoginCodeMail::class);
        $this->assertCount($count + 1, $mails, 'コードのメールが送られていない');

        return $mails->last()->code;
    }

    private function login(string $shop, string $code, ?string $ip = null): void
    {
        $this->send($shop, 'POST', '/account/login/code', ['code' => $code], $ip)->assertRedirect("http://{$shop}.localhost/account");
        $this->send($shop, 'GET', '/account', [], $ip)->assertOk();
    }

    private function assertWrong(string $shop, string $code, ?string $ip = null): void
    {
        $this->send($shop, 'POST', '/account/login/code', ['code' => $code], $ip)->assertRedirect("http://{$shop}.localhost/account/login/code");
        $this->assertStringContainsString('コードが違うか、期限が切れました', (string) $this->send($shop, 'GET', '/account/login/code', [], $ip)->getContent());
    }

    private function order(string $shop, string $email): string
    {
        return $this->inside($shop, function () use ($email): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '<b>ニット</b>', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => $email, 'phone' => '09012345678', 'address' => ['city' => '渋谷区']]);
            $account = app(CustomerAccounts::class)->forEmail($email, 'storefront');
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'storefront', customerAccount: $account);
            CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);

            return $sale->id;
        });
    }

    /**
     * 会員の印の Cookie を持ち越して送る（セッションはテストの中で続く）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $shop, string $method, string $path, array $data = [], ?string $ip = null): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        // 送るたびに 7 秒進める（人の速さ。接続元ごとの 1 分に 10 回の制限に、テスト自身が当たらないように）
        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        // 本番（php-fpm）と同じく、要求ごとにログインの仕組みを作り直す（覚えた会員を次の要求へ持ち越さない）
        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $server = $ip === null ? [] : ['REMOTE_ADDR' => $ip];
        $response = $method === 'GET' ? $this->withServerVariables($server)->get($url) : $this->withServerVariables($server)->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }

    private function shop(string $id): void
    {
        $this->tenant($id);
    }
}
