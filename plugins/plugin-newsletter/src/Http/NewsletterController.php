<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter\Http;

use App\Core\Party\Models\CustomerAccount;
use App\Core\Plugin\Services\PluginSettings;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberPage;
use App\Storefront\View\StorefrontView;
use EcSugar\PluginNewsletter\NewsletterSubscribers;
use EcSugar\PluginNewsletter\SubscribeOutcome;
use EcSugar\PluginNewsletter\SubscriberStatus;
use EcSugar\PluginNewsletter\TokenOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ショップの「お知らせのメール」の画面（App の画面。JavaScript を使わないフォーム）。
 * 受付（/p/newsletter。ゲストはメールを入れて確かめのメール、会員はボタンだけ）・確かめのリンク・配信の停止のリンク・マイページの切り替え。
 * リンクの画面は開いただけでは何も変えず、ボタン（POST）で変える（メールのリンクを先に開く仕組みで勝手に確かめ・止めない）。
 * 配信の停止の POST は、メールのソフトのワンクリック（RFC 8058。CSRF の合い言葉を持たない）も受ける
 */
final class NewsletterController
{
    private const string DONE = 'newsletter_done';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly NewsletterSubscribers $subscribers,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $member = $this->member($request);

        if ($member !== null) {
            return $this->account($request);
        }

        $old = (array) $request->session()->getOldInput();

        return $this->view->renderApp('newsletter::form', [
            'message' => $this->formMessage(),
            'email' => is_string($old['email'] ?? null) ? mb_substr($old['email'], 0, 254) : '',
            'hours' => NewsletterSubscribers::CONFIRM_HOURS,
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $member = $this->member($request);

        if ($member !== null) {
            return $this->toggle($request);
        }

        // 注文の入力・会員のログインと同じ決まり（email:rfc）に、送れる形かも足す（送れないアドレスを確かめ待ちに残さない）
        $data = $request->validate(['email' => ['required', 'string', 'max:254', 'email:rfc', static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || filter_var(trim($value), FILTER_VALIDATE_EMAIL) === false) {
                $fail('メールアドレスの形式が正しくありません。ご確認ください。');
            }
        }]], [], ['email' => 'メールアドレス']);
        $outcome = $this->subscribers->subscribe((string) $data['email'], null, 'form', $request->ip());

        if ($outcome === SubscribeOutcome::Full) {
            return redirect('/p/newsletter')->withInput($request->only('email'))->with('cart_error', 'ただいま登録を受け付けられません。時間をおいてお試しください。');
        }

