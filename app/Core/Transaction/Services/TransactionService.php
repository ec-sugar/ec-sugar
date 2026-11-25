<?php

declare(strict_types=1);

namespace App\Core\Transaction\Services;

use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\FulfillmentItem;
use App\Core\Inventory\Models\InventoryUnit;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\LineStock;
use App\Core\Inventory\Services\UnitService;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\SellerStatus;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Money\Money;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\Party;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\QuoteStatus;
use App\Core\Transaction\Enums\TaxRounding;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Quote;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Totals;
use App\Core\Transaction\TransactionFlows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 取引の開始・遷移・提示（CONCEPT 5.4、ADR 0011）。取引の状態を変えるのはこのクラスだけ。
 *
 * 遷移の規則:
 *   主フェーズ  … 種類の定義（TransactionFlow::transitions）にある遷移だけ。取引が open のときだけ
 *   取消        … open の間はどこからでも。在庫の確保を解き、合意の仕訳を打ち消して終える
 *   紛争        … open / completed からどこでも。入る前のフェーズを覚えておき、解決したらそこへ戻る
 *   返金        … 紛争中か completed から。合意の仕訳を打ち消して終える（お金の払い戻しは LedgerService::recordRefund）
 *
 * 合意（Agreement）に入るとき: 明細と出品者が揃っていることを確かめ、合意の仕訳を書き、物を出す流れなら在庫を確保する。
 * すべての変更は取引の行ロックの中で行い、同じ DB トランザクションでドメインイベントを積む。
 */
final class TransactionService
{
    public function __construct(
        private readonly TransactionFlows $flows,
        private readonly LedgerService $ledger,
        private readonly EventStore $events,
        private readonly LineStock $lineStock,
        private readonly UnitService $units,
    ) {}

    /**
     * @param  list<LineDraft>  $lines  開始フェーズが Agreement なら必須。Quote から始まる流れでは空でよい（提示で決まる）
     */
    public function open(
        string $kind,
        ?Seller $seller,
        ?Party $customer,
        array $lines = [],
        ?Transaction $parent = null,
        bool $pricesIncludeTax = true,
        TaxRounding $taxRounding = TaxRounding::Floor,
        string $currency = 'JPY',
        ?string $actor = null,
        ?CustomerAccount $customerAccount = null,
    ): Transaction {
        $flow = $this->flows->get($kind);

        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new TransactionException("通貨コードが不正です: {$currency}");
        }

        if ($seller !== null) {
            $this->assertSellerActive($seller);
        }

