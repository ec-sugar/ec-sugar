<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Crypto\BlindIndex;
use App\Core\Event\Models\DomainEvent;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerAddress;
use App\Core\Party\Models\CustomerSession;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Plugin\LoginEntry;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Http\Controllers\AccountController;
use App\Storefront\Http\Controllers\MemberSecurityController;
use App\Storefront\Members\AccountNoticeMail;
use App\Storefront\Members\EmailChangedMail;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberSignIn;
use EcSugar\PluginLine\LineLinks;
use EcSugar\PluginLine\LineLogin;
use EcSugar\PluginLine\LinkOutcome;
use EcSugar\PluginLine\Models\LineLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「LINE 連携」の LINE でログイン（ADR 0070）。認可コードフロー ＋ PKCE ＋ state ＋ nonce、ID トークンの確かめ、
 * つなぎ方（LINE から来た値で既存の会員に自動でつながない・メールのコードを通ってから）、1 つの LINE は 1 人だけ、
 * マイページのつなぐ・外す（本人の再確認の後だけ）、退会で消える、App を止めると入口が消える、核の口の守り（回数・止めた台帳）
 */
final class LineLoginTest extends TestCase
{
    use DatabaseTruncation;
    use LineHelpers;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->fakeLine();
        $this->tenant('lna', 'かくざとう');
        $this->configureLine('lna');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_a_new_line_is_linked_after_the_email_code_and_then_logs_in_directly(): void
    {
        // ログインの画面に入口が出る（核の口）
        $this->shop('GET', '/account/login')->assertOk()->assertSee('data-test="account-app-login"', false)->assertSee('/p/line/login', false)->assertSee('LINE でログイン');

        $query = $this->startLine();
        // 認可の画面へ渡す値: コードのフロー・このショップの戻り先だけ・メールは求めない・PKCE（S256）・友だち追加
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::CHANNEL, $query['client_id']);
        $this->assertSame('http://lna.localhost/p/line/callback', $query['redirect_uri']);
        $this->assertSame('profile openid', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('normal', $query['bot_prompt']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $query['state']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $query['nonce']);

        // まだつないでいない LINE は、メールの確認へ（会員の印はまだ出さない）
        $this->returnFromLine($query)->assertRedirect('http://lna.localhost/p/line/email');
        $this->assertNull($this->member);
        // コードと引き換え: PKCE の code_verifier は、渡した code_challenge の元。戻り先とチャネルの鍵も添える
        Http::assertSent(function (HttpRequest $request) use ($query): bool {
            return $request->url() === LineLogin::TOKEN && $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code-1'
                && $request['redirect_uri'] === 'http://lna.localhost/p/line/callback' && $request['client_id'] === self::CHANNEL && $request['client_secret'] === self::SECRET
                && rtrim(strtr(base64_encode(hash('sha256', (string) $request['code_verifier'], true)), '+/', '-_'), '=') === $query['code_challenge'];
        });
        // セッションには LINE の ID を平文で置かない
        $this->assertStringNotContainsString(self::SUB, (string) json_encode(session()->all()));

        $this->shop('GET', '/p/line/email')->assertOk()->assertSee('data-test="line-email"', false);
        $this->shop('POST', '/p/line/email', ['email' => 'hanako@example.com'])->assertRedirect('http://lna.localhost/p/line/code');
        $this->assertStringNotContainsString(self::SUB, (string) json_encode(session()->all()));
        $this->shop('GET', '/p/line/code')->assertOk()->assertSee('hanako@example.com');
        // コードのメールは「LINE をつなぐためのコード」（心当たりが無ければ誰にも伝えない）
        $codeMail = Mail::sent(LoginCodeMail::class)->last();
        $this->assertSame('LINE', $codeMail->linkingName());
        $codeMail->assertHasSubject('[かくざとう] LINE との連携用の確認コード');
        $codeMail->assertSeeInText('LINE と連携するための確認コードです');
        $codeMail->assertSeeInText('心当たりがない場合は、このコードをだれにも伝えないでください');
        // 違うコードでは、つながない・入れない。確かめた LINE の控えも消え、最初から
        $this->shop('POST', '/p/line/code', ['code' => $this->lastCode() === '000000' ? '111111' : '000000'])->assertRedirect('http://lna.localhost/account/login');
        $this->assertNull(session('line_pending'));
        $this->assertSame(0, $this->inside('lna', fn (): int => LineLink::query()->count()));
        $this->shop('POST', '/p/line/code', ['code' => $this->lastCode()])->assertRedirect('http://lna.localhost/account/login');
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/p/line/email');
        $this->shop('POST', '/p/line/email', ['email' => 'hanako@example.com']);
        $this->shop('POST', '/p/line/code', ['code' => $this->lastCode()])->assertRedirect('http://lna.localhost/account');
        // ログインしたら、途中の値（LINE の state・確かめ待ちの LINE）は残らない
        $this->assertNull(session('line_oauth'));
        $this->assertNull(session('line_pending'));
        // つないだことを、会員のメールへ事後に知らせる（何をしたかと日時だけ）
        Mail::assertSent(AccountNoticeMail::class, fn (AccountNoticeMail $mail): bool => $mail->hasTo('hanako@example.com') && str_starts_with($mail->what, 'LINE と連携しました'));
        // マイページの見出しに「LINE をつないでいます」
        $this->shop('GET', '/account')->assertOk()->assertSee('data-test="account-linked-login"', false)->assertSee('LINE と連携しています');
        $this->assertNotNull($this->member);

        $this->inside('lna', function (): void {
            $account = CustomerAccount::query()->where('email_index', BlindIndex::of('email', 'hanako@example.com'))->firstOrFail();
            $this->assertNotNull($account->member_since);
            $link = LineLink::query()->sole();
            $this->assertSame($account->id, $link->customer_account_id);
            $this->assertTrue($link->friend);
            // 店の DB には暗号文と HMAC だけ
            $raw = DB::table('line_links')->first();
            $this->assertStringStartsWith('te1:', (string) $raw->line_user);
            $this->assertStringNotContainsString(self::SUB, (string) json_encode($raw));
            $this->assertSame(self::SUB, $link->line_user);
            // 記録には LINE の ID を入れない
            $event = DomainEvent::query()->where('type', 'line.linked')->sole();
            $this->assertSame([], $event->payload);
            $this->assertSame('storefront', $event->actor);
            $this->assertStringNotContainsString(self::SUB, (string) json_encode(DomainEvent::query()->pluck('payload')));
        });

        // ログアウトして、もう一度 LINE で: メールを聞かずにその会員で入る（核の口でセッションを作り直し、会員の印の行を作る）
        $this->shop('POST', '/account/logout');
        $this->member = null;
        $sessions = $this->inside('lna', fn (): int => CustomerSession::query()->count());
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/account');
        $this->assertNotNull($this->member);
        $this->assertSame($sessions + 1, $this->inside('lna', fn (): int => CustomerSession::query()->count()));
        // 印の行に入り方を残す（メールのコードで入った時は code、LINE で入った時は App の名前）
        $this->assertSame(['code', 'line'], $this->inside('lna', fn (): array => CustomerSession::query()->orderBy('created_at')->orderBy('id')->pluck('via')->all()));
        $this->shop('GET', '/account')->assertOk();
        Mail::assertSent(LoginCodeMail::class, 2);
    }

