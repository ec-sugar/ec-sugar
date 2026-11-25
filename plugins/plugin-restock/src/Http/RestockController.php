<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock\Http;

use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Party\Models\CustomerAccount;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Members\Member;
use App\Storefront\View\StorefrontView;
use EcSugar\PluginRestock\RegisterOutcome;
use EcSugar\PluginRestock\RestockRequests;
use EcSugar\PluginRestock\RestockTargets;
use EcSugar\PluginRestock\TokenOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ショップの「入荷したら知らせる」（App の画面。JavaScript を使わないフォーム）。
 * 商品の画面の買い方の口 → 売り切れの種類とメール（会員はログインのメールで、欄は無い）→ ゲストは確かめのメール → リンクの画面で「登録を確かめる」。
 * 取り消しもメールのリンクの画面から。リンクの画面は開いただけでは何も変えず、ボタン（POST）で変える（メールのリンクを先に開く仕組みで勝手に確かめない）
 */
final class RestockController
{
    private const string DONE = 'restock_done';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly RestockTargets $targets,
        private readonly RestockRequests $requests,
    ) {}

    public function show(Request $request, string $listingId): Response
    {
        $listing = self::listing($listingId);
        $soldOut = $listing === null ? [] : $this->targets->soldOut($listing->id);

        if ($listing === null || $soldOut === []) {
            return $this->view->renderApp('restock::unavailable', ['listing' => $listing]);
        }

        $old = (array) $request->session()->getOldInput();

        return $this->view->renderApp('restock::form', [
            'listing' => $listing,
            'variants' => $soldOut,
            'memberEmail' => $this->member($request)?->email,
            'old' => ['variant_id' => is_string($old['variant_id'] ?? null) ? $old['variant_id'] : null, 'email' => is_string($old['email'] ?? null) ? mb_substr($old['email'], 0, 254) : ''],
            'hours' => RestockRequests::CONFIRM_HOURS,
        ]);
    }

    public function submit(Request $request, string $listingId): RedirectResponse
    {
        $listing = self::listing($listingId);
        $back = '/p/restock/'.strtolower($listingId);

        if ($listing === null) {
            return redirect($back);
        }

        $member = $this->member($request);
        $rules = ['variant_id' => ['required', 'string', 'size:26', 'alpha_num:ascii']];

        if ($member === null) {
            // 注文の入力・会員のログインと同じ決まり（email:rfc）に、送れる形かも足す（送れないアドレスを確かめ待ちに残さない）
            $rules['email'] = ['required', 'string', 'max:254', 'email:rfc', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || filter_var(trim($value), FILTER_VALIDATE_EMAIL) === false) {
                    $fail('メールアドレスの形式が正しくありません。ご確認ください。');
                }
            }];
        }

        $data = $request->validate($rules, [], ['variant_id' => '種類', 'email' => 'メールアドレス']);
        $outcome = $this->requests->register($listing->id, strtolower((string) $data['variant_id']), $member === null ? (string) $data['email'] : null, $member, $request->ip());

        $error = match ($outcome) {
            RegisterOutcome::Available => 'この種類はいま買えます。商品ページからカートに入れてください。',
            RegisterOutcome::Unavailable => '種類を選び直してください。',
            RegisterOutcome::Throttled => 'しばらくしてから、もう一度お試しください。',
            RegisterOutcome::Full => 'いまは登録を受け付けられません。時間をおいてお試しください。',
            default => null,
        };

        if ($error !== null) {
            return redirect($back)->withInput($request->only('variant_id', 'email'))->with('cart_error', $error);
        }

        return redirect($back.'/sent')->with(self::DONE, $outcome->name);
    }

    public function sent(Request $request, string $listingId): Response|RedirectResponse
    {
        $done = $request->session()->get(self::DONE);
        $listing = self::listing($listingId);

        if (! is_string($done) || $listing === null) {
            return redirect('/p/restock/'.strtolower($listingId));
        }

        $message = match ($done) {
            RegisterOutcome::Registered->name => '登録しました。入荷したら、ログイン中のメールアドレスへ 1 回だけお知らせします。登録を取り消すためのリンクは、メールでお送りしました。',
            RegisterOutcome::Already->name => 'この種類はすでに登録済みです。入荷したら 1 回だけお知らせします。',
            default => '確認メールをお送りしました。'.RestockRequests::CONFIRM_HOURS.' 時間以内にメールのリンクを開き、「登録を確定する」を押すと、入荷した時にお知らせします。メールが届かないときは、迷惑メールのフォルダもご確認ください。',
        };

        return $this->view->renderApp('restock::done', ['listing' => $listing, 'title' => $done === RegisterOutcome::Sent->name ? 'メールをご確認ください' : '登録しました', 'message' => $message]);
    }

    public function showConfirm(Request $request, string $token): Response
    {
        return $this->token($request, $token, 'confirm', null);
    }

    public function confirm(Request $request, string $token): Response
    {
        return $this->token($request, $token, 'confirm', $this->requests->confirm($token));
    }

    public function showCancel(Request $request, string $token): Response
    {
        return $this->token($request, $token, 'cancel', null);
    }

    public function cancel(Request $request, string $token): Response
    {
        return $this->token($request, $token, 'cancel', $this->requests->cancel($token));
    }

    /**
     * リンクの画面（押す前と押した後）。URL に合い言葉があるので、次の画面へ URL を渡さず、検索にも載せない
     */
    private function token(Request $request, string $token, string $mode, ?TokenOutcome $result): Response
    {
        // 参照元を渡さない・残さない・検索に載せないは、経路の宣言（secret-link）で核がまとめて掛ける
        [$state, $row] = $this->requests->peek($token);
        $listing = null;
        $variant = '';

        if ($row !== null && $state !== TokenOutcome::Invalid) {
            $listing = Listing::query()->find($row->listing_id);
            $variant = (string) ListingVariant::query()->find($row->variant_id)?->label();
        }

        // 押せるボタン（押す前だけ）
        $action = $result === null ? match (true) {
            $mode === 'confirm' && $state === TokenOutcome::Pending => 'confirm',
            $mode === 'cancel' && in_array($state, [TokenOutcome::Pending, TokenOutcome::Waiting], true) => 'cancel',
            default => null,
        } : null;

        $message = match (true) {
            $result === TokenOutcome::Confirmed => '登録が完了しました。入荷したら、このメールアドレスへ 1 回だけお知らせします。',
            $result === TokenOutcome::CancelledNow => '登録を取り消しました。お知らせは届きません。',
            $action === 'confirm' => '下のボタンを押すと、入荷のお知らせの登録が完了します。',
            $action === 'cancel' => '下のボタンを押すと、この商品の入荷のお知らせの登録を取り消します（ほかの登録はそのままです）。',
            $state === TokenOutcome::Waiting => 'この登録はすでに完了しています。入荷したら 1 回だけお知らせします。',
            $state === TokenOutcome::Notified => '入荷のお知らせは、すでにお送りしました（この登録は終了しています）。',
            $state === TokenOutcome::Cancelled => 'この登録は、すでに取り消されています。',
            default => 'このリンクは使えません。有効期限が切れたか、新しいリンクをお送りしたため無効になっています。お手数ですが、商品ページからもう一度お申し込みください。',
        };

        $response = $this->view->renderApp('restock::token', [
            'listing' => $listing,
            'variant' => $variant,
            'action' => $action,
            'message' => $message,
            'path' => '/p/restock/'.$mode.'/'.$token,
            // 確かめの画面から取り消しへ（確かめる前・確かめた後のどちらでも）
            'cancelPath' => $mode === 'confirm' && in_array($state, [TokenOutcome::Pending, TokenOutcome::Waiting], true) ? '/p/restock/cancel/'.$token : null,
        ]);

        return $response;
    }

    /**
     * ショップに出ている商品（出ていなければ null）
     */
    private static function listing(string $listingId): ?Listing
    {
        $listingId = strtolower($listingId);

        return app(Catalog::class)->isPublished($listingId) ? Listing::query()->find($listingId) : null;
    }

    private function member(Request $request): ?CustomerAccount
    {
        $member = $request->user('customer');

        return $member instanceof Member && $member->belongsHere() ? $member->account : null;
    }
}
