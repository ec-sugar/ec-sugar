<?php

declare(strict_types=1);

namespace App\Admin\Http\Presenters;

use App\Admin\Models\Operator;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Inventory\Models\Location;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Models\LedgerPosting;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Seller;
use App\Core\Order\AgeChecks;
use App\Core\Order\Gifts;
use App\Core\Order\Services\OrderFields;
use App\Core\Order\Services\OrderReturns;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Order\Services\ShippingDifferenceRefunds;
use App\Core\Payment\Models\OrderReturn;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\RefundLine;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Payment\Services\PaymentService;
use App\Core\Payment\Services\RefundRetries;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Quote;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\TaxBreakdown;
use App\Core\Transaction\TransactionFlow;
use App\Storefront\Orders\SentMail;

/**
 * 取引の詳細を、管理画面に渡す形にする（整理で TransactionController から分けた）。
 * 個人情報（顧客の名前・連絡先・住所、配送先、追跡番号）は入れない（personal() でだけ返す）
 */
final class TransactionPresenter
{
    // 詳細に出す記録（イベント）の上限。取引ごとの記録は多くないが、上限を付けて応答を膨らませない
    private const int MAX_EVENTS = 200;

    /** 贈り物の様子（ADR 0055）の店の人向けの言葉 */
    private const array GIFT_LABELS = [
        'preparing' => '贈り物・お支払いの確認待ち',
        'waiting' => '贈り物・受け取り待ち',
        'claimed' => '贈り物・受け取り済み',
        'shipped' => '贈り物・発送済み',
        'cancelling' => '贈り物・取り消し中',
        'stopped' => '贈り物・返金できなかった（要確認）',
        'cancelled' => '贈り物・取り消し済み',
        'expired' => '贈り物・期限切れで返金',
    ];

    public function __construct(
        private readonly SaleOrderService $orders,
        private readonly LedgerService $ledger,
        private readonly Gifts $gifts,
        private readonly PaymentService $payments,
        private readonly ShippingDifferenceRefunds $shippingRefunds,
        private readonly OrderFields $orderFields,
        private readonly OrderReturns $returns,
        private readonly RefundRetries $retries,
        private readonly GiftCardService $giftCards,
        private readonly AgeChecks $ageChecks,
    ) {}

