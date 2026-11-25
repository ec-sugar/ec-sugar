<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Listing\Models\Listing;
use App\Core\Money\Money;
use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\DirectMethod;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentService;
use App\Storefront\Cart\CartView;
use App\Storefront\Members\Member;
use Illuminate\Support\Facades\Auth;

/**
 * この注文で選べる支払いの選び方を決める（ADR 0074）。注文の入力・確かめの画面・確定（錠の中）が同じ決め方で使う。
 *
 * - stripe: 決済代行の画面で選ぶ手段（カード・コンビニ払い・PayPay）。Stripe の鍵がある店だけ
 * - bank_transfer: 銀行振込を使い、振込先を入れた店だけ。贈り物・お届け日を選んだ注文では選べない（振込を待ってから発送する。コンビニ払いと同じ）
 * - cod: 代引きを使う店で、贈り物でない・ふつうの商品（product）だけ・手数料を含めて上限の額まで。選べない時は理由を添える。
 *   定期便（カードを預ける 1 回目）は別の道（カートを通さない）で決済代行の画面へ進むので、ここには来ない
 * 合計 0 円の注文は払うものが無いので、選び方を出さない
 *
 * まだ払われていない注文の上限（ADR 0085）もここで決める（入力・確かめの画面・確定の錠の中で同じ答えになるように）:
 * - 店が「銀行振込・代金引換は会員だけ」にしたら、ゲストには銀行振込・代引きを出さず、添え書き（note）だけを出す
 * - 同じメール・同じ接続元の銀行振込・代引きの入金待ちが上限に達していたら、銀行振込・代引きを理由（UnpaidOrderLimits::PENDING）を添えて選べなくする
 */
final class PaymentChoices
{
    public const string COD_GIFT = '贈り物のご注文では、代金引換をお選びいただけません。';

    public const string COD_KIND = '予約・デジタルの品を含むご注文では、代金引換をお選びいただけません。';

    public const string BANK_GIFT = '贈り物のご注文では、銀行振込をお選びいただけません。';

    public const string BANK_DATED = 'お届け日をご指定のご注文は、銀行振込をお選びいただけません（お振込みを確認してから発送するため）。';

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentMethodSettings $methods,
        private readonly DirectMethodSettings $direct,
        private readonly UnpaidOrderLimits $limits,
    ) {}

    /**
     * @param  ?CheckoutInput  $input  入れた入力（贈り物・お届け日で決まる手段がある）。注文の入力の画面を初めて開いた時は null
     */
    public function offer(CartView $cart, ?CheckoutInput $input): PaymentOffer
    {
        // 払ってもらう額（合計 − ギフトカードで払う額、手数料の前。ADR 0076）で決める。全額をギフトカードで払う注文は払うものが無いので、店が受け取る支払いを出さない
        $total = $cart->due() - $cart->paymentFee;
        $options = [];

        if ($this->payments->available()) {
            // 送らない品（ダウンロードなど）のある注文にもコンビニ払いは出さない（支払いの画面の決まりと同じ。ADR 0069）
            $methods = $this->methods->forCheckout(max($total, 0), false, ($input?->datedDelivery() ?? false) || $cart->hasDigital());
            $labels = array_map(static fn (string $type): string => (string) CheckoutMethods::label($type), $methods->types);
            $options[] = ['value' => CheckoutInput::PAY_STRIPE, 'label' => implode('・', $labels).(count($labels) > 1 ? '（次の決済画面で選択）' : '（次の決済画面でお支払い）'),
                'available' => true, 'reason' => null, 'fee' => null];
        }

        if ($total < 1) {
            return new PaymentOffer($options);
        }

        $bank = $this->direct->bankTransfer();
        $cod = $this->direct->codEnabled();

        // 会員だけ: ゲストには出さない（確定の錠の中でも同じ決め方なので、出さなかった手段では確定しない）。
        // Stripe の鍵が無い店では、ゲストは払う手段が無いので「ログインすると選べる」と案内する
        if (($bank !== null || $cod) && $this->direct->membersOnly() && self::member() === null) {
            $names = implode('・', array_filter([$bank !== null ? '銀行振込' : null, $cod ? '代金引換' : null]));

            return new PaymentOffer($options, $bank['days'] ?? 7, $options === []
                ? 'このショップのお支払いは'.$names.'です。ログインすると、'.$names.'でご注文いただけます。'
                : $names.'は、ログインした会員の方だけお選びいただけます。');
        }

        // 入金待ちの数の上限: 銀行振込・代引きを使う店だけ数える。メールは入れたもの、まだ入れていなければ会員のメール、どちらも無ければ接続元だけで
        $email = is_string($input?->buyer['email'] ?? null) && trim($input->buyer['email']) !== '' ? $input->buyer['email'] : self::member()?->account->email;
        $pending = $bank !== null || $cod ? $this->limits->pendingProblem($email) : null;

        if ($bank !== null) {
            // 贈り物の注文には出さない（贈り物の受け取りのリンクと期限は払いが確かになってから。振込の期限と重なると、受け取りも返金も店の手作業になる。代引きと同じ理由の出し方）。
            // お届け日を選んだ注文にも出さない（振込を待ってから発送するので、選んだ日に届かない注文ができる。コンビニ払いと同じ。ADR 0058・0059・0074）
            $reason = match (true) {
                $input?->gift !== null => self::BANK_GIFT,
                $input?->datedDelivery() === true => self::BANK_DATED,
                default => $pending,
            };
            $options[] = ['value' => DirectMethod::BankTransfer->value, 'label' => '銀行振込（先払い。ご注文から '.$bank['days'].' 日以内にお振込み）',
                'available' => $reason === null, 'reason' => $reason, 'fee' => null];
        }

        if ($cod) {
            $fee = $this->direct->codFee($total);
            $reason = match (true) {
                $input?->gift !== null => self::COD_GIFT,
                $this->hasOtherKinds($cart) => self::COD_KIND,
                default => $pending,
            };

            if ($reason === null && $fee !== null) {
                $options[] = ['value' => DirectMethod::Cod->value, 'label' => '代金引換（手数料 '.Money::format($fee).'）', 'available' => true, 'reason' => null, 'fee' => $fee];
            } else {
                $options[] = ['value' => DirectMethod::Cod->value, 'label' => '代金引換', 'available' => false, 'fee' => null,
                    'reason' => $reason ?? '代金引換は、手数料を含めて '.Money::format($this->direct->status()['cod_limit']).' までのご注文でお選びいただけます。'];
            }
        }

        return new PaymentOffer($options, $bank['days'] ?? 7);
    }

    /**
     * このショップにログインした会員（ポイント・クーポンの会員だけと同じ見方。PlaceOrder）。ゲストは null
     */
    private static function member(): ?Member
    {
        $member = Auth::guard('customer')->user();

        return $member instanceof Member && $member->belongsHere() ? $member : null;
    }

    /**
     * ふつうの商品（発送する物）でない品が入っているか（予約・デジタルの品などを App が足した時）
     */
    private function hasOtherKinds(CartView $cart): bool
    {
        $ids = array_values(array_unique(array_column(array_filter($cart->lines, static fn (array $line): bool => $line['purchasable']), 'listingId')));

        return $ids !== [] && Listing::query()->whereIn('id', $ids)->where('kind', '<>', 'product')->exists();
    }
}
