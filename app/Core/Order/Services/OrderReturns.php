<?php

declare(strict_types=1);

namespace App\Core\Order\Services;

use App\Core\Event\EventStore;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Inventory\Services\LineStock;
use App\Core\Order\ReturnPlan;
use App\Core\Order\ReturnRequest;
use App\Core\Payment\Contracts\PaymentCredentials;
use App\Core\Payment\Enums\ReturnReason;
use App\Core\Payment\Models\OrderReturn;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\RefundLine;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\RefundRetries;
use App\Core\Payment\Services\ReturnSettlement;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Services\TransactionException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 返品・一部返金・済んだ注文の返金（ADR 0048）。取引を開き直さず、同じ取引に返金の 1 回と返金の行を足す（取引の状態は変えない）。
 * 決済代行で返す時は取消と同じ 3 段（ADR 0027）: ① 台帳のロックの中で、返す額を作り直して照らし、返金の 1 回・返金の行・在庫の戻しと
 * 返金の記録（requested）を残す ② ロックの外で決済代行に頼む ③ ロックの中で答えを記録する（成功なら台帳に返金と調整の仕訳・ポイント・App への知らせ）。
 * 外で返した記録（銀行振込・代金引換・店が現金で返した）は ① の中で済みにして帳簿まで書く。
 * 返せるのは発送した注文（済んだ・精算した注文も）だけ。発送の前は取消（全額）で返す。返金の合計は受け取った額を越えない（DB も断る）
 */
final class OrderReturns
{
    public const string NOT_RETURNABLE = '発送した注文（完了した注文も）だけ、ここから返金できます。発送の前は「注文を取り消す」で全額を返金します。';

    public const string BUSY = 'この注文に、確認が必要な返金（止めている返金）か、Stripe の応答を待っている返金（返品・送料の差額）があります。それらが完了するか確定してから、もう一度お試しください。';

    public const string NO_PROVIDER = 'この注文には Stripe で受け取った支払いが無いため、Stripe では返金できません。お店から返金したら「Stripe 以外で返金した（記録だけ）」を選んでください。';

    public const string OVER_PROVIDER = 'ギフトカードで支払われた分は Stripe では返金できません。Stripe で返金するのは Stripe で受け取った額までにして、残りは「Stripe 以外で返金した（記録だけ）」で記録し、ギフトカードは注文の画面で残高に戻してください。';

    public const string CHANGED = '返金の内容が変わりました（ほかの返金が先に行われたなど）。画面を読み込み直してから、もう一度お試しください。';

    public function __construct(
        private readonly EventStore $events,
        private readonly PaymentRefunds $refunds,
        private readonly RefundRetries $retries,
        private readonly ReturnSettlement $books,
        private readonly ReturnPlanner $planner,
        private readonly LineStock $lineStock,
    ) {}

    /**
     * 返品の画面に出すもの（読むだけ）。返せない注文は returnable = false と理由
     *
     * @return array<string, mixed>
     */
    public function options(Transaction $transaction): array
    {
        $blocked = match (true) {
            ! $this->returnable($transaction) => self::NOT_RETURNABLE,
            $this->busy($transaction) => self::BUSY,
            default => null,
        };

        return [
            'returnable' => $blocked === null,
            'blocked' => $this->returnable($transaction) ? $blocked : null,
            'available' => $this->books->available($transaction),
            // 決済代行（Stripe）で受けた支払いがあれば、決済代行で返せる。無ければ外で返した記録だけ
            'provider' => $this->providerPaid($transaction),
            'reasons' => array_map(static fn (ReturnReason $r): array => ['value' => $r->value, 'label' => $r->label()], ReturnReason::choosable()),
            ...$this->planner->options($transaction),
        ];
    }

    /**
     * 確かめの画面の中身（書き込まない）
     */
    public function preview(Transaction $transaction, ReturnRequest $request): ReturnPlan
    {
        $this->assertReturnable($transaction);

        return $this->planner->plan($transaction, $request);
    }

