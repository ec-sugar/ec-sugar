<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Money\Money;
use App\Core\Order\Gifts;
use App\Core\Order\OrderCharges;
use App\Core\Order\Preorders;
use App\Core\Order\Services\OrderFields;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Points\Services\PointService;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\TaxBreakdown;
use App\Storefront\Access\SecretLinks;
use App\Storefront\Checkout\CheckoutOrder;

/**
 * ショップの注文を、お客さんに見せる形（OrderView）にする。ショップで確定した注文だけ（管理画面などで作った取引は見せない）
 */
final class OrderReader
{
    /** 確認のメールの照会のリンクの期限（日） */
    public const int LINK_DAYS = 30;

    public function __construct(
        private readonly SaleOrderService $orders,
        private readonly OrderLinks $links,
        private readonly Gifts $gifts,
        private readonly PaymentService $payments,
        private readonly OrderFields $orderFields,
        private readonly PointService $points,
        private readonly OrderFacts $facts,
        private readonly GiftCardService $giftCards,
    ) {}

    public function find(string $orderId): ?Transaction
    {
        $id = strtolower(trim($orderId));

        if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) !== 1 || ! CheckoutOrder::query()->where('transaction_id', $id)->exists()) {
            return null;
        }

        return Transaction::query()->where('kind', 'sale')->find($id);
    }

    /**
     * 注文を見てよい人の画面のための様子（App のリンク〔レビューを書く・領収書など〕つき）。呼ぶのは見てよいと確かめた後だけ
     * （ご注文の確認と会員のマイページ）。確認のメールには使わない
     */
    public function readWithLinks(Transaction $transaction): OrderView
    {
        return $this->read($transaction)->withLinks($this->links->forOrder($transaction));
    }

    public function read(Transaction $transaction): OrderView
    {
        $yen = static fn (int $amount): string => Money::format($amount, $transaction->currency);
        $lines = $transaction->lines()->orderBy('position')->get();
        $shipping = $lines->filter(static fn (TransactionLine $line): bool => $line->line_type !== LineType::Item && $line->kind === 'shipping')->values();
        $status = $this->orders->status($transaction);
        // お届け日時のご希望（ADR 0059）: 注文の入力で選んだ値は配送の受渡のどれにも同じに入る。取り消していない最初の受渡から
        // 注文の項目（ADR 0066 の決定 5）: お客さんの画面とメールには選ぶ・チェックの答えだけ。文の答えは「お店に届いています」の一言だけ
        [$fields, $fieldsWithheld] = $this->orderFields->forCustomer($transaction->id);
        [$preorder, $orderedOn] = self::preorder($transaction);
        $wish = Fulfillment::query()->where('transaction_id', $transaction->id)->where('status', '!=', FulfillmentStatus::Cancelled)
            ->where(static fn ($q) => $q->whereNotNull('delivery_date')->orWhereNotNull('delivery_slot'))->orderBy('created_at')->first()?->delivery();

        return new OrderView(
            orderId: $transaction->id,
            status: $status->value,
            statusLabel: $status->label(),
            placedAt: $transaction->created_at?->timezone('Asia/Tokyo')->format('Y/m/d H:i') ?? '',
            // オプションの行（ADR 0036）は商品の行の後に並ぶ（名前に「（×数）」が入っている）。入れた文字はここには出さない
            items: $lines->filter(static fn (TransactionLine $line): bool => $line->line_type === LineType::Item || $line->kind === 'addon')
                ->map(static fn (TransactionLine $line): array => ['title' => $line->description, 'quantity' => $line->quantity, 'amountLabel' => $yen($line->amount),
                    'reduced' => TaxClass::isReducedRate($line->tax_rate_bp)])->values()->all(),
            shippingLabel: self::shippingLabel($shipping->all(), $yen),
            totalLabel: $yen($transaction->total_amount),
            // 発送は送る受渡だけ（送らない品〔ダウンロード・チケット・ギフトカード〕の渡し終えた受渡は「発送」に出さない）
            shipments: Fulfillment::query()->where('transaction_id', $transaction->id)->whereIn('status', [FulfillmentStatus::Shipped, FulfillmentStatus::Completed])
                ->where('method', '!=', FulfillmentMethod::Digital)->orderBy('shipped_at')->get()
                ->map(static fn (Fulfillment $f): array => [
                    'carrier' => $f->carrier === null ? null : (SaleOrderService::CARRIERS[$f->carrier] ?? null),
                    'trackingNumber' => $f->tracking_number,
                    'shippedAt' => $f->shipped_at?->timezone('Asia/Tokyo')->format('Y/m/d') ?? '',
                ])->values()->all(),
            free: (int) $transaction->total_amount === 0,
            // 値引きは税率ごとに行を分けてある（ADR 0063）。お客さんには同じ名前の行を 1 つにまとめて見せる
            // 使ったポイント（ADR 0068）も同じ並びに「ポイントのご利用」の 1 行で（率ごとの行をまとめる）
            discounts: $lines->filter(static fn (TransactionLine $line): bool => $line->line_type !== LineType::Item && in_array($line->kind, ['discount', 'points'], true))
                ->groupBy('description')
                ->map(static fn ($same, string $title): array => ['title' => $title, 'amountLabel' => '−'.$yen(abs((int) $same->sum('amount')))])->values()->all(),
            // 贈り物（ADR 0055）。お客さんの画面（ご注文の状況）とメールは買う人だけが見るので、受け取りのリンクも入れる
            gift: $this->gifts->share($transaction->id, private: true),
            hidePrices: $this->gifts->isGift($transaction->id),
            // 支払いの様子（払われた手段・コンビニ払いの入金待ち。ADR 0058）
            payment: $this->payments->state($transaction),
            deliveryWish: $wish?->label(),
            taxes: TaxBreakdown::ofTransaction($transaction),
            // 注文の項目の選ぶ・チェックの答え（ADR 0066）。注文の時の欄の名前で。App が足す注文の事実（予約の日時・場所・人数など）を先に
            fields: [...$this->facts->forOrder($transaction), ...$fields],
            fieldsWithheld: $fieldsWithheld,
            // ポイント（ADR 0068）: 使った数・付く予定・付いた数
            points: OrderPointsView::of($this->points->forOrder($transaction->id), self::inPerson($transaction->id)),
            // 料金の行: App の料金（ラッピング・のしなど。ADR 0078。0 円は「無料」）と支払いの手数料（代引き手数料。ADR 0074）の順に
            charges: $lines->filter(static fn (TransactionLine $line): bool => $line->line_type !== LineType::Item && OrderCharges::shownAsCharge($line->kind))
                ->map(static fn (TransactionLine $line): array => ['title' => $line->description, 'amountLabel' => (int) $line->amount === 0 ? '無料' : $yen((int) $line->amount)])->values()->all(),
            bankTransfer: PaymentAttempt::direct($transaction->id)?->method === 'bank_transfer',
            // 来店で受け渡す注文（予約の品。受渡が役務だけ）
            inPerson: self::inPerson($transaction->id),
            // ギフトカード（ADR 0076）: 使った額と使った後の残り（合計は変えない。支払いの一部）
            giftCard: OrderGiftCardView::of($this->giftCards->forOrder($transaction->id)),
            // 予約の品の発送の予定（ADR 0073）と、変わった時の注文の時の発売日
            preorder: $preorder,
            preorderOrderedOn: $orderedOn,
        );
    }

    /**
     * 受渡が役務（来店で受け渡す）だけの注文か（予約の品）
     */
    private static function inPerson(string $transactionId): bool
    {
        $methods = Fulfillment::query()->where('transaction_id', $transactionId)->distinct()->pluck('method');

        return $methods->isNotEmpty() && $methods->every(static fn (mixed $m): bool => $m === FulfillmentMethod::InPerson);
    }

    /**
     * 確認のメールに載せる、注文の様子のページへの期限つきの署名つきリンク（ショップのドメインで）。
     * 署名はパスにだけ掛ける（キューの処理の中では、ショップのドメインを Laravel が知らないため。作るのも確かめるのも核の SecretLinks）
     */
    public static function link(Transaction $transaction, string $storefrontBase): string
    {
        return rtrim($storefrontBase, '/').SecretLinks::signed('storefront.orders.show', ['orderId' => $transaction->id], now()->addDays(self::LINK_DAYS));
    }

    /**
     * 予約の品のある注文の発送の予定（ADR 0073）: 発送の前の受渡の発送を始める日。注文の時の日（order.preorder_placed）と違えば、その日も返す
     *
     * @return array{0: ?string, 1: ?string} [「11月20日ごろ発送（予約商品）」, 注文の時の日「11月20日」（同じなら null）]
     */
    private static function preorder(Transaction $transaction): array
    {
        if ($transaction->status->isClosed()) {
            return [null, null];
        }

        $shipsOn = Fulfillment::query()->where('transaction_id', $transaction->id)->where('status', FulfillmentStatus::Pending)->whereNotNull('ships_on')->max('ships_on');

        if ($shipsOn === null) {
            return [null, null];
        }

        $now = substr((string) $shipsOn, 0, 10);
        $placed = DomainEvent::query()->where('aggregate_type', 'transaction')->where('aggregate_id', $transaction->id)->where('type', Preorders::PLACED)->first()?->payload['ships_on'] ?? null;

        return [PreorderTerms::dateLabel($now).'ごろ発送（予約商品）', is_string($placed) && $placed !== $now ? PreorderTerms::dateLabel($placed) : null];
    }

    /**
     * 送料の見せ方（ADR 0064）: 「¥800」。便に方法の名前が付いていれば「¥800（クール便）」、便が 2 つ以上なら「¥2,000（通常便 ¥800・クール便 ¥1,200）」。送料の行が無ければ null
     *
     * @param  list<TransactionLine>  $lines  送料の行
     * @param  \Closure(int): string  $yen
     */
    private static function shippingLabel(array $lines, \Closure $yen): ?string
    {
        if ($lines === []) {
            return null;
        }

        $total = $yen((int) array_sum(array_map(static fn (TransactionLine $line): int => (int) $line->amount, $lines)));
        $names = array_map(static fn (TransactionLine $line): ?string => preg_match('/\A送料（(.+)）\z/u', (string) $line->description, $m) === 1 ? $m[1] : null, $lines);

        if (count($lines) === 1) {
            return $names[0] === null ? $total : $total.'（'.$names[0].'）';
        }

        $parts = [];
        foreach ($lines as $index => $line) {
            $parts[] = ($names[$index] ?? '送料').' '.$yen((int) $line->amount);
        }

        return $total.'（'.implode('・', $parts).'）';
    }
}
