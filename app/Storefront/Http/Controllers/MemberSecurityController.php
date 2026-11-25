<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Party\Services\CustomerException;
use App\Core\Party\Services\CustomerLogin;
use App\Core\Party\Services\CustomerPasskeys;
use App\Core\Party\Services\CustomerSessions;
use App\Storefront\Members\AccountLinks;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Members\MemberLogin;
use App\Storefront\Members\MemberPage;
use App\Storefront\Members\SendEmailChangedNotice;
use App\Storefront\View\StorefrontView;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * 会員の本人の再確認と退会（ADR 0044 の 3）。
 *
 * - 再確認: メールの変更・パスキー・退会の前に、その場でメールに新しいコードを送って入れてもらう（パスキーは区切り 3b で足す）。
 *   確かめた後 10 分は、この会員の印では繰り返さない。行き先は決まった名前だけ（外の URL へ戻さない）。
 *   App の画面（LINE をつなぐなど。ADR 0070）からは next=app と return=/p/<名前>/… で来て、確かめた後にその App の画面へ戻る
 *   （戻り先はこのブラウザのセッションに 1 回分だけ置き、App の経路の形〔AccountController::returnable〕でなければマイページへ）
 * - メールの変更（区切り 3b）: 再確認の後に、新しいアドレスへコードを送って持ち主を確かめ、変えたら前のアドレスへ知らせる。ほかの端末の印は取り消す
 * - 退会: 再確認の後に。会員の印を全部取り消し、届け先を消し、台帳はゲストの形に（注文の記録は残す）
 */
final class MemberSecurityController
{
    public const string ACTOR = 'storefront';

    /** 再確認の後に戻る先（決まった名前だけ） */
    private const array NEXT = ['leave' => 'storefront.account.leave', 'email' => 'storefront.account.email', 'passkeys' => 'storefront.account.passkeys', 'app' => 'storefront.account'];

    /** App の画面からの再確認の戻り先（ADR 0070。1 回分だけ） */
    public const string APP_RETURN = 'member_confirm_app_return';

    public const string NEW_EMAIL = 'member_new_email';

