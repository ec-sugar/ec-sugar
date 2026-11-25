<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Crypto\BlindIndex;
use App\Core\Party\Models\Party;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\SecretLinks;
use App\Storefront\Orders\OrderAccess;
use App\Storefront\Orders\OrderReader;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ご注文の確認（会員が無い間）。注文番号とメールアドレスで照会するか、確認のメールの期限つきの署名つきリンクで開く。
 * 見つからない・メールが違う・ショップの注文でない、はどれも同じ文で断る（あるかどうかを教えない）。
 * 総当たりは接続元ごと（経路の throttle）と、注文番号ごと（1 時間に 10 回）で止める
 */
final class OrderController
{
    // 照会を通した注文の印（App も同じ印を読む。OrderAccess）
    private const string VIEWABLE = OrderAccess::VIEWABLE;

    private const int PER_ORDER_PER_HOUR = 10;

    public function __construct(
        private readonly StorefrontView $view,
        private readonly OrderReader $orders,
    ) {}

    public function lookup(Request $request): Response
    {
        $old = $request->session()->getOldInput('order_number');

        return $this->view->render('order-lookup', ['orderNumber' => is_string($old) ? $old : '']);
    }

    public function find(Request $request): RedirectResponse
    {
        $number = $request->input('order_number');
        $email = $request->input('email');
        $refuse = static fn (string $message): RedirectResponse => redirect()->route('storefront.orders.lookup')
            ->withInput($request->only('order_number'))->with('cart_error', $message);

        if (! is_string($number) || ! is_string($email) || mb_strlen($number) > 64 || mb_strlen($email) > 254) {
            return $refuse('ご注文番号とメールアドレスを入力してください。');
        }

        // 注文番号ごとの回数（先に数えてから比べる。同時に送っても上限を越えない）
        if (! Throttle::allow(Throttle::shopKey('order-lookup', strtolower(trim($number))), self::PER_ORDER_PER_HOUR, 3600)) {
            return $refuse('確認の回数が多すぎます。しばらく時間をおいてから、もう一度お試しください。');
        }

        $transaction = $this->orders->find($number);
        $index = $transaction?->customer_id === null ? null : Party::query()->whereKey($transaction->customer_id)->value('email_index');

        if ($transaction === null || ! is_string($index) || ! hash_equals($index, BlindIndex::of('email', $email))) {
            return $refuse('ご注文番号またはメールアドレスが正しくありません。ご注文確認メールをご確認ください。');
        }

        $viewable = array_slice(array_values(array_unique([$transaction->id, ...(array) $request->session()->get(self::VIEWABLE, [])])), 0, 10);
        $request->session()->put(self::VIEWABLE, $viewable);
        $request->session()->regenerate();

        return redirect()->route('storefront.orders.show', ['orderId' => $transaction->id]);
    }

    /**
     * 注文の様子。確認のメールの署名つきリンク（期限つき）か、このセッションで照会した注文だけ
     */
    public function show(Request $request, string $orderId): Response|RedirectResponse
    {
        // 確認のメールの署名つきリンク（30 日の閲覧の資格）を参照元に残さないのは、経路の宣言（secret-link）で核が掛ける。期限切れで戻す応答も同じ
        $transaction = $this->orders->find($orderId);
        $granted = SecretLinks::valid($request) || in_array(strtolower($orderId), (array) $request->session()->get(self::VIEWABLE, []), true);

        if ($transaction === null || ! $granted) {
            return redirect()->route('storefront.orders.lookup')->with('cart_error', $request->has('signature')
                ? 'リンクの期限が切れました。ご注文番号とメールアドレスで確認してください。'
                : 'ご注文番号とメールアドレスで確認してください。');
        }

        // App のリンク（レビューを書く・領収書など）は、注文を見てよいと確かめたこの画面でだけ付ける
        return $this->view->render('order', ['order' => $this->orders->readWithLinks($transaction)]);
    }
}