    /**
     * 返金する。$expectedTotal は確かめの画面で見せた合計（ロックの中で作り直した合計と違えば断る。null なら照らさない）。
     * 決済代行に断られたら RefundDeclined（返金の 1 回と返金の行は残り、「返金をやり直す」で頼み直せる）、答えが分からなければ PaymentUnavailableException
     * （記録は requested のまま。見回りが同じ冪等キーで続きから進める）
     */
    public function record(Transaction $transaction, ReturnRequest $request, ?int $expectedTotal, string $actor): ?Refund
    {
        $this->assertReturnable(Transaction::query()->findOrFail($transaction->id));
        // 決済代行で返す時: 払われた支払い・そのアカウントの鍵・支払いの ID（ロックの外。Stripe に問い合わせることがある）。
        // 0 円の品だけを返す記録（返金 0 円）は決済代行に触れない
        $plan = $request->channel === 'provider' && $this->planner->plan($transaction, $request)->total > 0 ? $this->prepare($transaction) : null;

        $refund = DB::transaction(function () use ($transaction, $request, $expectedTotal, $actor, $plan): ?Refund {
            $locked = $this->lock($transaction);
            $this->assertReturnable($locked);

            if ($this->busy($locked)) {
                throw PaymentUnavailableException::conflict(self::BUSY);
            }

            $returnPlan = $this->planner->plan($locked, $request);

            if (($expectedTotal !== null && $expectedTotal !== $returnPlan->total) || ($request->channel === 'provider' && $returnPlan->total > 0 && $plan === null)) {
                throw PaymentUnavailableException::conflict(self::CHANGED);
            }

            // 決済代行で返せるのは、決済代行で受け取ってまだ返していない額まで（ギフトカードで払った分は決済代行では返せない。ADR 0076）
            if ($request->channel === 'provider' && $returnPlan->total > $this->books->room($locked) - app(GiftCardService::class)->heldInLedger($locked->id)) {
                throw PaymentUnavailableException::conflict(self::OVER_PROVIDER);
            }

            $return = OrderReturn::create(['transaction_id' => $locked->id, 'reason' => $request->reason, 'amount' => $returnPlan->total, 'taxes' => $returnPlan->taxes, 'actor' => $actor]);

            foreach ($returnPlan->lines as $row) {
                RefundLine::create([
                    'return_id' => $return->id, 'transaction_id' => $locked->id, 'kind' => $row['kind'], 'line_position' => $row['position'],
                    'quantity' => $row['quantity'], 'amount' => $row['amount'], 'tax_rate_bp' => $row['rate_bp'], 'restock' => $row['restock'],
                ]);

                if ($row['restock']) {
                    $this->restock($locked, (int) $row['position'], $row['quantity'], $actor);
                }
            }

            $this->events->append('order.return_recorded', 'transaction', $locked->id, ReturnSettlement::payload($return, $request->channel), $actor);

            // 0 円の品だけを返した（在庫の戻しの記録だけ）: 返金の記録は作らない
            if ($return->amount === 0) {
                return null;
            }

            $attempt = $plan['attempt'] ?? PaymentAttempt::query()->where('transaction_id', $locked->id)->where('status', 'paid')->first();

            return $this->refunds->openForReturn($locked, $return, $request->channel, $attempt, $plan['paymentRef'] ?? null, $actor);
        });

        return $plan === null || $refund === null ? $refund : $this->send($refund, $plan['credentials'], $actor);
    }

    /**
     * 断られた返金を頼み直す（返品・一部返金は決済代行か外で返した記録で、取り消した注文の取消の返金は決済代行で。ADR 0048 の 3）
     *
     * @param  'provider'|'outside'  $channel
     */
    public function retry(Transaction $transaction, string $refundId, string $channel, string $actor): Refund
    {
        $declined = Refund::query()->where('transaction_id', $transaction->id)->whereKey($refundId)->firstOrFail();

        if (! $this->retries->retryable($declined)) {
            throw PaymentUnavailableException::conflict(RefundRetries::NOT_RETRYABLE);
        }

        $plan = $channel === 'provider' ? $this->prepare($transaction) : null;

        $refund = DB::transaction(function () use ($transaction, $declined, $channel, $actor, $plan): Refund {
            $this->events->lock();
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $attempt = $plan['attempt'] ?? PaymentAttempt::query()->where('transaction_id', $locked->id)->where('status', 'paid')->first();

            return $this->retries->retryDeclined($locked, $declined, $channel, $attempt, $plan['paymentRef'] ?? null, $actor);
        });

        return $plan === null ? $refund : $this->send($refund, $plan['credentials'], $actor);
    }

    /**
     * 答えが分からないまま残った返品の返金を、同じ冪等キーで続きから（返金の見回りから。返金は 1 回）
     *
     * @return string 結果（resumed・declined・waiting）
     */
    public function resume(Refund $refund, string $actor): string
    {
        if ($refund->purpose !== 'return' || $refund->status !== 'requested') {
            throw new LogicException('応答を待つ返品の返金だけを続ける');
        }

        $plan = $this->prepare(Transaction::query()->findOrFail($refund->transaction_id));
        $after = $this->send($refund, $plan['credentials'], $actor)->status;

        return $after === 'failed' ? 'declined' : 'resumed';
    }

