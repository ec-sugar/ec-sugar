<?php

declare(strict_types=1);

namespace App\Core\Ledger\Services;

use App\Core\Event\EventStore;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Enums\LedgerAccount;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Models\LedgerPosting;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Totals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 仕訳の記録（CONCEPT 5.5、ADR 0011）。決済代行に依存しない。決済代行への指示はここから作る（逆にしない）。
 *
 * 符号: 口座の残高が正 = 受け取る権利、負 = 払う義務。仕訳ごとに合計 0。
 *
 *   合意（自動）  charge  payer −総額 / payee +総額
 *                 tax     payee −税額 / tax +税額
 *   支払い        payment payer +額 / (direct: payee −額 | platform: provider −額)
 *   手数料        fee     payee −額 / platform +額
 *   源泉徴収      withholding payee −額 / withholding +額
 *   送金          payout  provider +額 / payee −額
 *   返金          refund  payer −額 / (direct: payee +額 | platform: provider +額)
 *   返品の調整    adjustment payer +返した額 / payee −(返した額 − 税) / tax −税（税率ごとに 1 本。ADR 0048）
 *
 * 連鎖のロック → 取引の行ロックの順に取ってから書く（同時の支払い記録で受け取り過ぎない。EventStore のロック順序）。
 */
final class LedgerService
{
    public function __construct(private readonly EventStore $events) {}

    /**
     * 合意した金額で charge / tax を記録する。前の合意の分が残っていれば逆仕訳してから書く（本査定で金額が変わる買取など）。
     */
    public function recordAgreement(Transaction $transaction, ?string $actor = null): void
    {
        DB::transaction(function () use ($transaction, $actor): void {
            $this->events->lock();
            $this->reverseAgreement($transaction, $actor);

            $payer = LedgerAccount::of($transaction->payer);
            $payee = LedgerAccount::of($transaction->payee);

            if ($transaction->total_amount > 0) {
                $this->post($transaction, JournalType::Charge, [
                    [$payer, -$transaction->total_amount],
                    [$payee, $transaction->total_amount],
                ], actor: $actor);
            }

            if ($transaction->tax_amount > 0) {
                $this->post($transaction, JournalType::Tax, [
                    [$payee, -$transaction->tax_amount],
                    [LedgerAccount::Tax, $transaction->tax_amount],
                ], actor: $actor);
            }
        });
    }

    /**
     * まだ打ち消されていない charge / tax を逆仕訳する（取消・全額返金・合意のやり直し）。
     */
    public function reverseAgreement(Transaction $transaction, ?string $actor = null): void
    {
        DB::transaction(function () use ($transaction, $actor): void {
            $this->events->lock();

            $outstanding = LedgerJournal::query()
                ->where('transaction_id', $transaction->id)
                ->whereIn('type', [JournalType::Charge, JournalType::Tax])
                ->whereNull('reverses_journal_id')
                ->whereDoesntHave('reversal')
                ->orderBy('id')
                ->get();

            foreach ($outstanding as $journal) {
                $this->reverse($journal, $actor);
            }
        });
    }

    public function recordPayment(Transaction $transaction, int $amount, PaymentRoute $route, ?string $reference = null, ?string $actor = null): LedgerJournal
    {
        self::assertPositive($amount);

        return DB::transaction(function () use ($transaction, $amount, $route, $reference, $actor): LedgerJournal {
            $locked = $this->lock($transaction);
            $paid = $this->netPaid($locked);

            if ($paid + $amount > $locked->total_amount) {
                throw new InvalidArgumentException("合意額を超える支払いは記録できません（合意 {$locked->total_amount}、支払い済み {$paid}、今回 {$amount}）");
            }

            return $this->post($locked, JournalType::Payment, [
                [LedgerAccount::of($locked->payer), $amount],
                [$this->receiver($locked, $route), -$amount],
            ], $reference, $actor);
        });
    }

