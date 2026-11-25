<?php

declare(strict_types=1);

namespace EcSugar\PluginGift\Http;

use App\Core\Order\Gifts;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Access\SecretLinks;
use App\Storefront\Checkout\LastOrder;
use App\Storefront\Http\Middleware\StorefrontSecurityHeaders;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberPage;
use App\Storefront\View\StorefrontView;
use EcSugar\PluginGift\GiftCancellation;
use EcSugar\PluginGift\GiftOrders;
use EcSugar\PluginGift\GiftRefused;
use EcSugar\PluginGift\GiftStatus;
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 買う人の贈り物の画面（ADR 0055 の 3）: 受け取りのリンクを写す・LINE で送る・作り直す（前のリンクは使えなくなる）・受け取られる前なら取り消す（全額を返金）。
 * 開けるのは買う人だけ: このセッションで今買った注文（注文を受け付けた画面から）・メールとご注文の状況の署名つきリンク（開いた後はこのセッションで）・
 * 注文した会員。ほかの注文の番号では開けない（見つからないと同じ 404）
 */
final class GiftOrderController
{
    /** 署名つきリンクで開いた注文を、このセッションで覚える数 */
    private const string SESSION = 'gift_orders';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly GiftOrders $gifts,
        private readonly Gifts $core,
        private readonly GiftCancellation $cancellation,
    ) {}

    public function show(Request $request, string $orderId): Response
    {
        $gift = $this->authorize($request, $orderId);
        $share = $this->core->share($gift->transaction_id, private: true);
        abort_if($share === null, 404);

        // 署名つきの URL と受け取りのリンクを次の画面や読み込み先へ渡さない・検索に載せないは、経路の宣言（secret-link）で核が掛ける
        return MemberPage::private($this->view->renderApp('gift::order', [
            'gift' => $share,
            'orderId' => $gift->transaction_id,
            'number' => strtoupper($gift->transaction_id),
            'sender' => $gift->sender,
            'message' => $gift->message,
            'renewable' => $gift->status === 'waiting' && GiftStatus::of($gift) === 'waiting',
            'cancellable' => $gift->status === 'waiting' && GiftStatus::of($gift) === 'waiting',
            'notice' => session('gift_message'),
            'error' => session('gift_error'),
        ]));
    }

    /**
     * リンクを作り直す（前のリンクは使えなくなる）
     */
    public function renew(Request $request, string $orderId): RedirectResponse
    {
        $gift = $this->authorize($request, $orderId);

        try {
            $this->gifts->renew($gift->id, 'storefront');
        } catch (GiftRefused $e) {
            return redirect('/p/gift/orders/'.$gift->transaction_id)->with('gift_error', $e->getMessage());
        }

        return redirect('/p/gift/orders/'.$gift->transaction_id)->with('gift_message', 'リンクを作り直しました。前のリンクは使えません。新しいリンクを送ってください。');
    }

    /**
     * 取り消しの確かめ（受け取られる前だけ。全額を返金して在庫を戻す）
     */
    public function confirmCancel(Request $request, string $orderId): Response
    {
        $gift = $this->authorize($request, $orderId);
        abort_if(GiftStatus::of($gift) !== 'waiting', 404);

        $response = MemberPage::private($this->view->renderApp('gift::cancel', ['orderId' => $gift->transaction_id, 'number' => strtoupper($gift->transaction_id)]));
        $response->headers->set('X-Robots-Tag', StorefrontView::NO_INDEX);
        StorefrontSecurityHeaders::withoutReferrer($request);

        return $response;
    }

    /**
     * 取り消して全額を返金する（GiftCancellation。返金できなければ止めて、お店が確かめる）
     */
    public function cancel(Request $request, string $orderId): RedirectResponse
    {
        $gift = $this->authorize($request, $orderId);

        try {
            $outcome = $this->cancellation->cancel($gift->id, 'storefront');
        } catch (GiftRefused $e) {
            return redirect('/p/gift/orders/'.$gift->transaction_id)->with('gift_error', $e->getMessage());
        }

        return redirect('/p/gift/orders/'.$gift->transaction_id)->with($outcome === 'stopped' ? 'gift_error' : 'gift_message', $outcome === 'stopped'
            ? '返金の手続きができなかったため、取り消しを保留しています。お店で確認してご連絡します（受け取りのリンクは使えなくなりました）。'
            : '贈り物を取り消し、全額の返金の手続きをしました。受け取りのリンクは使えなくなりました。');
    }

    /**
     * マイページの贈り物（会員だけ。自分の注文の贈り物）
     */
    public function account(Request $request): Response|RedirectResponse
    {
        $member = $request->user('customer');

        if (! $member instanceof Member || ! $member->belongsHere()) {
            return redirect()->route('storefront.account.login', ['return' => '/p/gift/account']);
        }

        $ids = Transaction::query()->where('customer_account_id', $member->account->id)->pluck('id');
        $items = GiftOrder::query()->whereIn('transaction_id', $ids)->orderByDesc('created_at')->limit(50)->get()->map(static fn (GiftOrder $gift): array => [
            'orderId' => $gift->transaction_id,
            'number' => strtoupper($gift->transaction_id),
            'placedAt' => $gift->getAttribute('created_at')?->timezone('Asia/Tokyo')->format('Y/m/d') ?? '',
            'statusLabel' => GiftStatus::label(GiftStatus::of($gift)),
        ])->all();

        return MemberPage::private($this->view->renderApp('gift::account', ['items' => $items]));
    }

    /**
     * 買う人か確かめて、その注文の贈り物を返す（違えば 404。あるかどうかを教えない）
     */
    private function authorize(Request $request, string $orderId): GiftOrder
    {
        $id = strtolower($orderId);
        $remembered = (array) $request->session()->get(self::SESSION, []);
        $granted = in_array($id, $remembered, true) || LastOrder::id($request) === $id;

        if (! $granted && SecretLinks::valid($request)) {
            $granted = true;
            $request->session()->put(self::SESSION, array_slice(array_values(array_unique([$id, ...$remembered])), 0, 10));
        }

        $gift = GiftOrder::query()->where('transaction_id', $id)->first();

        if (! $granted && $gift !== null) {
            $member = $request->user('customer');
            $owner = Transaction::query()->whereKey($id)->value('customer_account_id');
            $granted = $member instanceof Member && $member->belongsHere() && is_string($owner) && $owner === $member->account->id;
        }

        abort_if($gift === null || ! $granted, 404);

        return $gift;
    }
}