        return DB::transaction(function () use ($flow, $seller, $customer, $lines, $parent, $pricesIncludeTax, $taxRounding, $currency, $actor, $customerAccount): Transaction {
            $this->events->lock();

            if ($parent !== null) {
                $parent = $this->lock($parent);

                if ($parent->status->isClosed()) {
                    throw new TransactionException('終わった取引の下に子の取引は作れません');
                }
            }

            $transaction = Transaction::create([
                'parent_id' => $parent?->id,
                'kind' => $flow->kind,
                'phase' => $flow->initial,
                'seller_id' => $seller?->id,
                'customer_id' => $customer?->id,
                'customer_account_id' => $customerAccount?->id,
                'payer' => $flow->payer,
                'payee' => $flow->payee,
                'currency' => $currency,
                'prices_include_tax' => $pricesIncludeTax,
                'tax_rounding' => $taxRounding,
            ]);

            if ($lines !== []) {
                if ($seller === null) {
                    throw new TransactionException('明細を持つ取引には販売者が要ります');
                }
                $this->writeLines($transaction, $seller, $lines);
            }

            $this->events->append('transaction.opened', 'transaction', $transaction->id, [
                'kind' => $transaction->kind,
                'phase' => $transaction->phase->value,
                'parent_id' => $transaction->parent_id,
                'seller_id' => $transaction->seller_id,
                'customer_id' => $transaction->customer_id,
                'customer_account_id' => $transaction->customer_account_id,
                'total_amount' => $transaction->total_amount,
                'currency' => $transaction->currency,
            ], $actor);

            if ($flow->initial === Phase::Agreement) {
                $this->enterAgreement($transaction, $actor);
                $transaction->save();
            }

            return $transaction;
        });
    }

    public function transition(Transaction $transaction, Phase $to, ?string $actor = null): Transaction
    {
        return DB::transaction(fn (): Transaction => $this->transitionLocked($this->lock($transaction), $to, $actor));
    }

    /**
     * @param  list<LineDraft>  $lines
     */
    public function propose(Transaction $transaction, Seller $seller, array $lines, ?CarbonImmutable $validUntil = null, ?string $actor = null): Quote
    {
        if ($lines === []) {
            throw new TransactionException('提示には明細が要ります');
        }

        $this->assertSellerActive($seller);

        return DB::transaction(function () use ($transaction, $seller, $lines, $validUntil, $actor): Quote {
            $locked = $this->lock($transaction);
            $this->assertQuoting($locked);

            if ($locked->seller_id !== null && $locked->seller_id !== $seller->id) {
                throw new TransactionException('この取引には別の販売者が決まっています');
            }

            $this->assertLinesBelongTo($seller, $lines);

            // 提示は jsonb に平文で残るので、オプションの入れた文字（個人情報になりうる）は持たせない（ADR 0010・0036）
            foreach ($lines as $line) {
                if ($line->addonText !== null) {
                    throw new TransactionException('提示の明細には、オプションの入れた文字を入れられません');
                }
            }

            $totals = Totals::of($lines, $locked->prices_include_tax, $locked->tax_rounding);

            $quote = Quote::create([
                'transaction_id' => $locked->id,
                'seller_id' => $seller->id,
                'lines' => array_map(static fn (LineDraft $line): array => $line->toArray(), $lines),
                'subtotal_amount' => $totals->subtotal,
                'tax_amount' => $totals->tax,
                'total_amount' => $totals->total,
                'valid_until' => $validUntil,
            ]);

            $this->events->append('quote.proposed', 'transaction', $locked->id, [
                'quote_id' => $quote->id,
                'seller_id' => $seller->id,
                'total_amount' => $totals->total,
                'valid_until' => $validUntil?->utc()->format('Y-m-d\TH:i:s\Z'),
            ], $actor);

            return $quote;
        });
    }

    /**
     * 提示を採る。提示の明細が取引の明細になり、他の提示は断ったことになり、取引は Agreement に進む。
     */
    public function acceptQuote(Quote $quote, ?string $actor = null): Transaction
    {
        return DB::transaction(function () use ($quote, $actor): Transaction {
            $transaction = $this->lock($quote->transaction()->firstOrFail());
            $this->assertQuoting($transaction);

            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->status !== QuoteStatus::Proposed) {
                throw new TransactionException("この提示は採れません（{$quote->status->value}）");
            }

            if ($quote->isExpired()) {
                throw new TransactionException('提示の有効期限が切れています');
            }

            if ($transaction->seller_id !== null && $transaction->seller_id !== $quote->seller_id) {
                throw new TransactionException('この取引には別の販売者が決まっています');
            }

            $seller = $quote->seller()->firstOrFail();
            $this->assertSellerActive($seller);

            // 前の合意で確保した在庫があれば解いてから明細を差し替える
            $this->releaseOutstanding($transaction);
            $transaction->lines()->delete();
            $transaction->seller_id = $seller->id;
            $this->writeLines($transaction, $seller, $quote->drafts());

            $quote->status = QuoteStatus::Accepted;
            $quote->decided_at = CarbonImmutable::now();
            $quote->save();

            Quote::query()
                ->where('transaction_id', $transaction->id)
                ->where('status', QuoteStatus::Proposed)
                ->update(['status' => QuoteStatus::Rejected, 'decided_at' => CarbonImmutable::now()]);

            $this->events->append('quote.accepted', 'transaction', $transaction->id, [
                'quote_id' => $quote->id,
                'seller_id' => $seller->id,
                'total_amount' => $transaction->total_amount,
            ], $actor);

            return $this->transitionLocked($transaction, Phase::Agreement, $actor);
        });
    }

    public function rejectQuote(Quote $quote, ?string $actor = null): Quote
    {
        return $this->decideQuote($quote, QuoteStatus::Rejected, 'quote.rejected', $actor);
    }

    public function withdrawQuote(Quote $quote, ?string $actor = null): Quote
    {
        return $this->decideQuote($quote, QuoteStatus::Withdrawn, 'quote.withdrawn', $actor);
    }

    private function transitionLocked(Transaction $transaction, Phase $to, ?string $actor): Transaction
    {
        $from = $transaction->phase;
        $status = $transaction->status;
        $flow = $transaction->flow();

        if ($status->isClosed()) {
            throw new TransactionException("終わった取引は動かせません（{$status->value}）");
        }

        $allowed = match (true) {
            $to === Phase::Cancel => $status === TransactionStatus::Open,
            $to === Phase::Dispute => $from !== Phase::Dispute,
            $to === Phase::Refund => $from === Phase::Dispute || $status === TransactionStatus::Completed,
            $from === Phase::Dispute => $to === $transaction->suspended_phase,
            default => $status === TransactionStatus::Open && $flow->allows($from, $to),
        };

        if (! $allowed) {
            throw new TransactionException("{$transaction->kind} の取引は {$from->value} から {$to->value} へ進めません");
        }

        // 返金済みにするのは、受け取ったお金をすべて返金として台帳に書いた後だけ（決済代行での返金と台帳を食い違わせない）
        if ($to === Phase::Refund && ($kept = $this->ledger->netPaid($transaction)) !== 0) {
            throw new TransactionException('受け取ったお金がまだ '.Money::format($kept, $transaction->currency).' 残っています。返金を台帳に書いてから、返金済みにしてください');
        }

        switch ($to) {
            case Phase::Cancel:
            case Phase::Refund:
                $this->releaseOutstanding($transaction);
                $this->ledger->reverseAgreement($transaction, $actor);
                $transaction->status = $to === Phase::Cancel ? TransactionStatus::Cancelled : TransactionStatus::Refunded;
                $transaction->closed_at = CarbonImmutable::now();
                $transaction->suspended_phase = null;
                break;

            case Phase::Dispute:
                $transaction->suspended_phase = $from;
                break;

            case Phase::Agreement:
                $this->enterAgreement($transaction, $actor);
                break;

            default:
                break;
        }

        if ($from === Phase::Dispute && ! $to->isCrossCutting()) {
            $transaction->suspended_phase = null;
        }

        if ($status === TransactionStatus::Open && $flow->completes($to)) {
            $transaction->status = TransactionStatus::Completed;
            $transaction->completed_at = CarbonImmutable::now();
        }

        $transaction->phase = $to;
        $transaction->save();

        $this->events->append('transaction.phase_changed', 'transaction', $transaction->id, [
            'kind' => $transaction->kind,
            'from' => $from->value,
            'to' => $to->value,
            'status' => $transaction->status->value,
        ], $actor);

        return $transaction;
    }

    private function enterAgreement(Transaction $transaction, ?string $actor): void
    {
        if ($transaction->seller_id === null || ! $transaction->lines()->exists()) {
            throw new TransactionException('合意には販売者と 1 行以上の明細が要ります');
        }

        $this->ledger->recordAgreement($transaction, $actor);

        if ($transaction->flow()->reservesInventory && $transaction->agreed_at === null) {
            foreach ($transaction->lines()->where('line_type', LineType::Item)->get() as $line) {
                if ($line->inventory_unit_id !== null) {
                    $this->units->reserve($line->unit()->firstOrFail());
                } elseif ($line->variant_id !== null && $line->location_id !== null) {
                    // セット商品は構成する品から引く。1 つでも足りなければ例外でトランザクションごと戻る
                    $this->lineStock->reserve($line->id, $line->listing_id, (string) $line->variant_id, (string) $line->location_id, $line->quantity);
                }
            }
        }

        // agreed_at が入っている間は「確保を持っている」の意味も兼ねる（解いたら null に戻す）
        $transaction->agreed_at = CarbonImmutable::now();
    }

    /**
     * 合意で確保して、まだ受渡で出庫していない分を解く。
     */
    private function releaseOutstanding(Transaction $transaction): void
    {
        if ($transaction->agreed_at === null || ! $transaction->flow()->reservesInventory) {
            $transaction->agreed_at = null;

            return;
        }

        /** @var array<string, int> $fulfilled */
        $fulfilled = FulfillmentItem::query()
            ->join('fulfillments', 'fulfillments.id', '=', 'fulfillment_items.fulfillment_id')
            ->where('fulfillments.transaction_id', $transaction->id)
            ->where('fulfillments.status', FulfillmentStatus::Completed->value)
            ->groupBy('fulfillment_items.transaction_line_id')
            ->selectRaw('fulfillment_items.transaction_line_id AS line_id, SUM(fulfillment_items.quantity)::integer AS quantity')
            ->pluck('quantity', 'line_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        foreach ($transaction->lines()->where('line_type', LineType::Item)->get() as $line) {
            $outstanding = $line->quantity - ($fulfilled[$line->id] ?? 0);

            if ($outstanding <= 0) {
                continue;
            }

            if ($line->inventory_unit_id !== null) {
                $this->units->release($line->unit()->firstOrFail());
            } elseif ($line->variant_id !== null && $line->location_id !== null) {
                $this->lineStock->release($line->id, $line->listing_id, (string) $line->variant_id, (string) $line->location_id, $outstanding);
            }
        }

        $transaction->agreed_at = null;
    }

    /**
     * @param  list<LineDraft>  $lines
     */
    private function writeLines(Transaction $transaction, Seller $seller, array $lines): void
    {
        $lines = $this->assertLinesBelongTo($seller, $lines);
        $totals = Totals::of($lines, $transaction->prices_include_tax, $transaction->tax_rounding);

        foreach ($lines as $position => $line) {
            TransactionLine::create(['transaction_id' => $transaction->id, 'position' => $position + 1] + $line->toArray());
        }

        $transaction->subtotal_amount = $totals->subtotal;
        $transaction->tax_amount = $totals->tax;
        $transaction->total_amount = $totals->total;
        $transaction->save();
    }

    /**
     * 明細が指す出品物・拠点・個体が、その出品者のものであること。
     * マーケットモードで他の出品者の商品や在庫を自分の取引に入れられないようにする（テナント内の境界）。
     * 商品の明細は種類も指す（ADR 0036）。種類の前に保存した提示の明細は、軸の無い商品なら、ただ 1 つの種類を入れて返す。
     *
     * @param  list<LineDraft>  $lines
     * @return list<LineDraft>
     */
    private function assertLinesBelongTo(Seller $seller, array $lines): array
    {
        foreach ($lines as $index => $line) {
            if ($line->listingId !== null) {
                $listing = Listing::query()->whereKey($line->listingId)->where('seller_id', $seller->id)->first();

                if ($listing === null || $listing->status !== ListingStatus::Active) {
                    throw new TransactionException('明細の出品物が見つからないか、公開中ではありません');
                }

                if ($line->variantId === null) {
                    $sole = $listing->soleVariant() ?? throw new TransactionException('明細が種類を指していません（種類のある商品）');
                    $lines[$index] = $line = $line->withVariant($sole);
                }

                $variant = ListingVariant::query()->whereKey($line->variantId)->where('listing_id', $listing->id)->first();

                if ($variant === null || $variant->status !== VariantStatus::Active) {
                    throw new TransactionException('明細の種類が見つからないか、販売中ではありません');
                }
            }

            if ($line->locationId !== null
                && ! Location::query()->whereKey($line->locationId)->where('seller_id', $seller->id)->exists()) {
                throw new TransactionException('明細の拠点がこの販売者のものではありません');
            }

            if ($line->inventoryUnitId !== null) {
                $unit = InventoryUnit::query()
                    ->whereKey($line->inventoryUnitId)
                    ->whereHas('location', static fn ($query) => $query->where('seller_id', $seller->id))
                    ->first();

                if ($unit === null) {
                    throw new TransactionException('明細の個体がこの販売者のものではありません');
                }

                if ($line->listingId !== null && $unit->listing_id !== $line->listingId) {
                    throw new TransactionException('明細の個体と出品物が一致しません');
                }

                if ($line->locationId !== null && $unit->location_id !== $line->locationId) {
                    throw new TransactionException('明細の個体と拠点が一致しません');
                }

                if ($line->variantId !== null && $unit->variant_id !== $line->variantId) {
                    throw new TransactionException('明細の個体と種類が一致しません');
                }
            }
        }

        return $lines;
    }

    private function decideQuote(Quote $quote, QuoteStatus $status, string $eventType, ?string $actor): Quote
    {
        return DB::transaction(function () use ($quote, $status, $eventType, $actor): Quote {
            $this->events->lock();
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== QuoteStatus::Proposed) {
                throw new TransactionException("この提示はもう決まっています（{$locked->status->value}）");
            }

            $locked->status = $status;
            $locked->decided_at = CarbonImmutable::now();
            $locked->save();

            $this->events->append($eventType, 'transaction', $locked->transaction_id, ['quote_id' => $locked->id], $actor);

            return $locked;
        });
    }

    private function assertQuoting(Transaction $transaction): void
    {
        if ($transaction->status !== TransactionStatus::Open || $transaction->phase !== Phase::Quote) {
            throw new TransactionException('提示を受けられるのは Quote フェーズの取引だけです');
        }
    }

    private function assertSellerActive(Seller $seller): void
    {
        if ($seller->status !== SellerStatus::Active) {
            throw new TransactionException('停止中の販売者は取引できません');
        }
    }

    private function lock(Transaction $transaction): Transaction
    {
        $this->events->lock();

        return Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
    }
}
