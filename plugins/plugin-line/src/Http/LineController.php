<?php

declare(strict_types=1);

namespace EcSugar\PluginLine\Http;

use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerException;
use App\Core\Party\Services\CustomerLogin;
use App\Core\Party\Services\CustomerSessions;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Members\MemberLogin;
use App\Storefront\Members\MemberPage;
use App\Storefront\Members\MemberSignIn;
use App\Storefront\View\StorefrontView;
use Carbon\CarbonImmutable;
use EcSugar\PluginLine\LineLinks;
use EcSugar\PluginLine\LineLogin;
use EcSugar\PluginLine\LineSettings;
use EcSugar\PluginLine\LineWebhook;
use EcSugar\PluginLine\LinkOutcome;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

/**
 * ショップの「LINE でログイン」と、マイページの「LINE をつなぐ／外す」と、Messaging API の Webhook（ADR 0070）。
 *
 * - ログイン: LINE で確かめた利用者がつないだ会員なら、核の口（MemberSignIn::forApp）でその会員として入れる
 * - まだつないでいない LINE: LINE から来た値で既存の会員に自動でつながない（メールは求めない）。メールを入れてもらい、核のメールのコードで
 *   本人を確かめてから、そのメールの会員（無ければ作る。核の CustomerLogin::verify）につなぐ。確かめた LINE の ID は、このブラウザの
 *   セッションに 10 分だけ暗号化して置く（違うコード・核のログインの画面を開いた・ログインした、で消える）。コードのメールは「LINE をつなぐためのコード」
 * - つなぐ・外す（マイページ）: 会員だけ・本人の再確認の後だけ（パスキーを足す・消すのと同じ。ログインの手段を足す・消す操作のため）
 * - 理由は細かく言わない（「ログインできませんでした」）
 */
final class LineController
{
    public const string ACTOR = 'storefront';

    /** まだつないでいない、確かめた LINE（このブラウザのセッション。10 分。核のログインの画面を開くと核が消す） */
    public const string PENDING = 'line_pending';

    private const int PENDING_MINUTES = 10;

