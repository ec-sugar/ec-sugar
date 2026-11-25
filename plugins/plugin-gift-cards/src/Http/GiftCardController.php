<?php

declare(strict_types=1);

namespace EcSugar\PluginGiftCards\Http;

use App\Core\Money\Money;
use App\Core\Payment\Contracts\PaymentCredentialStore;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentService;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Members\Member;
use App\Storefront\View\StorefrontView;
use EcSugar\PluginGiftCards\GiftCardPurchases;
use EcSugar\PluginGiftCards\GiftCardSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * ギフトカードを買う画面（ADR 0076）。額面を選び、買う人の名前とメール・贈る相手のメール（任意）とメッセージ（任意）→ 最後の確認（特商法の表示）→ カードのお支払い。
 * 会員でもゲストでも買える（会員ならメールに会員のメールを入れておく）。入れた内容は確定までこのブラウザのセッション（暗号化）に置く。
 * コードは払いが確かになってからメールで届く（この画面には出さない）
 */
final class GiftCardController
{
    private const string SESSION = 'gift_card_request';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly GiftCardSettings $settings,
        private readonly PaymentService $payments,
        private readonly PaymentCredentialStore $gateways,
    ) {}

    /**
     * 店の決済代行がテストの鍵か（テストの支払いで売ったカードは、本番の鍵に替えると使えない。売る画面に「いまはテストの支払いです」を出す。ADR 0076）
     */
    private function testMode(): bool
    {
        return $this->gateways->credentials()?->mode() === 'test';
    }

    public function show(Request $request): Response
    {
        if (! $this->payments->available()) {
            return $this->view->renderApp('gift-cards::unavailable');
        }

        $member = $this->memberEmail($request);
        $saved = (array) $request->session()->get(self::SESSION, []);
        $old = (array) $request->session()->getOldInput();

        return $this->view->renderApp('gift-cards::show', [
            'denominations' => array_map(static fn (int $a): array => ['amount' => $a, 'label' => Money::format($a)], $this->settings->denominations()),
            'validDays' => $this->settings->validDays(),
            'values' => $old !== [] ? $old : ($saved !== [] ? $saved : ['email' => $member]),
            'testMode' => $this->testMode(),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $denominations = $this->settings->denominations();
        $data = $request->validate([
            'amount' => ['required', 'integer', 'in:'.implode(',', $denominations)],
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'recipient_email' => ['nullable', 'string', 'max:254', 'email:rfc'],
            'message' => ['nullable', 'string', 'max:'.GiftCardPurchases::MESSAGE_MAX, 'not_regex:/[\x00-\x09\x0B-\x1F\x7F]/u'],
        ], [], ['amount' => '額面', 'name' => 'お名前', 'email' => 'メールアドレス', 'recipient_email' => '贈る相手のメールアドレス', 'message' => 'メッセージ']);

        $message = isset($data['message']) ? trim(str_replace(["\r\n", "\r"], "\n", (string) $data['message'])) : '';

        $request->session()->put(self::SESSION, [
            'amount' => (int) $data['amount'],
            'name' => trim((string) $data['name']),
            'email' => (string) $data['email'],
            'recipient_email' => ($data['recipient_email'] ?? null) === null || $data['recipient_email'] === '' ? null : (string) $data['recipient_email'],
            'message' => $message === '' ? null : $message,
            'order_key' => Str::random(43),
        ]);

        return redirect('/p/gift-cards/confirm');
    }

    public function confirm(Request $request): Response|RedirectResponse
    {
        $saved = $this->saved($request);

        if ($saved === null) {
            return redirect('/p/gift-cards');
        }

        return $this->view->renderApp('gift-cards::confirm', [
            'request' => $saved,
            'amountLabel' => Money::format($saved['amount']),
            'validDays' => $this->settings->validDays(),
            'testMode' => $this->testMode(),
        ]);
    }

    public function place(Request $request, GiftCardPurchases $purchases): RedirectResponse
    {
        $saved = $this->saved($request);

        if ($saved === null) {
            return redirect('/p/gift-cards');
        }

        // 準備中のショップで本番の鍵なら、注文を作らない（試せるのはテストの鍵だけ）
        if ($this->payments->liveBeforeOpening()) {
            return redirect('/p/gift-cards/confirm')->with('cart_error', PaymentService::NOT_OPEN);
        }

        $member = $request->user('customer');
        $account = $member instanceof Member && $member->belongsHere() ? $member->account : null;

        try {
            $transaction = $purchases->place($saved['amount'], $saved['name'], $saved['email'], $saved['recipient_email'], $saved['message'], $account, $saved['order_key'], $request->ip());
            $base = $request->getSchemeAndHttpHost();
            $session = $this->payments->startCheckout($transaction, $base.'/p/gift-cards/done', $base.'/p/gift-cards/confirm', GiftCardPurchases::ACTOR);
        } catch (OrderException|PaymentUnavailableException $e) {
            return redirect('/p/gift-cards/confirm')->with('cart_error', $e->getMessage());
        }

        return redirect()->away($session->url, 303);
    }

    public function done(Request $request): Response
    {
        $request->session()->forget(self::SESSION);

        return $this->view->renderApp('gift-cards::done');
    }

    /**
     * セッションに置いた内容（額面が今も選べるか確かめ直す）
     *
     * @return array{amount: int, name: string, email: string, recipient_email: string|null, message: string|null, order_key: string}|null
     */
    private function saved(Request $request): ?array
    {
        $saved = $request->session()->get(self::SESSION);

        if (! is_array($saved) || ! is_int($saved['amount'] ?? null) || ! in_array($saved['amount'], $this->settings->denominations(), true)
            || ! is_string($saved['name'] ?? null) || ! is_string($saved['email'] ?? null) || ! is_string($saved['order_key'] ?? null)) {
            return null;
        }

        return [
            'amount' => $saved['amount'],
            'name' => $saved['name'],
            'email' => $saved['email'],
            'recipient_email' => is_string($saved['recipient_email'] ?? null) ? $saved['recipient_email'] : null,
            'message' => is_string($saved['message'] ?? null) ? $saved['message'] : null,
            'order_key' => $saved['order_key'],
        ];
    }

    private function memberEmail(Request $request): ?string
    {
        $member = $request->user('customer');

        return $member instanceof Member && $member->belongsHere() ? $member->account->email : null;
    }
}
