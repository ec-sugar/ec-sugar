<?php

declare(strict_types=1);

namespace App\Core\Transaction;

use App\Core\Money\Money;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\TaxRounding;
use App\Core\Transaction\Models\Transaction;

/**
 * 税率ごとの内訳（ADR 0063。インボイスの記載事項: 税率ごとの対価の額と消費税額）。確かめの画面・注文の画面・メール・管理画面・領収書が同じものを使う。
 * 計算は Totals と同じ（税率ごとに合計してから 1 回だけ丸める）。注文の内訳は、作った注文の明細（注文の時の率を写した tax_rate_bp）から出す。
 *
 * amount は税込みの対象の額（内税の店の見せ方。外税の取引でも「対価＋税」）、tax はその率の消費税額。額が 0 の率は rows に出さない
 */
final readonly class TaxBreakdown
{
    /**
     * @param  list<array{rateBp: int, rateLabel: string, reduced: bool, amount: int, tax: int, amountLabel: string, taxLabel: string}>  $rows  率の低い順
     */
    public function __construct(public array $rows) {}

    /**
     * 作った注文の明細から（率ごとの額を DB で合計して）
     */
    public static function ofTransaction(Transaction $transaction): self
    {
        /** @var array<int, int> $grouped */
        $grouped = $transaction->lines()->reorder()->toBase()
            ->selectRaw('tax_rate_bp, SUM(amount)::bigint AS amount')
            ->groupBy('tax_rate_bp')
            ->pluck('amount', 'tax_rate_bp')
            ->mapWithKeys(static fn (mixed $amount, mixed $rate): array => [(int) $rate => (int) $amount])
            ->all();

        return self::ofAmounts($grouped, (bool) $transaction->prices_include_tax, $transaction->tax_rounding, $transaction->currency);
    }

    /**
     * 率ごとの額（明細の額の合計）から。注文の前（カートの確かめの画面）は、注文と同じ既定（内税・切り捨て）で
     *
     * @param  array<int, int>  $grouped  税率（basis point）=> その率の明細の額の合計
     */
    public static function ofAmounts(array $grouped, bool $pricesIncludeTax = true, TaxRounding $rounding = TaxRounding::Floor, string $currency = 'JPY'): self
    {
        $rows = [];

        foreach (Totals::ofAmounts($grouped, $pricesIncludeTax, $rounding)->byRate as $rate => $part) {
            $amount = $part['base'] + $part['tax'];

            if ($amount === 0 && $part['tax'] === 0) {
                continue;
            }

            $rows[] = [
                'rateBp' => $rate,
                'rateLabel' => TaxClass::rateLabel($rate),
                'reduced' => TaxClass::isReducedRate($rate),
                'amount' => $amount,
                'tax' => $part['tax'],
                'amountLabel' => Money::format($amount, $currency),
                'taxLabel' => Money::format($part['tax'], $currency),
            ];
        }

        return new self($rows);
    }

    /**
     * 軽減税率の行がある（「※ は軽減税率の対象」を添える）
     */
    public function hasReduced(): bool
    {
        return in_array(true, array_column($this->rows, 'reduced'), true);
    }

    /**
     * 消費税の合計（率ごとに丸めた額の合計）
     */
    public function tax(): int
    {
        return array_sum(array_column($this->rows, 'tax'));
    }

    /**
     * 1 行の見せ方（「8% 対象 ¥1,080（うち消費税 ¥80）」を「、」でつなぐ）。行が無ければ空の文字
     */
    public function text(): string
    {
        return implode('、', array_map(static fn (array $row): string => $row['rateLabel'].' 対象 '.$row['amountLabel'].'（うち消費税 '.$row['taxLabel'].'）', $this->rows));
    }

    /**
     * 確かめの指紋に入れる形（率と額と税額）
     */
    public function fingerprint(): string
    {
        return implode(',', array_map(static fn (array $row): string => $row['rateBp'].'='.$row['amount'].'/'.$row['tax'], $this->rows));
    }
}