    /**
     * 返せる注文か: 物販で、閉じていない（開いている・済んだ）、発送した（受渡か受取確認・精算の段）注文
     */
    public function returnable(Transaction $transaction): bool
    {
        return $transaction->kind === 'sale'
            && in_array($transaction->status, [TransactionStatus::Open, TransactionStatus::Completed], true)
            && in_array($transaction->phase, [Phase::Fulfillment, Phase::Acceptance, Phase::Settlement], true)
            && (bool) $transaction->prices_include_tax;
    }

    /**
     * 決済代行で返す下ごしらえ（払われた支払い・そのアカウントの鍵・支払いの ID）。決済代行で受けた支払いが無ければ断る
     *
     * @return array{attempt: PaymentAttempt, credentials: PaymentCredentials, paymentRef: string}
     */
    private function prepare(Transaction $transaction): array
    {
        if (! $this->providerPaid($transaction)) {
            throw PaymentUnavailableException::conflict(self::NO_PROVIDER);
        }

        return $this->refunds->prepareCancel($transaction);
    }

    /**
     * 決済代行で受けた支払いがあるか。店が受け取る支払い（銀行振込・代金引換。kind = direct、ADR 0074）は数えない
     * （前は数えていて、銀行振込の注文の返品の画面に「Stripe で返金する」が出て、押すと Stripe の支払いが見つからずに断られた。で見つけた）
     */
    private function providerPaid(Transaction $transaction): bool
    {
        return PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('status', 'paid')->where('kind', '<>', 'direct')->exists();
    }

    private function assertReturnable(Transaction $transaction): void
    {
        if (! $this->returnable($transaction)) {
            throw new TransactionException(self::NOT_RETURNABLE);
        }
    }

    /**
     * 止めた返金（額が違う・まだ確かめていない外でした返金）か、途中の取消・送料の差額・返品の返金がある（二重に返さないよう、先に片付けてもらう）。
     * 途中の返品の返金は 1 注文に 1 つ（DB の一意の索引 refunds_one_active_return も守る）: 同じ支払い・同じ額の返金が 2 つ途中にあると、知らせを取り違えうる
     */
    public function busy(Transaction $transaction): bool
    {
        return Refund::query()->where('transaction_id', $transaction->id)
            ->where(static fn ($q) => $q->where('status', 'held')->orWhere(static fn ($w) => $w->whereIn('purpose', ['cancel', 'shipping', 'return'])->whereIn('status', ['requested', 'pending'])))
            ->exists();
    }

    /**
     * ② ロックの外で決済代行へ頼み、③ ロックの中で答えを記録する。額が違えば止めて HELD
     */
    private function send(Refund $refund, PaymentCredentials $credentials, string $actor): Refund
    {
        if ($refund->status !== 'requested') {
            return $refund;
        }

        $result = $this->refunds->send($refund, $credentials, $actor);

        $recorded = DB::transaction(function () use ($refund, $result, $actor): Refund {
            $this->lock(Transaction::query()->findOrFail($refund->transaction_id), open: false);

            return $this->refunds->settle($refund, $result, $actor);
        });

        if ($recorded->status === 'held') {
            throw PaymentUnavailableException::conflict(PaymentRefunds::HELD);
        }

        return $recorded;
    }

    /**
     * 返した品を在庫に戻す（種類 × 拠点の在庫。発送して完了の前なら押さえた数はそのままで、完了の時に出庫される。記録つき）。
     * セット商品は、押さえた時の構成の品へ戻す（セットそのものの在庫には入れない。LineStock の控えから）
     */
    private function restock(Transaction $locked, int $position, int $quantity, string $actor): void
    {
        $line = TransactionLine::query()->where('transaction_id', $locked->id)->where('position', $position)->firstOrFail();

        foreach ($this->lineStock->receive($line->id, $line->listing_id, (string) $line->variant_id, (string) $line->location_id, $quantity) as [$variant, $location, $received, $stock]) {
            $this->events->append('inventory.returned', 'listing', (string) $variant->listing_id, [
                'transaction' => $locked->id,
                'variant_id' => $variant->id,
                'location_id' => $location->id,
                'quantity' => $received,
                'quantity_after' => $stock->quantity,
                'reserved_after' => $stock->reserved,
            ], $actor);
        }
    }

    /**
     * 台帳のロック → 取引の行のロック（決まりの順）
     */
    private function lock(Transaction $transaction, bool $open = true): Transaction
    {
        $this->events->lock();
        $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

        if ($open && $locked->kind !== 'sale') {
            throw new TransactionException(self::NOT_RETURNABLE);
        }

        return $locked;
    }
}
