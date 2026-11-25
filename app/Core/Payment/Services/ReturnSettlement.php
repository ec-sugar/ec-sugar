<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Event\EventStore;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Enums\LedgerAccount;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Models\LedgerPosting;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Money\Allocation;
use App\Core\Payment\Enums\ReturnReason;
use App\Core\Payment\Events\RefundSettled;
use App\Core\Payment\Models\OrderReturn;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\RefundLine;
use App\Core\Points\Services\PointService;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\TaxBreakdown;
use App\Core\Transaction\Totals;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 返品・一部返金の帳簿（ADR 0048）。返金が確かになった時（決済代行の返金の成功・外で返した記録・止めた外の返金の記録）に、
 * 台帳に返金の仕訳と税率ごとの調整の仕訳を書き、ポイントを割合で動かし、App へ知らせる（RefundSettled）。
 * 返せる額（受け取った額 − 途中・済んだ返金）と、税率ごとの返す額と消費税の計算もここに置く（返金の画面と DB のトリガーと同じ数え方）。
 * どれも呼ぶ側の DB トランザクションの中で、台帳のロック（EventStore::lock）の後に呼ぶ
 */
final class ReturnSettlement
{
    /** 数える返金（途中・止めた・済んだ）。返金が要る支払いと、まだ確かめていない外でした返金は数えない（DB のトリガー sugar_refunds_within_received と同じ） */
    private const array COMMITTED = ['requested', 'pending', 'held', 'succeeded'];