    public function test_a_line_carrying_a_members_email_is_not_linked_until_the_code_is_entered(): void
    {
        $this->login('hanako@example.com');
        $this->shop('POST', '/account/logout');
        $this->member = null;
        $hanako = $this->inside('lna', fn (): string => (string) CustomerAccount::query()->value('id'));

        // ID トークンに会員と同じメールが入っていても（求めていないが、来ても）読まない。自動でつながず、入れない
        $this->returnFromLine($this->startLine(), ['email' => 'hanako@example.com'])->assertRedirect('http://lna.localhost/p/line/email');
        $this->assertNull($this->member);
        $this->assertSame(0, $this->inside('lna', fn (): int => LineLink::query()->count()));
        $this->shop('GET', '/account')->assertRedirect();

        // ほかの人のメールを入れても、コードは本当の持ち主に届くだけ（コードを知らなければ、つながらない）
        $this->shop('POST', '/p/line/email', ['email' => 'hanako@example.com']);
        Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail): bool => $mail->hasTo('hanako@example.com'));
        $this->shop('POST', '/p/line/code', ['code' => '999990'])->assertRedirect('http://lna.localhost/account/login');
        $this->assertFalse($this->inside('lna', fn (): bool => LineLink::query()->exists()));

        // 持ち主がコードを入れると、その会員（新しく作らない）につながる
        $this->returnFromLine($this->startLine());
        $this->shop('POST', '/p/line/email', ['email' => 'hanako@example.com']);
        $this->shop('POST', '/p/line/code', ['code' => $this->lastCode()])->assertRedirect('http://lna.localhost/account');
        $this->inside('lna', function () use ($hanako): void {
            $this->assertSame(1, CustomerAccount::query()->count());
            $this->assertSame($hanako, LineLink::query()->sole()->customer_account_id);
        });
    }

    public function test_the_state_is_bound_to_this_browser_used_once_and_expires(): void
    {
        $tokenCalls = fn (): int => count(Http::recorded(fn (HttpRequest $r): bool => $r->url() === LineLogin::TOKEN));

        // 始めていない（state が無い）
        $this->shop('GET', '/p/line/callback?code=x&state='.str_repeat('a', 64))->assertRedirect('http://lna.localhost/account/login')->assertSessionHas('cart_error');
        // 違う state。断った後は、正しい state でも使えない（1 回きり）
        $query = $this->startLine();
        $this->returnFromLine($query, state: str_repeat('0', 64))->assertRedirect('http://lna.localhost/account/login');
        $this->returnFromLine($query)->assertRedirect('http://lna.localhost/account/login');
        // 合った state でも、2 度目は使えない
        $query = $this->startLine();
        $this->returnFromLine($query)->assertRedirect('http://lna.localhost/p/line/email');
        $before = $tokenCalls();
        $this->returnFromLine($query)->assertRedirect('http://lna.localhost/account/login');
        $this->assertSame($before, $tokenCalls());
        // ほかのブラウザ（セッションが違う）で戻っても使えない
        $query = $this->startLine();
        $this->flushSession();
        $this->returnFromLine($query)->assertRedirect('http://lna.localhost/account/login');
        // 10 分を過ぎた
        $query = $this->startLine();
        $this->travel(LineLogin::MINUTES + 1)->minutes();
        $this->returnFromLine($query)->assertRedirect('http://lna.localhost/account/login');
        // お客さんが同意の画面でやめた
        $query = $this->startLine();
        $this->shop('GET', '/p/line/callback?'.http_build_query(['error' => 'ACCESS_DENIED', 'state' => $query['state']]))->assertRedirect('http://lna.localhost/account/login')
            ->assertSessionHas('cart_error', 'LINE でのログインを中止しました。');

        // 断った戻りでは、どれもコードをトークンに替えに行かない
        $this->assertSame($before, $tokenCalls());
        $this->assertSame(0, $this->inside('lna', fn (): int => LineLink::query()->count()));
    }

    public function test_an_id_token_not_signed_for_this_channel_or_this_login_is_refused(): void
    {
        $now = time();
        $cases = [
            'ほかの鍵の署名' => fn () => $this->signWith = 'ffffffffffffffffffffffffffffffff',
            'alg が none' => fn () => $this->header = ['typ' => 'JWT', 'alg' => 'none'],
            'ほかのチャネル' => fn () => $this->claims['aud'] = '9999999999',
            'ほかの発行元' => fn () => $this->claims['iss'] = 'https://access.line.me.evil.example',
            '期限切れ' => fn () => $this->claims['exp'] = $now - 10,
            'nonce が違う' => fn () => $this->claims['nonce'] = str_repeat('f', 64),
        ];

        foreach ($cases as $why => $break) {
            $query = $this->startLine();
            $claims = ['iss' => 'https://access.line.me', 'aud' => self::CHANNEL, 'exp' => $now + 3600, 'nonce' => $query['nonce']];
            $this->claims = $claims;
            $break();
            $broken = $this->claims;
            $response = $this->returnFromLine($query, $broken);
            $this->signWith = self::SECRET;
            $this->header = ['typ' => 'JWT', 'alg' => 'HS256'];

            $response->assertRedirect('http://lna.localhost/account/login');
            $this->assertNull(session('line_pending'), $why);
        }

        $this->assertSame(0, $this->inside('lna', fn (): int => LineLink::query()->count()));
    }

    public function test_redirects_stay_inside_the_shop(): void
    {
        // 外から redirect_uri を渡しても、戻り先はショップの決まった経路だけ
        $query = $this->startLine(path: '/p/line/login?redirect_uri='.urlencode('https://evil.example/cb').'&return='.urlencode('https://evil.example'));
        $this->assertSame('http://lna.localhost/p/line/callback', $query['redirect_uri']);

        // ログインの後の戻り先は App の画面だけ（外の URL は覚えない）
        $this->linkHanako();
        $this->shop('POST', '/account/logout');
        $this->member = null;
        $this->shop('GET', '/account/login?return='.urlencode('https://evil.example/x'));
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/account');
        $this->shop('POST', '/account/logout');
        $this->member = null;
        $this->shop('GET', '/account/login?return=/p/line/account');
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/p/line/account');

        // App の入口も、自分の /p/<名前>/ の下だけ
        foreach (['https://evil.example/login', '/account/login', '/p/other/login', '/p/line//evil.example'] as $path) {
            try {
                new LoginEntry('line', 'LINE でログイン', $path);
                $this->fail("入口に {$path} を置けた");
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_one_line_is_linked_to_one_member_only(): void
    {
        $hanako = $this->linkHanako();

        // ほかの会員が同じ LINE をつなごうとしても断る（行は 1 つのまま）
        $this->shop('POST', '/account/logout');
        $this->member = null;
        $this->login('taro@example.com');
        $this->confirmByCode();
        $this->shop('POST', '/p/line/connect')->assertRedirect();
        $query = $this->lastAuthorize();
        $this->returnFromLine($query)->assertRedirect('http://lna.localhost/p/line/account')->assertSessionHas('cart_error', 'この LINE は、すでに別の会員アカウントと連携しています。');

        $this->inside('lna', function () use ($hanako): void {
            $this->assertSame($hanako, LineLink::query()->sole()->customer_account_id);
            $taro = CustomerAccount::query()->whereKeyNot($hanako)->firstOrFail();
            $hanakoAccount = CustomerAccount::query()->findOrFail($hanako);
            $links = app(LineLinks::class);
            // 同じことを 2 つの要求が続けて（同時の 2 つの 1 つ目が先に済んだ形で）しても、2 つ目は断る
            $this->assertSame(LinkOutcome::LineTaken, $links->link($taro, self::SUB, true, 'test'));
            $this->assertSame(LinkOutcome::Already, $links->link($hanakoAccount, self::SUB, true, 'test'));
            // 1 人に 1 つだけ
            $this->assertSame(LinkOutcome::AccountTaken, $links->link($hanakoAccount, self::OTHER_SUB, true, 'test'));
            $this->assertSame(1, LineLink::query()->count());
            $this->assertSame(1, DomainEvent::query()->where('type', 'line.linked')->count());
        });

        // アプリの確かめをすり抜けても、DB の一意が 2 行目を拒む（同時の 2 つのつなぎへの最後の守り）
        $this->inside('lna', function () use ($hanako): void {
            $taro = (string) CustomerAccount::query()->whereKeyNot($hanako)->value('id');
            $this->expectException(QueryException::class);
            DB::table('line_links')->insert(['id' => strtolower((string) Str::ulid()), 'customer_account_id' => $taro, 'line_user' => 'x', 'line_user_index' => LineLinks::index(self::SUB), 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function test_two_browsers_finishing_the_same_line_at_once_link_only_one_member(): void
    {
        $this->login('taro@example.com');
        $this->shop('POST', '/account/logout');
        $this->member = null;

        // 1 つ目のブラウザ: LINE → メールのコードまで（つなぐ直前）
        $this->returnFromLine($this->startLine());
        $this->shop('POST', '/p/line/email', ['email' => 'hanako@example.com']);
        $hanakoCode = $this->lastCode();
        $first = session()->all();

        // 2 つ目のブラウザ: 同じ LINE で、別のメール（taro）のコードまで
        $this->flushSession();
        $this->returnFromLine($this->startLine());
        $this->shop('POST', '/p/line/email', ['email' => 'taro@example.com'], '198.51.100.2');
        $this->shop('POST', '/p/line/code', ['code' => $this->lastCode()], '198.51.100.2')->assertRedirect('http://lna.localhost/account');
        $this->member = null;

        // 1 つ目が後から済んでも、同じ LINE は 2 人目につながない（ログインはコードで確かめたので入れる）
        $this->flushSession();
        session()->put($first);
        $this->shop('POST', '/p/line/code', ['code' => $hanakoCode])->assertRedirect('http://lna.localhost/account')
            ->assertSessionHas('cart_error', 'この LINE は、すでに別の会員アカウントと連携しているため、連携しませんでした。');
        $this->inside('lna', function (): void {
            $this->assertSame(1, LineLink::query()->count());
            $this->assertSame('taro@example.com', CustomerAccount::query()->findOrFail(LineLink::query()->sole()->customer_account_id)->email);
        });
    }

    public function test_connecting_and_disconnecting_need_a_fresh_confirmation_by_the_same_member(): void
    {
        $this->login('hanako@example.com');
        $this->shop('GET', '/p/line/account')->assertOk()->assertSee('data-test="line-connect"', false);

        // 再確認の前は、つながず再確認の画面へ（確かめた後は App の画面へ戻る）
        $this->shop('POST', '/p/line/connect')->assertRedirect('http://lna.localhost/account/confirm?next=app&return=%2Fp%2Fline%2Faccount');
        $this->shop('GET', '/account/confirm?next=app&return=/p/line/account')->assertOk();
        $this->shop('POST', '/account/confirm/send', ['next' => 'app']);
        $this->shop('POST', '/account/confirm', ['next' => 'app', 'code' => $this->lastCode()])->assertRedirect('http://lna.localhost/p/line/account');

        $this->shop('POST', '/p/line/connect')->assertRedirect();
        $this->returnFromLine($this->lastAuthorize())->assertRedirect('http://lna.localhost/p/line/account')->assertSessionHas('cart_message');
        $this->shop('GET', '/p/line/account')->assertOk()->assertSee('data-test="line-disconnect"', false);
        $this->assertSame(1, $this->inside('lna', fn (): int => LineLink::query()->count()));

        // 外す（10 分のうちの再確認で）。外したら LINE では入れず、メールの確認へ
        $this->shop('POST', '/p/line/disconnect')->assertRedirect('http://lna.localhost/p/line/account');
        $this->inside('lna', function (): void {
            $this->assertSame(0, LineLink::query()->count());
            $this->assertSame(['reason' => 'member'], DomainEvent::query()->where('type', 'line.unlinked')->sole()->payload);
        });
        Mail::assertSent(AccountNoticeMail::class, fn (AccountNoticeMail $mail): bool => $mail->hasTo('hanako@example.com') && str_starts_with($mail->what, 'LINE 連携を解除しました'));
        $this->shop('GET', '/account')->assertOk()->assertDontSee('data-test="account-linked-login"', false);

        // 再確認から 10 分を過ぎたら、もう一度
        $this->travel(11)->minutes();
        $this->shop('POST', '/p/line/connect')->assertRedirect('http://lna.localhost/account/confirm?next=app&return=%2Fp%2Fline%2Faccount');

        // つなぐ途中で別の会員に入れ替わった（同じブラウザで別の人がログインした）なら、つながない
        $this->shop('POST', '/account/confirm/send', ['next' => 'app']);
        $this->shop('POST', '/account/confirm', ['next' => 'app', 'code' => $this->lastCode()]);
        $this->shop('POST', '/p/line/connect');
        $query = $this->lastAuthorize();
        $pending = session('line_oauth');
        $this->shop('POST', '/account/logout');
        $this->member = null;
        $this->login('taro@example.com');
        session()->put('line_oauth', $pending);
        $this->returnFromLine($query)->assertRedirect('http://lna.localhost/account/login');
        $this->assertSame(0, $this->inside('lna', fn (): int => LineLink::query()->count()));

        // 会員でなければ、つなぐ・外すはログインの画面へ
        $this->shop('POST', '/account/logout');
        $this->member = null;
        $this->shop('POST', '/p/line/connect')->assertRedirect('http://lna.localhost/account/login?return=%2Fp%2Fline%2Faccount');
        $this->shop('POST', '/p/line/disconnect')->assertRedirect('http://lna.localhost/account/login?return=%2Fp%2Fline%2Faccount');
    }

    public function test_leaving_removes_the_link_and_a_stopped_or_unready_app_shows_no_entry(): void
    {
        $hanako = $this->linkHanako();
        $this->inside('lna', fn () => app(CustomerAccounts::class)->leave(CustomerAccount::query()->findOrFail($hanako), 'storefront'));
        $this->inside('lna', function (): void {
            $this->assertSame(0, LineLink::query()->count());
            $this->assertSame(['reason' => 'left'], DomainEvent::query()->where('type', 'line.unlinked')->sole()->payload);
        });

        // 設定がそろっていなければ入口を出さない
        $this->inside('lna', fn () => app(PluginSettings::class)->update('line', [], 'test', ['login_channel_secret']));
        $this->shop('GET', '/account/login')->assertOk()->assertDontSee('data-test="account-app-login"', false);
        $this->shop('GET', '/p/line/login')->assertRedirect('http://lna.localhost/account/login');

        // App を止めたら、入口も経路も無い
        $this->configureLine('lna');
        $this->inside('lna', fn () => app(PluginStates::class)->set('line', false, 'test'));
        $this->shop('GET', '/account/login')->assertOk()->assertDontSee('data-test="account-app-login"', false);
        $this->shop('GET', '/p/line/login')->assertNotFound();
        $this->shop('GET', '/p/line/callback?code=x&state=y')->assertNotFound();
    }

    public function test_the_core_gate_refuses_stopped_members_and_too_many_sign_ins(): void
    {
        $hanako = $this->linkHanako();
        $this->shop('POST', '/account/logout');
        $this->member = null;

        // 店の人が止めた台帳は、LINE でも入れない
        $this->inside('lna', fn () => CustomerAccount::query()->whereKey($hanako)->update(['disabled_at' => now()]));
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/account/login');
        $this->assertNull($this->member);
        $this->inside('lna', fn () => CustomerAccount::query()->whereKey($hanako)->update(['disabled_at' => null]));

        // 会員ごとに 1 時間 20 回まで（外の認証が通っても、印を出し続けさせない）
        $key = 'member-signin|lna|account|'.hash('sha256', $hanako);
        for ($i = 0; $i < MemberSignIn::ACCOUNT_PER_HOUR; $i++) {
            RateLimiter::hit($key, 3600);
        }
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/account/login');
        $this->assertNull($this->member);
        RateLimiter::clear($key);
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/account');
        $this->assertNotNull($this->member);
    }

    public function test_a_line_login_never_counts_as_a_fresh_confirmation(): void
    {
        $this->linkHanako();
        $this->shop('POST', '/account/logout');
        $this->member = null;
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/account');
        $this->assertNotNull($this->member);

        // LINE で入った直後でも、本人の再確認の要る操作はメールのコードかパスキーで確かめ直す（LINE のログインで再確認の時刻を進めない）
        $this->inside('lna', fn () => $this->assertNull(CustomerSession::query()->latest('created_at')->orderByDesc('id')->value('confirmed_at')));
        foreach ([['GET', '/account/email', 'email'], ['GET', '/account/passkeys', 'passkeys'], ['GET', '/account/leave', 'leave']] as [$method, $path, $next]) {
            $this->shop($method, $path)->assertRedirect("http://lna.localhost/account/confirm?next={$next}");
        }
        $this->shop('POST', '/account/leave')->assertRedirect('http://lna.localhost/account/confirm?next=leave');
        $this->shop('POST', '/p/line/disconnect')->assertRedirect('http://lna.localhost/account/confirm?next=app&return=%2Fp%2Fline%2Faccount');
        $this->assertSame(1, $this->inside('lna', fn (): int => LineLink::query()->count()));
        $this->assertNotNull($this->inside('lna', fn () => CustomerAccount::query()->whereNotNull('member_since')->first()));
    }

    public function test_an_unfinished_line_is_forgotten_after_ten_minutes_or_when_the_login_page_opens(): void
    {
        // 核のログインの画面を開くと、確かめ待ちの LINE は消える
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/p/line/email');
        $this->assertIsArray(session('line_pending'));
        $this->shop('GET', '/account/login')->assertOk();
        $this->assertNull(session('line_pending'));
        $this->shop('POST', '/p/line/email', ['email' => 'hanako@example.com'])->assertRedirect('http://lna.localhost/account/login');
        Mail::assertNothingSent();

        // 10 分を過ぎても消える
        $this->returnFromLine($this->startLine())->assertRedirect('http://lna.localhost/p/line/email');
        $this->travel(11)->minutes();
        $this->shop('POST', '/p/line/email', ['email' => 'hanako@example.com'])->assertRedirect('http://lna.localhost/account/login');
        Mail::assertNothingSent();
    }

    public function test_after_a_line_login_new_addresses_are_told_by_mail_and_an_email_change_names_the_linked_line(): void
    {
        $this->linkHanako();
        $this->shop('POST', '/account/logout');
        $this->member = null;
        $this->returnFromLine($this->startLine());

        // 届け先を足す・直すは止めずに、会員のメールへ事後に知らせる（住所は載せない）
        $home = ['name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];
        $this->shop('POST', '/account/addresses', ['address' => $home])->assertRedirect('http://lna.localhost/account/addresses');
        $added = Mail::sent(AccountNoticeMail::class, fn (AccountNoticeMail $mail): bool => $mail->what === 'お届け先を追加しました')->first();
        $this->assertNotNull($added);
        $this->assertTrue($added->hasTo('hanako@example.com'));
        $added->assertSeeInText('心当たりがない場合は');
        $added->assertDontSeeInText('神宮前');
        $id = $this->inside('lna', fn (): string => (string) CustomerAddress::query()->value('id'));
        $this->shop('POST', "/account/addresses/{$id}", ['address' => [...$home, 'line1' => '神南 9-9-9']]);
        Mail::assertSent(AccountNoticeMail::class, fn (AccountNoticeMail $mail): bool => $mail->what === 'お届け先を変更しました');

        // メールを変えたら、前のアドレスへの知らせに LINE のつなぎがあることも載せる
        $this->confirmByCode();
        $this->shop('POST', '/account/email', ['email' => 'hana@example.net']);
        $this->shop('POST', '/account/email/verify', ['code' => $this->lastCode()])->assertRedirect('http://lna.localhost/account');
        Mail::assertSent(EmailChangedMail::class, function (EmailChangedMail $mail): bool {
            $mail->assertSeeInText('LINE と連携しています');

            return $mail->hasTo('hanako@example.com') && $mail->linked === ['LINE と連携しています'];
        });
    }

    public function test_signing_in_drops_every_unfinished_login_and_confirmation_value(): void
    {
        // LINE の途中（state・確かめ待ちの LINE）と再確認の途中の値を残したまま、同じブラウザでメールのコードで入る
        $this->returnFromLine($this->startLine());
        $this->startLine();
        session()->put(MemberSecurityController::APP_RETURN, '/p/line/account');
        session()->put(MemberSecurityController::SENT, time());
        $this->assertIsArray(session('line_oauth'));
        $this->assertIsArray(session('line_pending'));

        $this->login('taro@example.com');
        $this->assertNotNull($this->member);
        foreach (['line_oauth', 'line_pending', MemberSecurityController::APP_RETURN, MemberSecurityController::SENT, AccountController::PENDING] as $key) {
            $this->assertNull(session($key), $key);
        }
    }

    public function test_only_the_apps_own_routes_can_sign_a_member_in(): void
    {
        $hanako = $this->linkHanako();
        $routes = app('router')->getRoutes();

        foreach (['storefront.account' => false, 'storefront.plugin.newsletter.form' => false] as $name => $allowed) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route, $name);
            $request = Request::create('http://lna.localhost/');
            $request->setRouteResolver(static fn () => $route);
            $request->setLaravelSession(app('session')->driver());
            $this->assertNull($this->inside('lna', fn () => app(MemberSignIn::class)->forApp($request, 'line', $hanako)), $name);
        }

        // 入っていない App・使っていない App の名前でも入れない
        $request = Request::create('http://lna.localhost/p/line/callback');
        $request->setRouteResolver(static fn () => $routes->getByName('storefront.plugin.line.callback'));
        $request->setLaravelSession(app('session')->driver());
        $this->assertNull($this->inside('lna', fn () => app(MemberSignIn::class)->forApp($request, 'nothing', $hanako)));
    }

    public function test_another_shop_never_sees_this_shops_link(): void
    {
        $this->linkHanako();
        $this->tenant('lnb', 'ほかの店');
        $this->configureLine('lnb');

        // 同じ LINE でも、ほかの店では誰ともつながっていない（店の鍵で引き手が違う）
        $this->returnFromLine($this->startLine('lnb'), shop: 'lnb')->assertRedirect('http://lnb.localhost/p/line/email');
        $this->assertSame(0, $this->inside('lnb', fn (): int => LineLink::query()->count()));
        $this->assertNotSame($this->inside('lna', fn (): string => LineLinks::index(self::SUB)), $this->inside('lnb', fn (): string => LineLinks::index(self::SUB)));
    }

    /**
     * hanako が LINE（SUB）でログイン・登録して、つないだ状態にする。台帳の ID を返す
     */
    private function linkHanako(): string
    {
        $this->returnFromLine($this->startLine());
        $this->shop('POST', '/p/line/email', ['email' => 'hanako@example.com']);
        $this->shop('POST', '/p/line/code', ['code' => $this->lastCode()])->assertRedirect();

        return $this->inside('lna', fn (): string => LineLink::query()->sole()->customer_account_id);
    }

    private function confirmByCode(): void
    {
        $this->shop('POST', '/account/confirm/send', ['next' => 'app']);
        $this->shop('POST', '/account/confirm', ['next' => 'app', 'code' => $this->lastCode()]);
    }

    /**
     * 直前の「LINE をつなぐ」が送った先の認可の画面のクエリ（セッションの値から作る）
     *
     * @return array<string, string>
     */
    private function lastAuthorize(): array
    {
        $pending = session('line_oauth');
        $this->assertIsArray($pending);

        return ['state' => $pending['state'], 'nonce' => $pending['nonce']];
    }
}
