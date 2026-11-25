<?php

declare(strict_types=1);

namespace App\Storefront\Apps;

use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;

/**
 * 購入の完了の画面で App に渡す値（ADR 0034 の 1）。注文の番号・金額（最小通貨単位の整数）・通貨・品の ID と数だけ。
 * 名前・メール・住所・電話は持たない（渡す道を作らない）
 */
final readonly class PurchaseData
{
    /**
     * @param  list<array{id: string, quantity: int}>  $items
     */
    public function __construct(
        public string $number,
        public int $total,
        public string $currency,
        public array $items,
    ) {}

    /**
     * 店の文脈の中で、取引から作る（品の明細だけ。送料などの調整の行は入れない）
     */
    public static function of(Transaction $transaction, string $number): self
    {
        $items = [];

        foreach ($transaction->lines()->where('line_type', LineType::Item->value)->whereNotNull('listing_id')->orderBy('position')->get(['listing_id', 'quantity']) as $line) {
            /** @var TransactionLine $line */
            $items[] = ['id' => (string) $line->listing_id, 'quantity' => (int) $line->quantity];
        }

        return new self($number, (int) $transaction->total_amount, strtoupper((string) $transaction->currency), $items);
    }

    /**
     * スクリプトの要素に付ける data- の印（値は核が Blade の {{ }} でエスケープして出す）
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'data-order-number' => $this->number,
            'data-order-total' => (string) $this->total,
            'data-order-currency' => $this->currency,
            'data-order-items' => (string) json_encode($this->items, JSON_THROW_ON_ERROR),
        ];
    }
}
