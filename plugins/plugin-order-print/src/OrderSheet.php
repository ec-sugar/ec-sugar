<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint;

use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Money\Money;
use App\Core\Order\AgeChecks;
use App\Core\Order\OrderCharges;
use App\Core\Party\Models\Party;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\TaxBreakdown;
use Carbon\CarbonImmutable;

/**
 * 1 つの注文を、納品書・領収書に載せる形にする（金額は「¥5,400」の形の文字）。
 * 税率ごとの対価と消費税額は、核の内訳（TaxBreakdown。注文の時の率で、税率ごとに 1 回だけ丸める。ADR 0063）をそのまま使う。
 * 軽減税率の明細には「※」。値引きの行は税率ごとに分けてあるので、率が 2 つ以上の注文では値引きの名前に率を添える。
 * 個人情報（届け先・注文者の名前）は、店の人の画面のときだけ読む（shipTo・customerName）。
 * 備考（notes）は App が注文に足した料金の内容（ラッピング・のしと名入れ。ADR 0078。核の OrderCharges）で、これも店の人の画面のときだけ
 */
final readonly class OrderSheet
{
    /**
     * @param  list<array{title: string, quantity: int, unit: string, amount: string, reduced: bool}>  $items  商品とオプションの行
     * @param  list<array{label: string, amount: string}>  $adjustments  送料・値引きなどの行
     * @param  list<array{rate: string, base: string, tax: string, reduced: bool}>  $taxes  税率ごとの対象の額（税込み）と消費税額
     * @param  list<array{name: string, postal: string, address: string}>  $shipTo  届け先（店の人の画面だけ）
     * @param  ?string  $remark  備考（年齢の確かめをした注文の「年齢確認済み（20 歳以上）」。ADR 0077。運送会社の受け取りの時の確かめ用）
     * @param  list<array{name: string, value: string}>  $notes  備考（ラッピング・のしと名入れ。店の人の画面だけ）
     */
    public function __construct(
        public string $number,
        public string $orderedOn,
        public ?string $paidOn,
        public int $total,
        public string $totalLabel,
        public string $subtotalLabel,
        public array $items,
        public array $adjustments,
        public array $taxes,
        public bool $hasReduced,
        public array $shipTo,
        public ?string $customerName,
        public ?string $remark = null,
        public array $notes = [],
    ) {}

    public static function of(Transaction $transaction, bool $personal): self
    {
        $yen = static fn (int $amount): string => Money::format($amount, $transaction->currency);
        $lines = $transaction->lines()->get();
        $goods = $lines->filter(static fn (TransactionLine $line): bool => $line->line_type === LineType::Item || $line->kind === 'addon')->values();
        $adjustments = $lines->reject(static fn (TransactionLine $line): bool => $line->line_type === LineType::Item || $line->kind === 'addon')->values();
        $breakdown = TaxBreakdown::ofTransaction($transaction);
        $mixed = count($breakdown->rows) > 1;
        $taxes = array_map(static fn (array $row): array => ['rate' => $row['rateLabel'], 'base' => $row['amountLabel'], 'tax' => $row['taxLabel'], 'reduced' => $row['reduced']], $breakdown->rows);

        $paid = LedgerJournal::query()->where('transaction_id', $transaction->id)->where('type', JournalType::Payment)->orderBy('occurred_at')->value('occurred_at');

        return new self(
            number: strtoupper($transaction->id),
            orderedOn: self::day($transaction->created_at === null ? null : CarbonImmutable::parse($transaction->created_at)) ?? '',
            paidOn: self::day($paid === null ? null : CarbonImmutable::parse($paid)),
            total: (int) $transaction->total_amount,
            totalLabel: $yen((int) $transaction->total_amount),
            subtotalLabel: $yen((int) $goods->sum('amount')),
            items: $goods->map(static fn (TransactionLine $line): array => [
                'title' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $yen($line->unit_amount),
                'amount' => $yen($line->amount),
                'reduced' => TaxClass::isReducedRate($line->tax_rate_bp),
            ])->all(),
            adjustments: $adjustments->map(static fn (TransactionLine $line): array => [
                'label' => $mixed && in_array($line->kind, ['discount', 'points'], true) ? $line->description.'（'.TaxClass::rateLabel($line->tax_rate_bp).' 対象）' : $line->description,
                'amount' => $yen($line->amount),
            ])->values()->all(),
            taxes: $taxes,
            hasReduced: $breakdown->hasReduced(),
            shipTo: $personal ? self::shipTo($transaction) : [],
            // 名前は暗号化した列なので、モデルを通して読む（value() では暗号文のまま）
            customerName: $personal && $transaction->customer_id !== null ? Party::query()->find($transaction->customer_id)?->name : null,
            // 年齢の確かめをした注文（核の記録。App「年齢確認」をやめた後も出す）
            remark: app(AgeChecks::class)->verifiedFor([$transaction->id])[$transaction->id]['label'] ?? null,
            notes: $personal ? app(OrderCharges::class)->notes($transaction->id, private: true) : [],
        );
    }

    /**
     * 備考の 1 行（「ラッピング: リボン／のし: 御祝・外のし・名入れ ◯◯」。無ければ空）
     */
    public function notesLine(): string
    {
        return OrderCharges::line($this->notes);
    }

    /**
     * 今日（日本の日付）
     */
    public static function today(): string
    {
        return (string) self::day(CarbonImmutable::now());
    }

    /**
     * 届け先（受渡ごと。同じ届け先は 1 つに）
     *
     * @return list<array{name: string, postal: string, address: string}>
     */
    private static function shipTo(Transaction $transaction): array
    {
        $out = [];

        foreach ($transaction->fulfillments()->orderBy('created_at')->get() as $fulfillment) {
            $a = is_array($fulfillment->address) ? $fulfillment->address : [];
            $zip = preg_replace('/\D/', '', self::str($a['zip'] ?? null)) ?? '';
            $entry = [
                'name' => self::str($a['name'] ?? null),
                'postal' => strlen($zip) === 7 ? '〒'.substr($zip, 0, 3).'-'.substr($zip, 3) : '',
                'address' => trim(self::str($a['prefecture'] ?? null).self::str($a['city'] ?? null).self::str($a['line1'] ?? null).' '.self::str($a['line2'] ?? null)),
            ];

            if (($entry['name'] !== '' || $entry['address'] !== '') && ! in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function day(?CarbonImmutable $at): ?string
    {
        return $at?->setTimezone('Asia/Tokyo')->format('Y年n月j日');
    }
}
