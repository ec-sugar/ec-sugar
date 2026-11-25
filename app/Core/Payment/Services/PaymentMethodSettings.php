<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Event\EventStore;
use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\Contracts\PaymentCredentialStore;
use App\Core\Payment\Models\PaymentMethodSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 店が使う支払いの手段を読む・変える（ADR 0058）。カードはいつも使う。足せるのはコンビニ払いと PayPay。
 * 支払いの画面には、ここで選んだ手段のうち、その注文の額で使えるものだけを出す（ほかの手段は Stripe に excluded_payment_method_types で外してもらう）。
 * Stripe のダッシュボードで足した手段は、ここで選ぶまで出ない（核が扱えない後払いの手段を、知らないうちに出さない）
 */
final class PaymentMethodSettings
{
    /** コンビニ払いの払いの期限の日数の範囲（Stripe は 1〜60 日。長く在庫を押さえないよう 30 日まで） */
    public const int MIN_KONBINI_DAYS = 1;

    public const int MAX_KONBINI_DAYS = 30;

    public function __construct(
        private readonly EventStore $events,
        private readonly PaymentCredentialStore $gateways,
    ) {}

    /**
     * 管理画面に見せる今の設定と、選べるか（PayPay は Stripe へ接続した店では使えない。Stripe の文書）
     *
     * @return array{konbini: bool, paypay: bool, konbini_days: int, paypay_available: bool}
     */
    public function status(): array
    {
        $row = PaymentMethodSetting::query()->first();

        return [
            'konbini' => (bool) $row?->konbini,
            'paypay' => (bool) $row?->paypay,
            'konbini_days' => $row->konbini_days ?? 3,
            'paypay_available' => ! $this->connected(),
        ];
    }

    /**
     * この額の注文の支払いの画面に出す手段。選んでいても、額が手段の範囲の外なら出さない。
     * カードを預ける支払い（定期便の 1 回目）は、カードだけ（コンビニ払いと PayPay は預けられない）。
     * お届け日を選んだ注文と送らない品（チケット）の注文（$datedDelivery）には、後から払う手段（コンビニ払い）を出さない（ADR 0058・0059 の作って決めたこと。
     * 払いの期限まで発送を待つので、選んだ日に届かなくなる。時間帯だけの指定は日を約束しないので出す。チケットは終演の後に払いが届いて出る、を作らない。ADR 0069）。
     * 来店で受け渡す品だけの注文（予約）も同じく出さない（予約の日時の後に払いが届く、を作らない。決めるのは PaymentService）
     */
    public function forCheckout(int $amount, bool $savingCard = false, bool $datedDelivery = false): CheckoutMethods
    {
        $row = PaymentMethodSetting::query()->first();

        if ($row === null || $savingCard) {
            return CheckoutMethods::cardOnly();
        }

        $types = ['card'];

        if ($row->konbini && ! $datedDelivery && self::within('konbini', $amount)) {
            $types[] = 'konbini';
        }

        // 設定した後に Stripe へ接続した店も、ここで落とす
        if ($row->paypay && ! $this->connected() && self::within('paypay', $amount)) {
            $types[] = 'paypay';
        }

        return new CheckoutMethods($types, $row->konbini_days);
    }

    /**
     * 使う手段を変える（お金の権限の人が、パスキーの再確認の後）。何も変わらなければ記録も残さない
     *
     * @param  array<string, mixed>  $input  {konbini, paypay, konbini_days}
     */
    public function update(array $input, ?string $actor = null): void
    {
        $data = Validator::make($input, [
            'konbini' => ['required', 'boolean'],
            'paypay' => ['required', 'boolean'],
            'konbini_days' => ['required', 'integer', 'between:'.self::MIN_KONBINI_DAYS.','.self::MAX_KONBINI_DAYS],
        ], [
            'konbini_days.between' => 'お支払いの期限は 1〜30 日で選んでください。',
        ], ['konbini' => 'コンビニ払い', 'paypay' => 'PayPay', 'konbini_days' => 'コンビニ払いのお支払いの期限'])->validate();

        if ((bool) $data['paypay'] && $this->connected()) {
            throw ValidationException::withMessages(['paypay' => 'Stripe へ接続した店では、PayPay は使えません（Stripe の制限）。自分の Stripe の鍵で設定した店だけが使えます。']);
        }

        DB::transaction(function () use ($data, $actor): void {
            $this->events->lock();
            $row = PaymentMethodSetting::query()->lockForUpdate()->first() ?? new PaymentMethodSetting;
            $row->fill(['konbini' => (bool) $data['konbini'], 'paypay' => (bool) $data['paypay'], 'konbini_days' => (int) $data['konbini_days']]);

            if ($row->exists && ! $row->isDirty()) {
                return;
            }

            $row->save();
            $this->events->append('payment.methods_updated', 'payment_method_settings', $row->id, ['konbini' => $row->konbini, 'paypay' => $row->paypay, 'konbini_days' => $row->konbini_days], $actor);
        });
    }

    /**
     * コンビニ払いの払いの期限（Stripe と同じ数え方: 確定した日〔日本時間〕から何日後の 23:59:59）。
     * Stripe に問い合わせられず、答えの期限が分からないときに使う
     */
    public static function konbiniDue(CarbonImmutable $confirmedAt, int $days): CarbonImmutable
    {
        return $confirmedAt->setTimezone('Asia/Tokyo')->startOfDay()->addDays($days)->setTime(23, 59, 59)->utc();
    }

    private static function within(string $type, int $amount): bool
    {
        [$min, $max] = CheckoutMethods::LIMITS[$type];

        return $amount >= $min && $amount <= $max;
    }

    /**
     * Stripe へ接続した店（Connect、ADR 0022）か
     */
    private function connected(): bool
    {
        return $this->gateways->credentials()?->account() !== null;
    }
}