    public function recordRefund(Transaction $transaction, int $amount, PaymentRoute $route, ?string $reference = null, ?string $actor = null): LedgerJournal
    {
        self::assertPositive($amount);

        return DB::transaction(function () use ($transaction, $amount, $route, $reference, $actor): LedgerJournal {
            $locked = $this->lock($transaction);
            // 決済代行の経路の返金は、決済代行が預かった額まで（ギフトカードでの受け取り〔direct〕まで決済代行から返さない。ADR 0076）
            $paid = $route === PaymentRoute::Platform ? $this->heldByProvider($locked) : $this->netPaid($locked);

            if ($amount > $paid) {
                throw new InvalidArgumentException("受け取った額を超える返金は記録できません（受領 {$paid}、今回 {$amount}）");
            }

            return $this->post($locked, JournalType::Refund, [
                [LedgerAccount::of($locked->payer), -$amount],
                [$this->receiver($locked, $route), $amount],
            ], $reference, $actor);
        });
    }

    /**
     * 返品・一部返金で、請求と消費税を税率ごとに減らす（調整の仕訳。ADR 0048）。返金の仕訳（お金の動き）とは別に、返した額（税込み）の分だけ請求を、
     * その率の消費税の分だけ納める義務を減らす:  payer +返した額 / payee −（返した額 − 税） / tax −税
     * 取引の請求の残り（請求 − これまでの調整）を越えては減らさない。率ごとに 1 本（reference に率を残す）
     */
    public function recordReturnAdjustment(Transaction $transaction, int $gross, int $tax, int $rateBp, ?string $actor = null): LedgerJournal
    {
        self::assertPositive($gross);

        // 税は「返す前と後の残りの税の差」なので、1 円の返金では税が返した額と同じ 1 円になることがある（その時は payee の行を書かない）
        if ($tax < 0 || $tax > $gross) {
            throw new InvalidArgumentException('調整の消費税は 0 以上で、返した額までの整数です');
        }

        return DB::transaction(function () use ($transaction, $gross, $tax, $rateBp, $actor): LedgerJournal {
            $locked = $this->lock($transaction);
            $payer = LedgerAccount::of($locked->payer);
            // 請求の残り: 合意の請求（payer の負）と、これまでの調整（payer の正）の和。0 より大きくしない
            $charged = (int) LedgerPosting::query()
                ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
                ->where('ledger_postings.transaction_id', $locked->id)
                ->whereIn('ledger_journals.type', [JournalType::Charge->value, JournalType::Adjustment->value])
                ->where('ledger_postings.account', $payer->value)
                ->sum('ledger_postings.amount');

            if ($charged + $gross > 0) {
                throw new InvalidArgumentException('請求の残りを越えて減らせません（残り '.(-$charged)."、今回 {$gross}）");
            }

            $postings = [[$payer, $gross]];

            if ($gross > $tax) {
                $postings[] = [LedgerAccount::of($locked->payee), -($gross - $tax)];
            }

            if ($tax > 0) {
                $postings[] = [LedgerAccount::Tax, -$tax];
            }

            return $this->post($locked, JournalType::Adjustment, $postings, "return-rate-{$rateBp}", $actor);
        });
    }

    public function recordFee(Transaction $transaction, int $amount, ?string $actor = null): LedgerJournal
    {
        self::assertPositive($amount);

        return $this->post($transaction, JournalType::Fee, [
            [LedgerAccount::of($transaction->payee), -$amount],
            [LedgerAccount::Platform, $amount],
        ], actor: $actor);
    }

    public function recordWithholding(Transaction $transaction, int $amount, ?string $actor = null): LedgerJournal
    {
        self::assertPositive($amount);

        return $this->post($transaction, JournalType::Withholding, [
            [LedgerAccount::of($transaction->payee), -$amount],
            [LedgerAccount::Withholding, $amount],
        ], actor: $actor);
    }

    public function recordPayout(Transaction $transaction, int $amount, ?string $reference = null, ?string $actor = null): LedgerJournal
    {
        self::assertPositive($amount);

        return $this->post($transaction, JournalType::Payout, [
            [LedgerAccount::Provider, $amount],
            [LedgerAccount::of($transaction->payee), -$amount],
        ], $reference, $actor);
    }

    /**
     * 取引ごとの口座残高。全口座の合計は常に 0。
     *
     * @return array<string, int> 口座 => 残高
     */
    public function balances(Transaction $transaction): array
    {
        /** @var array<string, int> $balances */
        $balances = LedgerPosting::query()
            ->where('transaction_id', $transaction->id)
            ->groupBy('account')
            ->selectRaw('account, SUM(amount)::bigint AS balance')
            ->pluck('balance', 'account')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        ksort($balances);

        return $balances;
    }

