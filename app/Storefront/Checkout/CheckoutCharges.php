<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Order\OrderCharges;
use App\Core\Plugin\OrderCharge\ChargeCart;
use App\Core\Plugin\OrderCharge\ChargeForm;
use App\Core\Plugin\OrderCharge\ChargeQuote;
use App\Core\Plugin\OrderCharge\ChargeRejected;
use App\Storefront\Cart\CartGiftCards;
use App\Storefront\Cart\CartPoints;
use App\Storefront\Cart\CartView;
use Illuminate\Validation\ValidationException;

/**
 * 注文の入力・確かめの画面・確定で、App の料金（ラッピング・のしなど。ADR 0078）をカートに足す（ショップの側）。
 * 3 つとも同じ決め方なので、確かめの画面の指紋がずれない: 今買える行から App に渡すカートを作り、入れた値を見積もり、
 * 答えの行をカートの合計に足して、ギフトカードを料金を含めた合計に充て直す（代引き手数料はこの後に足す）
 */
final class CheckoutCharges
{
    public const string CHANGED = 'お選びの内容の一部が、現在ご利用いただけません。選び直してください。';

    /** 料金を足した後のお支払いが 1〜49 円になる時の理由（Stripe の円の最低額。ポイントの決まり CartPoints::BELOW_MINIMUM と同じ考え方） */
    public const string BELOW_MINIMUM = 'お選びの料金を加えると、お支払い額が 1〜49 円になります（お支払いは 50 円以上が必要です）。使うポイントを減らして、お支払い額が 50 円以上になるようにしてください。';

    public function __construct(
        private readonly OrderCharges $charges,
        private readonly CartGiftCards $giftCards,
    ) {}

    /**
     * App に渡すカート（今買える行の商品と数。値段とお客さんの情報は渡さない）
     */
    public static function cartOf(CartView $view, ?CheckoutInput $input): ChargeCart
    {
        $items = [];

        foreach ($view->lines as $line) {
            if ($line['purchasable']) {
                $items[] = ['listingId' => $line['listingId'], 'title' => $line['title'], 'quantity' => $line['quantity']];
            }
        }

        return new ChargeCart($items, $input?->gift !== null);
    }

    /**
     * 注文の入力に出す欄（使っていない店・選べるものが無ければ null）
     */
    public function form(CartView $view, ?CheckoutInput $input): ?ChargeForm
    {
        return $this->charges->form(self::cartOf($view, $input));
    }

    /**
     * 入れた値を、今出す欄の鍵だけに絞る。欄を出さない（使わない店・選べる商品が無い）なら全部外す（送られてきても使わない）
     */
    public function settle(CartView $view, CheckoutInput $input): CheckoutInput
    {
        if ($input->charges === []) {
            return $input;
        }

        $form = $this->form($view, $input);

        return $input->withCharges($form === null ? [] : array_intersect_key($input->charges, array_flip($form->keys())));
    }

    /**
     * 絞った後に、選んでいた値（空でない値）が無くなったか
     */
    public static function lostChoice(CheckoutInput $before, CheckoutInput $after): bool
    {
        return array_diff_key(array_filter($before->charges, static fn (string $v): bool => $v !== ''), $after->charges) !== [];
    }

    /**
     * 入れた値を見積もって、料金の行を足したカートと見積もりを返す。選べない値は ChargeRejected
     *
     * @return array{0: CartView, 1: ChargeQuote}
     *
     * @throws ChargeRejected
     */
    public function apply(CartView $view, CheckoutInput $input): array
    {
        $quote = $this->charges->quote(self::cartOf($view, $input), $input->charges);

        if ($quote->isEmpty()) {
            return [$view, $quote];
        }

        // ギフトカード（ADR 0076）は料金を含めた合計に充て直す（代引き手数料の前の合計。手数料は残りのお支払いに入る）
        $giftCard = $this->giftCards->apply($view->giftCard->id, $view->total() + $quote->total());
        $charged = $view->withCharges($quote->lines, $giftCard);

        // ポイントは料金の前の合計で決まる（0 円か 50 円以上が残る）ので、ポイントで 0 円にした注文に小さな料金を足すと、お支払いが 1〜49 円になりうる。
        // 決済代行で払えない額なので断る（入力・確かめの画面・確定の 3 か所ともここを通る）。ギフトカードはこの額まで充てて 50 円を残す決まりがあるので、ここには来ない
        if ($charged->due() >= 1 && $charged->due() < CartPoints::MINIMUM_CHARGE) {
            throw new ChargeRejected(['_form' => self::BELOW_MINIMUM]);
        }

        return [$charged, $quote];
    }

    /**
     * 断った理由を注文の入力の欄の名前（charges.<欄の鍵>）にして返す
     */
    public static function invalid(ChargeRejected $e): ValidationException
    {
        $messages = [];

        foreach ($e->errors as $key => $message) {
            $messages['charges.'.$key] = $message;
        }

        return ValidationException::withMessages($messages === [] ? ['charges._form' => OrderCharges::UNAVAILABLE] : $messages);
    }
}