    private const string FAILED = 'LINE でログインできませんでした。もう一度お試しいただくか、メールアドレスでログインしてください。';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly LineLogin $line,
        private readonly LineLinks $links,
        private readonly LineSettings $settings,
    ) {}

    /**
     * LINE でログイン: LINE の認可の画面へ送る。ショップの 1 つ目のドメインでないときは、先にそちらへ移る（戻り先とセッションを同じホストに）
     */
    public function login(Request $request): RedirectResponse
    {
        if ($this->member($request) !== null) {
            return redirect()->route('storefront.account');
        }

        $callback = LineLogin::callbackUrl();

        if ($callback !== null && ! str_starts_with($callback, $request->getSchemeAndHttpHost().'/')) {
            return redirect(substr($callback, 0, -strlen('/callback')).'/login');
        }

        $url = $this->line->begin($request, 'login');

        return $url === null ? $this->toLogin(self::FAILED) : redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        [$identity, $reason] = $this->line->finish($request);

        if ($identity === null) {
            return $this->toLogin($reason === 'cancelled' ? 'LINE でのログインを中止しました。' : self::FAILED);
        }

        if ($identity->purpose === 'link') {
            return $this->finishLink($request, $identity->sub, $identity->friend, $identity->account);
        }

        $link = $this->links->forLine($identity->sub);

        if ($link !== null) {
            // ログインの時に聞いた友だちの印で直す（Webhook を受けていなくても、ログインのたびに今に合う）
            $this->links->setFriend($identity->sub, $identity->friend, CarbonImmutable::now());

            return app(MemberSignIn::class)->forApp($request, LineSettings::PLUGIN, $link->customer_account_id) ?? $this->toLogin(self::FAILED);
        }

        // まだつないでいない LINE: メールのコードで本人を確かめてから、会員につなぐ（LINE から来た値では自動でつながない）
        $request->session()->put(self::PENDING, [
            'sub' => Crypt::encryptString($identity->sub),
            'friend' => $identity->friend,
            'at' => CarbonImmutable::now()->getTimestamp(),
        ]);

        return redirect('/p/line/email');
    }

    public function email(Request $request): Response|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return $this->toLogin('もう一度「LINE でログイン」からお進みください。');
        }

        $old = $request->session()->getOldInput('email');

        return MemberPage::private($this->view->renderApp('line::email', ['email' => is_string($old) ? $old : '']));
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return $this->toLogin('もう一度「LINE でログイン」からお進みください。');
        }

        $email = $request->input('email');
        $email = is_string($email) ? trim($email) : '';

        // 核のログインと同じ決まり（email:rfc・254 文字まで）
        if ($email === '' || mb_strlen($email) > 254 || Validator::make(['email' => $email], ['email' => 'email:rfc'])->fails()) {
            return redirect('/p/line/email')->withInput(['email' => mb_substr($email, 0, 254)])->with('cart_error', 'メールアドレスの形式が正しくありません。');
        }

        // 送る数の上限は核のログインと同じ（メール × 接続元・接続元・メールごと）。メールの有る無しで答えを変えない
        app(MemberLogin::class)->send($email, $request->ip(), 'LINE');
        // LINE の ID は暗号のまま持ち回る（セッションに平文で置かない）
        $raw = (array) $request->session()->get(self::PENDING);
        $request->session()->put(self::PENDING, ['sub' => $raw['sub'], 'friend' => $pending['friend'], 'at' => $pending['at'], 'email' => $email, 'sent' => CarbonImmutable::now()->getTimestamp()]);

        return redirect('/p/line/code');
    }

    public function code(Request $request): Response|RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null || ! is_string($pending['email'] ?? null)) {
            return $this->toLogin('もう一度「LINE でログイン」からお進みください。');
        }

        return MemberPage::private($this->view->renderApp('line::code', ['email' => $pending['email'], 'minutes' => CustomerLogin::CODE_MINUTES]));
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null || ! is_string($pending['email'] ?? null) || ! is_int($pending['sent'] ?? null)
            || CarbonImmutable::now()->getTimestamp() - $pending['sent'] > CustomerLogin::CODE_MINUTES * 60) {
            $request->session()->forget(self::PENDING);

            return $this->toLogin('もう一度「LINE でログイン」からお進みください。');
        }

        // 全角の数字と間の空白は受ける（核のログインと同じ）
        $code = $request->input('code');
        $code = is_string($code) && mb_strlen($code) <= 16 ? (string) preg_replace('/\s+/u', '', mb_convert_kana($code, 'ns')) : '';

        try {
            $account = app(MemberLogin::class)->verify($pending['email'], $code);
        } catch (CustomerException $e) {
            $request->session()->forget(self::PENDING);

            return $this->toLogin($e->getMessage());
        }

        // 違うコードなら、確かめた LINE の控えを消して最初から（当てずっぽうを LINE の 1 回の確かめで 1 回に）
        if ($account === null) {
            $request->session()->forget(self::PENDING);

            return $this->toLogin('コードが違うか、有効期限が切れています。もう一度「LINE でログイン」からお進みください。');
        }

        $request->session()->forget(self::PENDING);
        $outcome = $this->links->link($account, (string) $pending['sub'], (bool) $pending['friend'], self::ACTOR);
        // メールのコードで本人を確かめたので、つなげなくても会員としては入れる（ふつうのコードのログインと同じ）
        $response = app(MemberSignIn::class)->start($request, $account, 'code');

        return match ($outcome) {
            LinkOutcome::Linked, LinkOutcome::Already => $response->with('cart_message', 'LINE と連携しました。次からは「LINE でログイン」でログインできます。'),
            LinkOutcome::AccountTaken => $response->with('cart_error', 'この会員アカウントは、すでに別の LINE と連携しています。連携する LINE を替えるときは、マイページの「LINE 連携」で解除してから、連携し直してください。'),
            LinkOutcome::LineTaken => $response->with('cart_error', 'この LINE は、すでに別の会員アカウントと連携しているため、連携しませんでした。'),
            LinkOutcome::NotMember => $response,
        };
    }

    /**
     * マイページの「LINE 連携」
     */
    public function account(Request $request): Response|RedirectResponse
    {
        $member = $this->member($request);

        if ($member === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/line/account']);
        }

        $link = $this->links->forAccount($member->id);

        return MemberPage::private($this->view->renderApp('line::account', [
            'linked' => $link !== null,
            'friend' => $link !== null && $link->friend,
            'ready' => $this->settings->login() !== null,
        ]));
    }

    public function connect(Request $request): RedirectResponse
    {
        $member = $this->member($request);

        if ($member === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/line/account']);
        }

        if (! app(CustomerSessions::class)->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'app', 'return' => '/p/line/account']);
        }

        if ($this->links->forAccount($member->id) !== null) {
            return redirect('/p/line/account')->with('cart_error', 'すでに LINE と連携しています。');
        }

        $url = $this->line->begin($request, 'link', $member->id);

        return $url === null ? redirect('/p/line/account')->with('cart_error', 'いまは LINE と連携できません。') : redirect()->away($url);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $member = $this->member($request);

        if ($member === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/line/account']);
        }

        if (! app(CustomerSessions::class)->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'app', 'return' => '/p/line/account']);
        }

        $this->links->unlink($member, self::ACTOR);

        return redirect('/p/line/account')->with('cart_message', 'LINE 連携を解除しました。LINE でのログインと、LINE でのお知らせは使えなくなります。');
    }

    /**
     * Messaging API の Webhook。署名が合わなければ 400（中身は言わない）。合えば、読めても読めなくても 200（LINE に送り直させない）
     */
    public function webhook(Request $request): Response
    {
        $secret = $this->settings->webhookSecret();
        $body = $request->getContent();

        if ($secret === null) {
            return response('', 404);
        }

        if (strlen($body) > LineWebhook::MAX_BYTES || ! LineWebhook::signatureMatches($body, $request->header('x-line-signature'), $secret)) {
            return response('', 400);
        }

        app(LineWebhook::class)->apply($body);

        return response('', 200);
    }

    /**
     * ログインした会員が LINE をつなぐ（LINE から戻った後）。始めた会員と今の会員が同じ時だけ
     */
    private function finishLink(Request $request, string $sub, bool $friend, ?string $accountId): RedirectResponse
    {
        $member = $this->member($request);

        if ($member === null || $accountId === null || ! hash_equals($accountId, $member->id)) {
            return $this->toLogin(self::FAILED);
        }

        $message = match ($this->links->link($member, $sub, $friend, self::ACTOR)) {
            LinkOutcome::Linked, LinkOutcome::Already => ['cart_message', 'LINE と連携しました。次からは「LINE でログイン」でログインできます。'],
            LinkOutcome::LineTaken => ['cart_error', 'この LINE は、すでに別の会員アカウントと連携しています。'],
            LinkOutcome::AccountTaken => ['cart_error', 'すでに LINE と連携しています。'],
            LinkOutcome::NotMember => ['cart_error', 'いまは LINE と連携できません。'],
        };

        return redirect('/p/line/account')->with($message[0], $message[1]);
    }

    /**
     * まだつないでいない、確かめた LINE（このブラウザのセッション。10 分）
     *
     * @return array{sub: string, friend: bool, at: int, email?: string, sent?: int}|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || ! is_string($pending['sub'] ?? null) || ! is_int($pending['at'] ?? null)
            || CarbonImmutable::now()->getTimestamp() - $pending['at'] > self::PENDING_MINUTES * 60) {
            return null;
        }

        try {
            $sub = Crypt::decryptString($pending['sub']);
        } catch (DecryptException) {
            return null;
        }

        $out = ['sub' => $sub, 'friend' => ($pending['friend'] ?? false) === true, 'at' => $pending['at']];

        if (is_string($pending['email'] ?? null)) {
            $out['email'] = $pending['email'];
        }

        if (is_int($pending['sent'] ?? null)) {
            $out['sent'] = $pending['sent'];
        }

        return $out;
    }

    private function member(Request $request): ?CustomerAccount
    {
        $member = $request->user('customer');

        return $member instanceof Member && $member->belongsHere() ? $member->account : null;
    }

    private function toLogin(string $message): RedirectResponse
    {
        return redirect()->route('storefront.account.login')->with('cart_error', $message);
    }
}