    public function reverse(LedgerJournal $journal, ?string $actor = null): LedgerJournal
    {
        $postings = $journal->postings()->get()
            ->map(static fn (LedgerPosting $posting): array => [$posting->account, -$posting->amount])
            ->values()
            ->all();

        return $this->post($journal->transaction()->firstOrFail(), $journal->type, $postings, $journal->reference, $actor, $journal);
    }

    /**
     * @param  list<array{0: LedgerAccount, 1: int}>  $postings
     */
    private function post(
        Transaction $transaction,
        JournalType $type,
        array $postings,
        ?string $reference = null,
        ?string $actor = null,
        ?LedgerJournal $reverses = null,
    ): LedgerJournal {
        if (count($postings) < 2) {
            throw new InvalidArgumentException('仕訳には 2 行以上の明細が要ります');
        }

        $sum = 0;

        foreach ($postings as [, $amount]) {
            if ($amount === 0) {
                throw new InvalidArgumentException('金額 0 の明細は書けません');
            }
            $sum += $amount;
        }

        if ($sum !== 0) {
            throw new InvalidArgumentException("仕訳の貸借が一致しません（合計 {$sum}）");
        }

        if ($reference !== null && (strlen($reference) > 128 || preg_match('/\A[\x21-\x7E]+\z/', $reference) !== 1)) {
            throw new InvalidArgumentException('reference は 128 文字までの ASCII です');
        }

        return DB::transaction(function () use ($transaction, $type, $postings, $reference, $actor, $reverses): LedgerJournal {
            $this->events->lock();

            $journal = LedgerJournal::create([
                'transaction_id' => $transaction->id,
                'type' => $type,
                'currency' => $transaction->currency,
                'reverses_journal_id' => $reverses?->id,
                'reference' => $reference,
                'occurred_at' => CarbonImmutable::now(),
            ]);

            $amounts = [];

            foreach ($postings as [$account, $amount]) {
                LedgerPosting::create([
                    'journal_id' => $journal->id,
                    'transaction_id' => $transaction->id,
                    'account' => $account,
                    'amount' => $amount,
                ]);
                $amounts[] = ['account' => $account->value, 'amount' => $amount];
            }

            $this->events->append('ledger.journal_posted', 'transaction', $transaction->id, [
                'journal_id' => $journal->id,
                'type' => $type->value,
                'currency' => $transaction->currency,
                'reverses_journal_id' => $reverses?->id,
                'postings' => $amounts,
            ], $actor);

            return $journal;
        });
    }

    private function lock(Transaction $transaction): Transaction
    {
        $this->events->lock();

        return Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * 受け取った額（支払い − 返金）。payment / refund の payer 口座の動きから求める。
     */
    /**
     * 受け取って、まだ返していない額（支払いの仕訳 − 返金の仕訳）
     */
    public function netPaid(Transaction $transaction): int
    {
        return (int) LedgerPosting::query()
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
            ->where('ledger_postings.transaction_id', $transaction->id)
            ->whereIn('ledger_journals.type', [JournalType::Payment->value, JournalType::Refund->value])
            ->where('ledger_postings.account', LedgerAccount::of($transaction->payer)->value)
            ->sum('ledger_postings.amount');
    }

    /**
     * 決済代行の経路で受け取って、まだ返していない額（provider の口座の支払いと返金の動き）
     */
    private function heldByProvider(Transaction $transaction): int
    {
        return -(int) LedgerPosting::query()
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
            ->where('ledger_postings.transaction_id', $transaction->id)
            ->whereIn('ledger_journals.type', [JournalType::Payment->value, JournalType::Refund->value])
            ->where('ledger_postings.account', LedgerAccount::Provider->value)
            ->sum('ledger_postings.amount');
    }

    private static function assertPositive(int $amount): void
    {
        if ($amount <= 0 || $amount > LineDraft::MAX_AMOUNT * Totals::MAX_LINES) {
            throw new InvalidArgumentException('金額は 1 以上で、上限以内の整数です');
        }
    }

    private function receiver(Transaction $transaction, PaymentRoute $route): LedgerAccount
    {
        return $route === PaymentRoute::Platform ? LedgerAccount::Provider : LedgerAccount::of($transaction->payee);
    }
}