        return redirect('/p/newsletter/sent')->with(self::DONE, $outcome->name);
    }

    public function sent(Request $request): Response|RedirectResponse
    {
        $done = $request->session()->get(self::DONE);

        if (! is_string($done)) {
            return redirect('/p/newsletter');
        }

        return $this->view->renderApp('newsletter::done', [
            'title' => 'メールをご確認ください',
            'message' => '登録の確認メールをお送りしました。'.NewsletterSubscribers::CONFIRM_HOURS.' 時間以内にメールのリンクを開き、「配信の登録を確定する」を押すと、お知らせが届くようになります。メールが届かないときは、迷惑メールのフォルダもご確認ください。',
        ]);
    }

    /**
     * マイページの「お知らせのメール」（会員だけ）
     */
    public function account(Request $request): Response|RedirectResponse
    {
        $member = $this->member($request);

        if ($member === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/newsletter/account']);
        }

        return MemberPage::private($this->view->renderApp('newsletter::account', [
            'email' => $member->email,
            'active' => $this->subscribers->memberStatus($member) === SubscriberStatus::Active,
            'message' => $this->formMessage(),
        ]));
    }

    /**
     * マイページの切り替え（受け取る・止める）
     */
    public function toggle(Request $request): RedirectResponse
    {
        $member = $this->member($request);

        if ($member === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/newsletter/account']);
        }

        $data = $request->validate(['action' => ['required', 'string', 'in:subscribe,unsubscribe']]);

        if ($data['action'] === 'unsubscribe') {
            $this->subscribers->unsubscribeMember($member);

            return redirect('/p/newsletter/account')->with('cart_message', 'お知らせのメールの配信を停止しました。');
        }

        $outcome = $this->subscribers->subscribe(null, $member, 'account', $request->ip());

        return match ($outcome) {
            SubscribeOutcome::Throttled => redirect('/p/newsletter/account')->with('cart_error', 'しばらくしてから、もう一度お試しください。'),
            SubscribeOutcome::Full => redirect('/p/newsletter/account')->with('cart_error', 'ただいま登録を受け付けられません。時間をおいてお試しください。'),
            default => redirect('/p/newsletter/account')->with('cart_message', 'お知らせのメールの配信を登録しました。'),
        };
    }

    public function showConfirm(Request $request, string $token): Response
    {
        return $this->token($request, $token, 'confirm', null);
    }

    public function confirm(Request $request, string $token): Response
    {
        return $this->token($request, $token, 'confirm', $this->subscribers->confirm($token));
    }

    public function showUnsubscribe(Request $request, string $token): Response
    {
        return $this->token($request, $token, 'unsubscribe', null);
    }

    /**
     * 配信を止める。画面のボタンと、メールのソフトのワンクリック（本文が List-Unsubscribe=One-Click）の両方
     */
    public function unsubscribe(Request $request, string $token): Response
    {
        $via = $request->input('List-Unsubscribe') === 'One-Click' ? 'one_click' : 'link';

        return $this->token($request, $token, 'unsubscribe', $this->subscribers->unsubscribe($token, $via));
    }

    /**
     * リンクの画面（押す前と押した後）。URL に合い言葉があるので、次の画面へ URL を渡さず、検索にも載せず、残さない
     */
    private function token(Request $request, string $token, string $mode, ?TokenOutcome $result): Response
    {
        // 参照元を渡さない・残さない・検索に載せないは、経路の宣言（secret-link）で核がまとめて掛ける
        $state = $result ?? ($mode === 'confirm' ? $this->subscribers->peekConfirm($token) : $this->subscribers->peekUnsubscribe($token));

        // 押せるボタン（押す前だけ）
        $action = $result === null ? match (true) {
            $mode === 'confirm' && $state === TokenOutcome::Pending => 'confirm',
            $mode === 'unsubscribe' && $state === TokenOutcome::Active => 'unsubscribe',
            default => null,
        } : null;

        $message = match (true) {
            $state === TokenOutcome::Confirmed => '登録が完了しました。お知らせのメールが届くようになりました。配信は、届いたメールの最後にあるリンクからいつでも停止できます。',
            $state === TokenOutcome::StoppedNow => 'お知らせのメールの配信を停止しました。今後は届きません。',
            $action === 'confirm' => '下のボタンを押すと登録が完了し、お知らせのメールが届くようになります。',
            $action === 'unsubscribe' => '下のボタンを押すと、お知らせのメールの配信を停止します。',
            $mode === 'confirm' && $state === TokenOutcome::Active => '登録はすでに完了しています。お知らせのメールが届きます。',
            $state === TokenOutcome::Stopped => 'お知らせのメールの配信は、すでに停止しています。今後は届きません。',
            default => 'このリンクは使えません。有効期限が切れたか、新しいリンクをお送りしたため無効になっています。',
        };

        $response = $this->view->renderApp('newsletter::token', [
            'title' => $mode === 'confirm' ? 'お知らせのメールの登録' : 'お知らせのメールの配信停止',
            'action' => $action,
            'message' => $message,
            'path' => '/p/newsletter/'.$mode.'/'.$token,
        ]);

        return $response;
    }

    private function formMessage(): ?string
    {
        $message = app(PluginSettings::class)->publicValues('newsletter')['form_message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    private function member(Request $request): ?CustomerAccount
    {
        $member = $request->user('customer');

        return $member instanceof Member && $member->belongsHere() ? $member->account : null;
    }
}