    public function __construct(
        private readonly EventStore $events,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * 受け取った額（台帳の支払いの仕訳の合計。返金は引かない）
     */
    public function received(Transaction $transaction): int
    {
        return (int) LedgerPosting::query()
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
            ->where('ledger_postings.transaction_id', $transaction->id)
            ->where('ledger_journals.type', JournalType::Payment->value)
            ->where('ledger_postings.account', LedgerAccount::of($transaction->payer)->value)
            ->sum('ledger_postings.amount');
    }

    /**
     * まだ返せる余り（受け取った額 − 途中・止めた・済んだ返金。DB のトリガーと同じ数え方）。$except の返金は数えない（その返金の額を変える時）
     */
    public function room(Transaction $transaction, ?string $except = null): int
    {
        $committed = (int) Refund::query()->where('transaction_id', $transaction->id)->where('purpose', '<>', 'stray')->whereIn('status', self::COMMITTED)
            ->when($except !== null, static fn ($q) => $q->whereKeyNot($except))
            ->whereNot(static fn ($q) => $q->where('purpose', 'external')->where('status', 'held'))
            ->whereNotExists(static fn ($q) => $q->from('stray_payments')->whereColumn('stray_payments.payment_ref', 'refunds.payment_ref'))
            ->sum('amount');

        return $this->received($transaction) - $committed;
    }

    /**
     * 新しい返品・一部返金に使える額: 余り − 断られて頼み直していない返品の返金（その返品の分を空けておく）
     */
    public function available(Transaction $transaction): int
    {
        // 断られた・額が違って「無かった」とした返品の返金で、まだ頼み直していないもの
        $declined = (int) Refund::query()->where('transaction_id', $transaction->id)->where('purpose', 'return')->where('status', 'failed')
            ->where(static fn ($q) => $q->whereNull('resolution')->orWhere('resolution', 'dismissed'))
            ->whereNotExists(static fn ($q) => $q->from('refunds AS live')->whereColumn('live.return_id', 'refunds.return_id')->where('live.status', '<>', 'failed'))
            ->sum('amount');

        return max(0, $this->room($transaction) - $declined);
    }

    /**
     * ポイントの割合の元: [これまでに返した額（済んだ返品・一部返金と記録した外の返金）, 受け取った額 − 済んだ送料の差額の返金]
     *
     * @return array{0: int, 1: int}
     */
    public function progress(Transaction $transaction): array
    {
        $done = Refund::query()->where('transaction_id', $transaction->id)->where('status', 'succeeded');
        $refunded = (int) (clone $done)->whereIn('purpose', ['return', 'external'])->sum('amount');
        $shipping = (int) (clone $done)->where('purpose', 'shipping')->sum('amount');

        return [$refunded, $this->received($transaction) - $shipping];
    }

    /**
     * 税率ごとの、まだ返していない額（注文の率ごとの額 − 返金の行の額 − 送料の差額の返金〔送料の率〕）。$exceptReturn の返金の 1 回の行は数えない（その 1 回の税を出し直す時）
     *
     * @return array<int, int> 率 => 額（0 以上）
     */
    public function remainingByRate(Transaction $transaction, ?string $exceptReturn = null): array
    {
        $returned = RefundLine::query()->where('transaction_id', $transaction->id)->when($exceptReturn !== null, static fn ($q) => $q->where('return_id', '<>', $exceptReturn))->toBase()
            ->selectRaw('tax_rate_bp, SUM(amount)::bigint AS amount')->groupBy('tax_rate_bp')->pluck('amount', 'tax_rate_bp');
        $shippingRate = TaxClass::SHIPPING->rateBp();
        $shipping = self::shippingDifferences($transaction->id);
        $remaining = [];

        foreach (TaxBreakdown::ofTransaction($transaction)->rows as $row) {
            $rate = $row['rateBp'];
            $remaining[$rate] = max(0, $row['amount'] - (int) ($returned[$rate] ?? 0) - ($rate === $shippingRate ? $shipping : 0));
        }

        return $remaining;
    }

    /**
     * 贈り物の送料の差額の返金（途中・済んだ・止めたものと、断られてまだ片付けていないもの〔店の人が頼み直せる〕）。返品の送料と残りの額の計算で、二重に返さないように引く
     */
    public static function shippingDifferences(string $transactionId): int
    {
        return (int) Refund::query()->where('transaction_id', $transactionId)->where('purpose', 'shipping')
            ->where(static fn ($q) => $q->where('status', '<>', 'failed')->orWhereNull('resolution'))->sum('amount');
    }

    /**
     * 税率ごとの返す額と消費税。消費税は「返す前の残りの額の税 − 返した後の残りの額の税」で出す（率ごとに 1 回だけ丸める決まり〔ADR 0063〕のまま、
     * 全部を返すと税の合計が注文の税にちょうど戻る）
     *
     * @param  array<int, int>  $gross  率 => 今回返す額
     * @return list<array{rate_bp: int, gross: int, tax: int}>
     */
    public function taxes(Transaction $transaction, array $gross, ?string $exceptReturn = null): array
    {
        $remaining = $this->remainingByRate($transaction, $exceptReturn);
        $rows = [];
        ksort($gross);

        foreach ($gross as $rate => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $before = $remaining[$rate] ?? 0;

            if ($amount > $before) {
                throw new InvalidArgumentException("税率 {$rate} の残りを越えて返せません（残り {$before}、今回 {$amount}）");
            }

            $rows[] = ['rate_bp' => $rate, 'gross' => $amount, 'tax' => $this->taxOf($transaction, $rate, $before) - $this->taxOf($transaction, $rate, $before - $amount)];
        }

        return $rows;
    }

    /**
     * 返品・一部返金の返金が確かになった（成功・外で返した記録）。台帳に返金の仕訳（お金の動き）と税率ごとの調整の仕訳、ポイント、App への知らせ
     */
    public function settle(Refund $locked, ?string $actor): void
    {
        $return = OrderReturn::query()->findOrFail($locked->return_id);
        $transaction = Transaction::query()->findOrFail($locked->transaction_id);
        // 決済代行に頼んだ返金は決済代行が預かった分から、外で返した分は店から直に返した
        $route = $locked->channel === 'outside' ? PaymentRoute::Direct : PaymentRoute::Platform;
        $this->ledger->recordRefund($transaction, $locked->amount, $route, $locked->provider_ref, $actor);
        // 確かめた額にそろえる直しの行があれば、税は行から出し直す（ふつうは作った時の税のまま）
        $lines = (int) $return->lines()->sum('amount');
        $taxes = $lines === $return->amount ? $return->taxes : $this->taxes($transaction, $this->grossOf($return), $return->id);

        foreach ($taxes as $row) {
            $this->ledger->recordReturnAdjustment($transaction, (int) $row['gross'], (int) $row['tax'], (int) $row['rate_bp'], $actor);
        }

        $this->afterRefund($locked, $return, $actor);
    }

    /**
     * 額が違って止めた返品・一部返金の返金を、店の人が確かめた額で記録した（止めた返金の片付け。ADR 0048）。
     * 確かめた額と返金の行の合計の差を、金額だけの行（多ければ残りの額の割合で足し、少なければこの 1 回の率ごとの額の割合で引く）でそろえてから、ふつうに帳簿を書く
     */
    public function settleResolved(Refund $locked, ?string $actor): void
    {
        $return = OrderReturn::query()->findOrFail($locked->return_id);
        $transaction = Transaction::query()->findOrFail($locked->transaction_id);
        $difference = $locked->amount - (int) $return->lines()->sum('amount');

        if ($difference !== 0) {
            $parts = $difference > 0
                ? Allocation::byWeight($difference, $this->remainingByRate($transaction))
                : array_map(static fn (int $v): int => -$v, Allocation::byWeight(-$difference, $this->grossOf($return)));

            foreach (array_filter($parts) as $rate => $part) {
                RefundLine::create(['return_id' => $return->id, 'transaction_id' => $transaction->id, 'kind' => 'amount', 'line_position' => null, 'quantity' => 0, 'amount' => $part, 'tax_rate_bp' => $rate]);
            }

            $this->events->append('order.return_corrected', 'transaction', $transaction->id, ['return' => $return->id, 'refund' => $locked->id, 'requested' => $return->amount, 'confirmed' => $locked->amount], $actor);
        }

        $this->settle($locked, $actor);
    }

    /**
     * 決済代行の画面でした返金（外でした返金）を、店の人が確かめた額で記録した（止めた返金の片付け。ADR 0027・0048）。
     * 発送の後（返品できる段）なら、金額だけの返金の行（税率ごとに、まだ返していない額の割合で配る）を足して返金の 1 回につなぎ、台帳に返金と調整の仕訳を書く。
     * 発送の前は今までどおり返金の仕訳だけ（後で取り消すと、取消が請求と税を全部打ち消すので、調整を書くと 0 に戻らない）。
     * 経路は決済代行が預かった分（Platform）。銀行振込・代金引換で店が直に返した記録は Direct（PaymentRefunds::recordDirect）
     */
    public function settleExternal(Refund $locked, ?string $actor, PaymentRoute $route = PaymentRoute::Platform): void
    {
        $transaction = Transaction::query()->findOrFail($locked->transaction_id);

        if (! in_array($transaction->phase, [Phase::Fulfillment, Phase::Acceptance, Phase::Settlement], true)) {
            $this->ledger->recordRefund($transaction, $locked->amount, $route, $locked->provider_ref, $actor);
            $this->afterRefund($locked, null, $actor);

            return;
        }

        $remaining = $this->remainingByRate($transaction);
        $amount = min($locked->amount, array_sum($remaining));
        $gross = array_filter(Allocation::byWeight($amount, $remaining), static fn (int $v): bool => $v > 0);
        $return = OrderReturn::create(['transaction_id' => $transaction->id, 'reason' => ReturnReason::External, 'amount' => $locked->amount, 'taxes' => $this->taxes($transaction, $gross), 'actor' => $actor]);

        foreach ($gross as $rate => $part) {
            RefundLine::create(['return_id' => $return->id, 'transaction_id' => $transaction->id, 'kind' => 'amount', 'line_position' => null, 'quantity' => 0, 'amount' => $part, 'tax_rate_bp' => $rate]);
        }

        $locked->forceFill(['return_id' => $return->id])->save();
        // 外でした返金の記録は返し方（channel）を持たない。記録には経路で書く（店が直に返した記録は outside。で、発送の後の銀行振込の返金の記録が型の誤りで落ちていた）
        $this->events->append('order.return_recorded', 'transaction', $transaction->id, self::payload($return, $locked->channel ?? ($route === PaymentRoute::Direct ? 'outside' : 'provider')), $actor);
        $this->ledger->recordRefund($transaction, $locked->amount, $route, $locked->provider_ref, $actor);

        foreach ($return->taxes as $row) {
            $this->ledger->recordReturnAdjustment($transaction, (int) $row['gross'], (int) $row['tax'], (int) $row['rate_bp'], $actor);
        }

        $this->afterRefund($locked, $return, $actor);
    }

    /**
     * 取消・送料の差額の返金が確かになった（App への知らせだけ。台帳とポイントは今までどおり呼ぶ側が書く）
     */
    public function announce(Refund $locked, ?string $actor = null): void
    {
        $transaction = Transaction::query()->findOrFail($locked->transaction_id);
        $this->events->notice(new RefundSettled($transaction->id, $locked->id, $locked->purpose, $locked->amount, $this->ledger->netPaid($transaction) === 0), $actor);
    }

    /**
     * 記録とイベントの中身（個人情報なし。明細の位置・数・額・率・在庫に戻したか）
     *
     * @return array<string, mixed>
     */
    public static function payload(OrderReturn $return, string $channel): array
    {
        return [
            'return' => $return->id,
            'reason' => $return->reason->value,
            'amount' => $return->amount,
            'channel' => $channel,
            'taxes' => $return->taxes,
            'lines' => $return->lines()->orderBy('created_at')->orderBy('id')->get()->map(static fn (RefundLine $line): array => [
                'kind' => $line->kind, 'position' => $line->line_position, 'quantity' => $line->quantity, 'amount' => $line->amount, 'rate_bp' => $line->tax_rate_bp, 'restock' => $line->restock,
            ])->values()->all(),
        ];
    }

    /**
     * ポイント（ADR 0068 の作って決めたこと）と App への知らせ。全額を返した時は今までどおりの全額の取り消し、一部なら割合で
     */
    private function afterRefund(Refund $locked, ?OrderReturn $return, ?string $actor): void
    {
        $transaction = Transaction::query()->findOrFail($locked->transaction_id);
        // 全額: お金で受け取った額（台帳の受け取り − ギフトカードでの受け取り）を全部返した。ギフトカードの額は自動では戻さない（店の人が額を選んで戻す。ADR 0076）
        $full = $this->ledger->netPaid($transaction) - app(GiftCardService::class)->heldInLedger($transaction->id) === 0;
        $points = app(PointService::class);

        if ($full) {
            // 外でした返金は、まだ発送できる開いた注文では動かさない（店が送るかもしれない。ADR 0068 の 4）。返品は発送の後だけなので動かす
            if ($locked->purpose === 'return' || ! $this->shippable($transaction)) {
                $points->refunded($transaction->id, $actor);
            }
        } else {
            [$refunded, $received] = $this->progress($transaction);
            $points->refundedPart($transaction->id, $refunded, $received, $actor);
        }

        $lines = $return === null ? [] : $return->lines()->orderBy('created_at')->orderBy('id')->get()
            ->map(static fn (RefundLine $line): array => ['kind' => $line->kind, 'position' => $line->line_position, 'quantity' => $line->quantity, 'amount' => $line->amount])->values()->all();
        $this->events->notice(new RefundSettled($transaction->id, $locked->id, $locked->purpose, $locked->amount, $full, $return?->id, $lines), $actor);
    }

    /**
     * 発送の前の受渡が残る開いた注文（まだ発送できる）。受渡の表は文脈をまたがず、状態の値だけを見る
     */
    private function shippable(Transaction $transaction): bool
    {
        return ! $transaction->status->isClosed()
            && ($transaction->phase === Phase::Payment || DB::table('fulfillments')->where('transaction_id', $transaction->id)->where('status', 'pending')->exists());
    }

    /**
     * 返金の 1 回の税率ごとの額（行の合計）
     *
     * @return array<int, int>
     */
    private function grossOf(OrderReturn $return): array
    {
        return $return->lines()->toBase()->selectRaw('tax_rate_bp, SUM(amount)::bigint AS amount')->groupBy('tax_rate_bp')->pluck('amount', 'tax_rate_bp')
            ->mapWithKeys(static fn (mixed $amount, mixed $rate): array => [(int) $rate => (int) $amount])->filter(static fn (int $v): bool => $v > 0)->all();
    }

    private function taxOf(Transaction $transaction, int $rate, int $gross): int
    {
        return $gross <= 0 ? 0 : Totals::ofAmounts([$rate => $gross], (bool) $transaction->prices_include_tax, $transaction->tax_rounding)->tax;
    }
}
