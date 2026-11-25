<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Money\Money;

/**
 * 注文の入力と確かめの画面に出す支払いの選び方（テーマ API の一部。ADR 0074）。サーバーが店の設定とこの注文から決める。
 * 選べない手段（代引きを贈り物・予約・デジタルの品・上限の額を超える注文で）は、理由を添えて「選べない」として並べる
 *
 * options の 1 つ: value（stripe・bank_transfer・cod）・label（画面の文字）・available（選べるか）・reason（選べない理由）・fee（代引き手数料。無ければ null）
 * note: 選び方に出さなかった手段の添え書き（「銀行振込・代金引換は会員の方だけ」。ADR 0085）。無ければ null
 */
final readonly class PaymentOffer
{
    /**
     * @param  list<array{value: string, label: string, available: bool, reason: string|null, fee: int|null}>  $options
     * @param  int  $bankDays  銀行振込の払う期限の日数
     * @param  ?string  $note  選び方に出さなかった手段の添え書き（店が銀行振込・代金引換を会員だけにした時のゲスト）
     */
    public function __construct(
        public array $options,
        public int $bankDays = 7,
        public ?string $note = null,
    ) {}

    /**
     * 選び方を見せるか（店が銀行振込か代引きを使っている時だけ。カードなどだけの店は、今までどおり何も出さない）。
     * 手段が 1 つでも、それが銀行振込・代引きなら見せる（どの手段で払うかをお客さんに見せる）
     */
    public function hasChoice(): bool
    {
        return count($this->options) > 1 || ($this->options !== [] && $this->options[0]['value'] !== CheckoutInput::PAY_STRIPE);
    }

    public function allows(string $value): bool
    {
        return ($this->option($value)['available'] ?? false) === true;
    }

    /**
     * 選べない理由（選べるか、店が使っていない手段なら null。会員だけにして出さなかった手段は添え書き）
     */
    public function reason(string $value): ?string
    {
        if ($this->option($value) === null && $value !== CheckoutInput::PAY_STRIPE) {
            // 出さなかった手段（会員だけの銀行振込・代引きをゲストが送った）は添え書きを理由に
            return $this->note;
        }

        return $this->option($value)['reason'] ?? null;
    }

    public function fee(string $value): int
    {
        return (int) ($this->option($value)['fee'] ?? 0);
    }

    /**
     * 選んだ値が選べなければ、選べる最初の手段（どれも選べなければ stripe）
     */
    public function resolve(string $value): string
    {
        if ($this->allows($value)) {
            return $value;
        }

        foreach ($this->options as $option) {
            if ($option['available']) {
                return $option['value'];
            }
        }

        return CheckoutInput::PAY_STRIPE;
    }

    /**
     * 手数料の見せ方（「¥330」）
     */
    public static function feeLabel(?int $fee): ?string
    {
        return $fee === null || $fee === 0 ? null : Money::format($fee);
    }

    /**
     * @return array{value: string, label: string, available: bool, reason: string|null, fee: int|null}|null
     */
    private function option(string $value): ?array
    {
        foreach ($this->options as $option) {
            if ($option['value'] === $value) {
                return $option;
            }
        }

        return null;
    }
}