    /**
     * @return array{last4: string, amount: int, due: int, released: int, releasable: int}|null
     */
    private function giftCard(Transaction $transaction): ?array
    {
        $used = $this->giftCards->forOrder($transaction->id);

        return $used === null ? null : [
            'last4' => $used['last4'],
            'amount' => $used['amount'],
            'due' => OrderGiftCard::dueOf($transaction),
            'released' => $used['released'],
            'releasable' => $this->giftCards->releasable($transaction->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Transaction $transaction): array
    {
        $locations = Location::query()->pluck('name', 'id');
        $sellers = Seller::query()->pluck('name', 'id');

        $journals = LedgerJournal::query()->where('transaction_id', $transaction->id)->orderBy('occurred_at')->orderBy('id')->get();
        $postings = LedgerPosting::query()->where('transaction_id', $transaction->id)->orderBy('account')->get()->groupBy('journal_id');

        $sale = $transaction->kind === 'sale';
        $fulfillmentRows = $transaction->fulfillments()->get();
        // 取消の返金を額が違って止めている: 人が Stripe で確かめるまで、発送・完了・取消のボタンを出さない
        $held = $sale && Refund::query()->where('transaction_id', $transaction->id)->where('purpose', 'cancel')->where('status', 'held')->exists();
        // 店が受け取る支払い（銀行振込・代引き。ADR 0074）の記録。入金の確認・受取拒否・返金の記録のボタンを決める
        $direct = $sale ? PaymentAttempt::direct($transaction->id) : null;
        $open = $transaction->status === TransactionStatus::Open;
        $directPaid = $direct?->status === 'paid' ? $this->ledger->netPaid($transaction) : 0;

        return [
            'id' => $transaction->id,
            'kind' => $transaction->kind,
            'phase' => $transaction->phase->value,
            // 物販の対応の状況と、今できる操作（画面はこれでボタンを出す。できるかどうかはサーバーが操作のたびに確かめ直す）
            'order_status' => $sale ? $this->orders->status($transaction)->value : null,
            'actions' => [
                // 送り先を待つ受渡（贈り物の受け取り待ち。ADR 0055）と、発売日の前の予約の品の受渡（ADR 0073）は発送できないので数えない。来店で受け渡す受渡（予約。役務）は
                // 発送しない（App「予約」の画面の「来店済み」で済みにする）
                'ship' => $sale && ! $held && $transaction->status === TransactionStatus::Open && in_array($transaction->phase, [Phase::Payment, Phase::Fulfillment], true)
                    && $fulfillmentRows->contains(static fn (Fulfillment $f): bool => $f->status === FulfillmentStatus::Pending && ! $f->awaitingAddress() && $f->method !== FulfillmentMethod::InPerson && ! $f->heldForRelease()),
                // 役務（設置の代行など）だけの注文の「提供済みにする」: 支払いが済み、役務の受渡が残っている時（予約の品は App「予約」の画面で済ませる）
                'deliver_service' => $sale && ! $held && $open && $transaction->phase === Phase::Payment
                    && $fulfillmentRows->contains(static fn (Fulfillment $f): bool => $f->status === FulfillmentStatus::Pending && $f->method === FulfillmentMethod::InPerson)
                    && $this->orders->isServiceOrder($transaction),
                // 代引きは入金を確かめてから完了にする（ADR 0074）
                'complete' => $sale && ! $held && $transaction->status === TransactionStatus::Open && $transaction->phase === Phase::Fulfillment
                    && ! $fulfillmentRows->contains(static fn (Fulfillment $f): bool => $f->status === FulfillmentStatus::Pending)
                    && ! (in_array($direct?->method, ['cod', 'on_site'], true) && $direct->status === 'awaiting'),
                'cancel' => ! $held && $this->orders->cancellable($transaction),
                // 断られた贈り物の送料の差額の返金を頼み直す（ADR 0064。お金の段階）。頼み直す時にロックの中で確かめ直す
                'retry_shipping_refund' => $sale && $this->shippingRefunds->retryable($transaction),
                // 銀行振込の入金待ち・代引きと予約の当日払いで払う前の注文の「入金を確認した」（お金の段階。ADR 0074）
                'confirm_payment' => $open && $direct?->status === 'awaiting'
                    && (in_array($direct->method, ['cod', 'on_site'], true) ? in_array($transaction->phase, [Phase::Payment, Phase::Fulfillment], true) : $transaction->phase === Phase::Agreement),
                // 発送した代引きの注文の「受取拒否で取り消す」
                'refuse_cod' => $open && $direct?->method === 'cod' && $direct->status === 'awaiting'
                    && $fulfillmentRows->contains(static fn (Fulfillment $f): bool => $f->status === FulfillmentStatus::Shipped),
                // 銀行振込・代引きで受け取った額の「返金を記録する」（受け取って、まだ返していない額がある時）
                'record_refund' => $directPaid > 0,
                // 使ったギフトカードの額を、店の人が選んでカードへ戻す（外でした返金の後など。ADR 0076。お金の段階）。払う前の注文は自動で戻る
                'release_gift_card' => $sale && $transaction->phase !== Phase::Agreement && $this->giftCards->releasable($transaction->id) > 0,
            ],
            // ギフトカード（ADR 0076）: 支払いの内訳（ギフトカード −¥3,000／お支払い ¥7,000）と、カードへ戻した額・まだ戻せる額。使っていない注文は null
            'gift_card' => $sale ? $this->giftCard($transaction) : null,
            // 贈り物の注文（ADR 0055）: 様子と受け取りの期限（「贈り物・受け取り待ち」）。贈り物の App を使うのをやめた後は様子が分からないので「贈り物」だけ
            'gift' => $sale ? $this->gift($transaction) : null,
            // 年齢の確かめをした注文（ADR 0077）: 「年齢確認済み（20 歳以上）」の札。生年月日は持っていない。App をやめた後も出す
            'age_check' => $sale ? ($this->ageChecks->verifiedFor([$transaction->id])[$transaction->id] ?? null) : null,
            // 支払いの様子（払われた手段・コンビニ払いの入金待ちと払いの期限。ADR 0058）。払込票の画面はお客さんにだけ出すので入れない
            'payment' => $sale ? $this->payment($transaction) : null,
            // 取り消すと返金する額（払われた Stripe の支払い）。確認の文に出す
            // 銀行振込・代引きの注文は取消で返金しない（先に返金を記録する。ADR 0074）
            'refundable_amount' => $direct === null ? (int) PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('status', 'paid')->value('amount') : 0,
            // 銀行振込・代引きで受け取って、まだ返していない額（返金の記録の上限）
            'direct_refundable' => $directPaid,
            // お客さんへ送ったメールの履歴（宛先は残していない）
            'sent_mails' => SentMail::query()->where('transaction_id', $transaction->id)->orderBy('created_at')->get()->map(static fn (SentMail $mail): array => [
                'kind' => $mail->kind,
                'subject' => $mail->subject,
                'status' => $mail->status,
                'sent_at' => $mail->sent_at?->toIso8601String(),
            ])->values(),
            // 返金が要る支払い（二重に払われた・取り消した注文に届いた）
            'stray_payments' => StrayPayment::query()->where('transaction_id', $transaction->id)->orderBy('created_at')->get()->map(static fn (StrayPayment $stray): array => [
                'id' => $stray->id,
                'reason' => $stray->reason,
                'amount' => $stray->amount,
                'status' => $stray->status,
                'refundable' => $stray->status === 'pending' && $stray->payment_ref !== null && str_starts_with($stray->payment_ref, 'pi_'),
                'created_at' => $stray->created_at->toIso8601String(),
                'refunded_at' => $stray->refunded_at?->toIso8601String(),
            ])->values(),
            // 返金の記録（ADR 0027）。保留・失敗・戻されたものを画面で知らせる。理由は Stripe の符号だけ
            'refunds' => Refund::query()->where('transaction_id', $transaction->id)->orderBy('created_at')->orderBy('id')->get()->map(fn (Refund $refund): array => [
                'id' => $refund->id,
                'purpose' => $refund->purpose,
                // 返し方（provider: Stripe・outside: 店が外で返した記録）と返金の 1 回（ADR 0048）
                'channel' => $refund->channel,
                'return_id' => $refund->return_id,
                // 断られた返品の返金・取り消した注文の返金は、店の人が頼み直せる（ADR 0048 の 3。頼み直す時にロックの中で確かめ直す）
                'retryable' => $this->retries->retryable($refund),
                'amount' => $refund->amount,
                'status' => $refund->status,
                'failure_reason' => $refund->failure_reason,
                'created_at' => $refund->created_at->toIso8601String(),
                'settled_at' => $refund->settled_at?->toIso8601String(),
                // 止めた返金は、店の人が Stripe で確かめた後に片付けられる。片付けた結果は recorded・dismissed
                'resolvable' => $refund->status === 'held',
                'resolution' => $refund->resolution,
            ])->values(),
            // 返した額の合計と見せ方（一部返金・全額返金。取引の状態は変えない。ADR 0048）
            'refund_summary' => $sale ? $this->refundSummary($transaction) : null,
            // 返品・一部返金の画面（返せる数と額の目安・返せる額・理由の候補）と、返金の履歴（日時・額・状態・誰が）
            'returns' => $sale ? [...$this->returns->options($transaction), 'history' => $this->returnHistory($transaction)] : null,
            'carriers' => SaleOrderService::CARRIERS,
            // 入金日（支払いの仕訳の最初の日）と出荷日（最初の発送の日）。日本の EC の受注の画面でよく見る項目
            'paid_at' => $journals->first(static fn (LedgerJournal $j): bool => $j->type === JournalType::Payment)?->occurred_at->toIso8601String(),
            'shipped_at' => $fulfillmentRows->min('shipped_at')?->toIso8601String(),
            // 税率ごとの対象額と消費税（「10% 対象 ¥54,900（内消費税 ¥4,991）」。領収書・問い合わせの対応に）
            'tax_by_rate' => self::taxByRate($transaction),
            'suspended_phase' => $transaction->suspended_phase?->value,
            'status' => $transaction->status->value,
            'kind_label' => $transaction->flow()->label,
            'path' => self::mainPath($transaction->flow()),
            'parent_id' => $transaction->parent_id,
            'children' => $transaction->children()->orderBy('created_at')->pluck('id'),
            'seller' => $transaction->seller_id === null ? null : ['id' => $transaction->seller_id, 'name' => $sellers->get($transaction->seller_id)],
            'has_customer' => $transaction->customer_id !== null,
            'payer' => $transaction->payer->value,
            'payee' => $transaction->payee->value,
            'currency' => $transaction->currency,
            'prices_include_tax' => $transaction->prices_include_tax,
            'tax_rounding' => $transaction->tax_rounding->value,
            'subtotal_amount' => $transaction->subtotal_amount,
            'tax_amount' => $transaction->tax_amount,
            'total_amount' => $transaction->total_amount,
            'created_at' => $transaction->created_at?->toIso8601String(),
            'agreed_at' => $transaction->agreed_at?->toIso8601String(),
            'completed_at' => $transaction->completed_at?->toIso8601String(),
            'closed_at' => $transaction->closed_at?->toIso8601String(),
            'lines' => $transaction->lines()->orderBy('position')->get()->map(static fn (TransactionLine $line): array => [
                'id' => $line->id,
                'position' => $line->position,
                'line_type' => $line->line_type->value,
                'kind' => $line->kind,
                'listing_id' => $line->listing_id,
                'location' => $line->location_id === null ? null : $locations->get($line->location_id),
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount,
                'amount' => $line->amount,
                'tax_rate_bp' => $line->tax_rate_bp,
            ])->values(),
            'quotes' => $transaction->quotes()->orderBy('created_at')->get()->map(static fn (Quote $quote): array => [
                'id' => $quote->id,
                'seller' => ['id' => $quote->seller_id, 'name' => $sellers->get($quote->seller_id)],
                'status' => $quote->status->value,
                'total_amount' => $quote->total_amount,
                'valid_until' => $quote->valid_until?->toIso8601String(),
                'decided_at' => $quote->decided_at?->toIso8601String(),
            ])->values(),
            'journals' => $journals->map(static fn (LedgerJournal $journal): array => [
                'id' => $journal->id,
                'type' => $journal->type->value,
                'reference' => $journal->reference,
                'reverses_journal_id' => $journal->reverses_journal_id,
                'occurred_at' => $journal->occurred_at->toIso8601String(),
                'postings' => ($postings->get($journal->id) ?? collect())->map(static fn (LedgerPosting $posting): array => [
                    'account' => $posting->account->value,
                    'amount' => $posting->amount,
                ])->values(),
            ])->values(),
            'balances' => $this->ledger->balances($transaction),
            // 注文の項目（ADR 0066。注文の時の欄の名前と種類で）。選ぶ・チェックの値は店が決めた選択肢なのでここで出す。
            // 自由な文（1 行・複数行）は個人情報を含みうるので、値を入れず personal()（見た記録が残る）でだけ返す
            'order_fields' => array_map(static fn (array $f): array => [
                'name' => $f['name'],
                'type' => $f['type']->value,
                'value' => $f['type']->freeText() ? null : $f['label'],
                'private' => $f['type']->freeText(),
            ], $this->orderFields->forTransaction($transaction->id)),
            // 受渡は方法と状態だけ。配送先と追跡番号は個人情報として personal() で返す
            'fulfillments' => $transaction->fulfillments()->orderBy('created_at')->get()->map(static fn (Fulfillment $fulfillment): array => [
                'id' => $fulfillment->id,
                'method' => $fulfillment->method->value,
                'status' => $fulfillment->status->value,
                'location' => $fulfillment->location_id === null ? null : $locations->get($fulfillment->location_id),
                'carrier' => $fulfillment->carrier,
                // 配送の方法の名前（注文の時に写したもの。方法の違う品は受渡が分かれる。ADR 0064）
                'shipping_method' => $fulfillment->shipping_method,
                'has_address' => $fulfillment->getRawOriginal('address') !== null,
                // 送り先を待つ（贈り物の受け取り待ち。発送できない）
                'awaiting_address' => $fulfillment->awaitingAddress(),
                // お届け日時のご希望（ADR 0059）。個人情報ではないので、ここで出す（指定なしは null）
                'delivery' => ($wish = $fulfillment->delivery()) === null ? null : [...$wish->toArray(), 'label' => $wish->label()],
                // 発送を始める日（予約の品の発売日。ADR 0073）と、その日の前で発送できないか
                'ships_on' => $fulfillment->shipsOn(),
                'held_for_release' => $fulfillment->heldForRelease(),
                'shipped_at' => $fulfillment->shipped_at?->toIso8601String(),
                'completed_at' => $fulfillment->completed_at?->toIso8601String(),
            ])->values(),
            // 記録（イベント）。payload には個人情報を入れない決まり（ADR 0010）なので、そのまま出す
            'events' => DomainEvent::query()
                ->where('aggregate_type', 'transaction')->where('aggregate_id', $transaction->id)
                ->orderBy('sequence')->limit(self::MAX_EVENTS)->get()
                ->map(static fn (DomainEvent $event): array => [
                    'sequence' => $event->sequence,
                    'type' => $event->type,
                    'actor' => $event->actor,
                    'payload' => $event->payload,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                    'hash' => substr($event->hash, 0, 12),
                ])->values(),
        ];
    }

    /**
     * 返した額（済んだ返品・一部返金・記録した外の返金・送料の差額の返金）と、一部返金・全額返金の見せ方。返品・外の返金が無ければ見せ方は null
     *
     * @return array{refunded: int, state: 'partial'|'full'|null}
     */
    private function refundSummary(Transaction $transaction): array
    {
        $done = Refund::query()->where('transaction_id', $transaction->id)->where('status', 'succeeded')->whereIn('purpose', ['return', 'external', 'shipping']);
        $refunded = (int) (clone $done)->sum('amount');
        $returned = (clone $done)->whereIn('purpose', ['return', 'external'])->exists();

        return [
            'refunded' => $refunded,
            'state' => ! $returned || $transaction->status->isClosed() ? null : ($this->ledger->netPaid($transaction) <= 0 ? 'full' : 'partial'),
        ];
    }

    /**
     * 返金の履歴（新しい順。返金の 1 回ごとに、行・税率ごとの額・今の返金の状態・誰が）。個人情報は入れない（店の人の名前は運営の画面の中の名前）
     *
     * @return list<array<string, mixed>>
     */
    private function returnHistory(Transaction $transaction): array
    {
        $returns = OrderReturn::query()->where('transaction_id', $transaction->id)->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        if ($returns->isEmpty()) {
            return [];
        }

        $descriptions = TransactionLine::query()->where('transaction_id', $transaction->id)->pluck('description', 'position');
        $lines = RefundLine::query()->whereIn('return_id', $returns->pluck('id'))->orderBy('created_at')->orderBy('id')->get()->groupBy('return_id');
        $refunds = Refund::query()->whereIn('return_id', $returns->pluck('id'))->orderBy('created_at')->orderBy('id')->get()->groupBy('return_id');
        $operatorIds = $returns->map(static fn (OrderReturn $r): ?string => is_string($r->actor) && str_starts_with($r->actor, 'operator:') ? substr($r->actor, 9) : null)->filter()->unique()->values();
        $names = $operatorIds->isEmpty() ? collect() : Operator::query()->whereIn('id', $operatorIds)->pluck('name', 'id');

        return $returns->map(static function (OrderReturn $return) use ($descriptions, $lines, $refunds, $names): array {
            $latest = ($refunds->get($return->id) ?? collect())->last();
            $operator = is_string($return->actor) && str_starts_with($return->actor, 'operator:') ? substr($return->actor, 9) : null;

            return [
                'id' => $return->id,
                'created_at' => $return->created_at->toIso8601String(),
                // 返した額（行の合計。額が違って止めた返金を確かめた額で記録した時は、直しの行で確かめた額にそろう）
                'amount' => (int) ($lines->get($return->id) ?? collect())->sum('amount'),
                'reason' => $return->reason->value,
                'reason_label' => $return->reason->label(),
                'taxes' => $return->taxes,
                // 誰が: 運営の名前（見つからなければ actor の種類だけ）
                'by' => $operator !== null ? ($names->get($operator) ?? '管理者') : match (true) {
                    $return->actor === null => null,
                    str_starts_with($return->actor, 'webhook:') => '決済代行の通知',
                    default => 'システム',
                },
                'refund' => $latest === null ? null : ['id' => $latest->id, 'status' => $latest->status, 'channel' => $latest->channel, 'settled_at' => $latest->settled_at?->toIso8601String()],
                'lines' => ($lines->get($return->id) ?? collect())->map(static fn (RefundLine $line): array => [
                    'kind' => $line->kind,
                    'position' => $line->line_position,
                    'description' => $line->line_position === null ? null : $descriptions->get($line->line_position),
                    'quantity' => $line->quantity,
                    'amount' => $line->amount,
                    'rate_bp' => $line->tax_rate_bp,
                    'restock' => $line->restock,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * 振込先は返さない（店の設定にある。注文の時の写しはお客さんの画面とメールにだけ）
     *
     * test: Stripe のテストのモードで払われた・払う途中の支払い（公開の前の試し。本物のお金は動いていない。ADR 0096）
     *
     * @return array{method: string|null, method_label: string|null, awaiting: bool, pay_by: string|null, direct: bool, on_delivery: bool, test: bool}|null
     */
    private function payment(Transaction $transaction): ?array
    {
        $state = $this->payments->state($transaction);
        $open = $transaction->status === TransactionStatus::Open;

        return $state === null ? null : [
            'method' => $state->method,
            'method_label' => $state->label,
            'awaiting' => $state->awaiting && $open,
            'pay_by' => $state->payBy?->toIso8601String(),
            // 店が受け取る支払い（銀行振込・代引き。ADR 0074）と、代引きで入金を確かめていない
            'direct' => in_array($state->method, ['bank_transfer', 'cod', 'on_site'], true),
            'on_delivery' => $state->onDelivery && $open,
            'test' => PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('provider', 'stripe')->whereIn('status', ['paid', 'awaiting'])->where('test_mode', true)->exists(),
        ];
    }

    /**
     * @return array{status: string|null, label: string, expires_on: string|null}|null
     */
    private function gift(Transaction $transaction): ?array
    {
        if (! $this->gifts->isGift($transaction->id)) {
            return null;
        }

        $share = $this->gifts->share($transaction->id, private: false);

        return [
            'status' => $share?->status,
            'label' => $share === null ? '贈り物' : self::GIFT_LABELS[$share->status],
            'expires_on' => $share !== null && $share->waiting() ? $share->expiresOn : null,
        ];
    }

    /**
     * 税率ごとの対象の額（税込み）と消費税額（核の内訳。ADR 0063）
     *
     * @return list<array{rate_bp: int, gross: int, tax: int, reduced: bool}>
     */
    private static function taxByRate(Transaction $transaction): array
    {
        return array_map(static fn (array $row): array => ['rate_bp' => $row['rateBp'], 'gross' => $row['amount'], 'tax' => $row['tax'], 'reduced' => $row['reduced']],
            TaxBreakdown::ofTransaction($transaction)->rows);
    }

    /**
     * 流れの主な道のり（最初のフェーズから、まだ通っていない次のフェーズをたどる）。画面の道のりの表示に使う
     *
     * @return list<string>
     */
    private static function mainPath(TransactionFlow $flow): array
    {
        $path = [];
        $phase = $flow->initial;

        while (! in_array($phase->value, $path, true)) {
            $path[] = $phase->value;
            $next = array_values(array_filter($flow->transitions[$phase->value] ?? [], static fn (Phase $p): bool => ! in_array($p->value, $path, true)));

            if ($next === []) {
                break;
            }

            $phase = $next[0];
        }

        return $path;
    }
}