    public const string SENT = 'member_confirm_sent';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly MemberLogin $login,
        private readonly CustomerSessions $sessions,
        private readonly CustomerAccounts $accounts,
        private readonly CustomerPasskeys $passkeys,
    ) {}

    public function confirm(Request $request): Response
    {
        $next = self::next($request->query('next'));
        $sent = $request->session()->get(self::SENT);
        $account = $this->account($request);
        $return = $request->query('return');

        if ($next === 'app' && is_string($return) && AccountController::returnable($return)) {
            $request->session()->put(self::APP_RETURN, $return);
        }

        return MemberPage::private($this->view->renderCore('account-confirm', [
            'next' => $next,
            'email' => $account->email,
            'hasPasskeys' => $this->passkeys->list($account)->isNotEmpty(),
            'sent' => is_int($sent) && CarbonImmutable::now()->getTimestamp() - $sent <= CustomerLogin::CODE_MINUTES * 60,
            'minutes' => CustomerLogin::CODE_MINUTES,
        ]));
    }

    public function send(Request $request): RedirectResponse
    {
        $account = $this->account($request);
        $this->login->send($account->email, $request->ip());
        $request->session()->put(self::SENT, CarbonImmutable::now()->getTimestamp());

        return redirect()->route('storefront.account.confirm', ['next' => self::next($request->input('next'))]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $account = $this->account($request);
        $next = self::next($request->input('next'));
        $code = $request->input('code');
        $code = is_string($code) && mb_strlen($code) <= 16 ? (string) preg_replace('/\s+/u', '', mb_convert_kana($code, 'ns')) : '';
        try {
            $confirmed = $this->login->verify($account->email, $code);
        } catch (CustomerException) {
            // 会員はまとめられない（DB の決まり）ので来ないが、来ても確かめは通さない
            $confirmed = null;
        }

        if ($confirmed === null || $confirmed->id !== $account->id) {
            return redirect()->route('storefront.account.confirm', ['next' => $next])->with('cart_error', 'コードが違うか、期限が切れました。もう一度お試しください。');
        }

        $this->sessions->confirm(MemberCookie::token($request));
        $request->session()->forget(self::SENT);
        // 前のセッション（コードを送ったメールの控えなど）は消してから作り直す
        $request->session()->regenerate(true);

        return self::afterConfirm($request, $next);
    }

    /**
     * 再確認の後の行き先へ送る（パスキーでの再確認も同じ）。App の画面からなら、覚えた App の画面へ 1 回だけ
     */
    public static function afterConfirm(Request $request, string $next): RedirectResponse
    {
        $return = $request->session()->pull(self::APP_RETURN);

        if ($next === 'app' && is_string($return) && AccountController::returnable($return)) {
            return redirect($return);
        }

        return redirect()->route(self::nextRoute($next)[1]);
    }

    public function leaveForm(Request $request): Response|RedirectResponse
    {
        $this->account($request);

        if (! $this->sessions->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'leave']);
        }

        return MemberPage::private($this->view->renderCore('account-leave', ['appLinks' => app(AccountLinks::class)->all()]));
    }

    public function leave(Request $request): RedirectResponse
    {
        $account = $this->account($request);

        if (! $this->sessions->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'leave']);
        }

        $this->accounts->leave($account, self::ACTOR);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('storefront.home')->withCookie(MemberCookie::forget($request))->with('cart_message', '退会しました。ご利用ありがとうございました。');
    }

    /**
     * メールの変更（区切り 3b）: 再確認の後に、新しいアドレスへコードを送り、合えば変える。前のアドレスへ知らせる
     */
    public function emailForm(Request $request): Response|RedirectResponse
    {
        $account = $this->account($request);

        if (! $this->sessions->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'email']);
        }

        $old = $request->session()->getOldInput('email');

        return MemberPage::private($this->view->renderCore('account-email', [
            'current' => $account->email,
            'pending' => $this->pendingEmail($request),
            'email' => is_string($old) ? $old : '',
            'minutes' => CustomerLogin::CODE_MINUTES,
        ]));
    }

    public function emailSend(Request $request): RedirectResponse
    {
        $account = $this->account($request);

        if (! $this->sessions->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'email']);
        }

        $email = $request->input('email');
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || mb_strlen($email) > 254 || Validator::make(['email' => $email], ['email' => 'email:rfc'])->fails()) {
            return redirect()->route('storefront.account.email')->withInput(['email' => mb_substr($email, 0, 254)])->with('cart_error', 'メールアドレスを確認してください。');
        }

        if (mb_strtolower($email) === mb_strtolower($account->email)) {
            return redirect()->route('storefront.account.email')->with('cart_error', '現在のメールアドレスと同じです。');
        }

        $this->login->send($email, $request->ip());
        $request->session()->put(self::NEW_EMAIL, ['email' => $email, 'at' => CarbonImmutable::now()->getTimestamp()]);

        return redirect()->route('storefront.account.email');
    }

    public function emailVerify(Request $request): RedirectResponse
    {
        $account = $this->account($request);
        $email = $this->pendingEmail($request);

        if ($email === null || ! $this->sessions->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'email']);
        }

        $code = $request->input('code');
        $code = is_string($code) && mb_strlen($code) <= 16 ? (string) preg_replace('/\s+/u', '', mb_convert_kana($code, 'ns')) : '';

        if (! $this->login->check($email, $code)) {
            return redirect()->route('storefront.account.email')->with('cart_error', 'コードが違うか、期限が切れました。もう一度お試しください。');
        }

        $request->session()->forget(self::NEW_EMAIL);
        $previous = $account->email;

        if (! $this->accounts->changeEmail($account, $email, MemberCookie::token($request), self::ACTOR)) {
            return redirect()->route('storefront.account.email')
                ->with('cart_error', 'このメールアドレスは、すでにこのショップのご注文や会員で使われています。ひとつにまとめたい場合は、ショップへお問い合わせください。');
        }

        dispatch(SendEmailChangedNotice::for($previous, $account->id));

        return redirect()->route('storefront.account')->with('cart_message', 'メールアドレスを変更しました。変更前のアドレスにもお知らせのメールを送りました。');
    }

    /**
     * 新しいアドレス（コードを送ったもの。このブラウザのセッション、コードの期限まで）
     */
    private function pendingEmail(Request $request): ?string
    {
        $pending = $request->session()->get(self::NEW_EMAIL);

        if (! is_array($pending) || ! is_string($pending['email'] ?? null) || ! is_int($pending['at'] ?? null)
            || CarbonImmutable::now()->getTimestamp() - $pending['at'] > CustomerLogin::CODE_MINUTES * 60) {
            return null;
        }

        return $pending['email'];
    }

    private static function next(mixed $value): string
    {
        return self::nextRoute($value)[0];
    }

    /**
     * 再確認の後の行き先（決まった名前と経路だけ。パスキーでの再確認も同じ一覧を使う）
     *
     * @return array{0: string, 1: string}
     */
    public static function nextRoute(mixed $value): array
    {
        $key = is_string($value) && array_key_exists($value, self::NEXT) ? $value : 'leave';

        return [$key, self::NEXT[$key]];
    }

    private function account(Request $request): CustomerAccount
    {
        $member = $request->user('customer');

        if (! $member instanceof Member || ! $member->belongsHere()) {
            abort(401);
        }

        return $member->account;
    }
}
