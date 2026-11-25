<?php

declare(strict_types=1);

namespace App\Core\Fulfillment\Services;

use App\Core\Event\EventStore;
use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Models\FulfillmentItem;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\LineStock;
use App\Core\Inventory\Services\UnitService;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Services\TransactionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 受渡の作成と進行（CONCEPT 5.7）。取引のフェーズは動かさない（それは TransactionService の仕事）。
 *
 * - 出す受渡（inbound 以外）は合意済みの取引だけ。明細と数量を指定し、明細の数量を超えて出せない
 * - 出す受渡が完了したら、合意で確保していた在庫を出庫する（物を出す流れのとき）
 * - 受ける受渡（inbound、買取の送付・持込）は明細を持たない。届いた物は個体在庫として受け入れる（縦のプラグインの仕事）
 * - 送り状連携は後回し。追跡番号は手入力
 */
final class FulfillmentService
{
    private const array ADDRESS_KEYS = ['name', 'company', 'zip', 'prefecture', 'city', 'line1', 'line2', 'phone'];

    public function __construct(
        private readonly EventStore $events,
        private readonly LineStock $lineStock,
        private readonly UnitService $units,
    ) {}

    /**
     * @param  array<string, int>  $items  取引明細の ID => 数量
     * @param  array<string, string|null>|null  $address
     * @param  ?DeliveryChoice  $delivery  お届けの日と時間帯の希望（配送だけ。選べる範囲かは呼ぶ側が店の決まりで確かめる）
     * @param  string|null  $shippingMethod  配送の方法の名前（写して持つ。ADR 0064。方法の違う品は受渡を分ける）
     * @param  string|null  $shipsOn  発送を始める日（Y-m-d。予約の品の発売日。ADR 0073）。この日まで発送・完了にできない
     */
    public function create(
        Transaction $transaction,
        FulfillmentMethod $method,
        array $items = [],
        ?Location $location = null,
        ?array $address = null,
        ?string $actor = null,
        ?DeliveryChoice $delivery = null,
        ?string $shippingMethod = null,
        ?string $shipsOn = null,
    ): Fulfillment {
        $address = $address === null ? null : self::cleanAddress($address);
        self::assertDate($shipsOn);

        if ($shipsOn !== null && $method->isInbound()) {
            throw new TransactionException('発送を始める日を持てるのは出す配送だけです');
        }

        if ($delivery !== null && $method !== FulfillmentMethod::Shipping) {
            throw new TransactionException('お届けの日と時間帯を指定できるのは配送だけです');
        }

        return DB::transaction(function () use ($transaction, $method, $items, $location, $address, $actor, $delivery, $shippingMethod, $shipsOn): Fulfillment {
            $locked = $this->lock($transaction);

            if ($locked->status->isClosed()) {
                throw new TransactionException('終わった取引に配送は作れません');
            }

            if ($location !== null && $location->seller_id !== $locked->seller_id) {
                throw new TransactionException('配送の拠点がこの取引の販売者のものではありません');
            }

            if ($method->isInbound()) {
                if ($items !== []) {
                    throw new TransactionException('受ける配送は明細を持ちません（届いた物は個体在庫として受け入れる）');
                }
            } else {
                if ($locked->agreed_at === null) {
                    throw new TransactionException('合意していない取引の物は出せません');
                }

                if ($items === []) {
                    throw new TransactionException('出す配送には明細が要ります');
                }

                $this->assertRemaining($locked, $items);
            }

            $fulfillment = Fulfillment::create([
                'transaction_id' => $locked->id,
                'method' => $method,
                'location_id' => $location?->id,
                'address' => $address,
                'delivery_date' => $delivery?->date,
                'delivery_slot' => $delivery?->slot,
                'shipping_method' => $shippingMethod === null ? null : mb_substr($shippingMethod, 0, 30),
                'ships_on' => $shipsOn,
            ]);

            $payloadItems = [];

            foreach ($items as $lineId => $quantity) {
                FulfillmentItem::create([
                    'fulfillment_id' => $fulfillment->id,
                    'transaction_line_id' => $lineId,
                    'quantity' => $quantity,
                ]);
                $payloadItems[] = ['line_id' => $lineId, 'quantity' => $quantity];
            }

            // お届けの希望（日と時間帯）は個人情報ではないので記録に残す（指定のあるときだけ）
            $this->events->append('fulfillment.created', 'transaction', $locked->id, [
                'fulfillment_id' => $fulfillment->id,
                'method' => $method->value,
                'items' => $payloadItems,
                ...($delivery === null ? [] : ['delivery' => $delivery->toArray()]),
                ...($shipsOn === null ? [] : ['ships_on' => $shipsOn]),
            ], $actor);

            return $fulfillment;
        });
    }

    public function ship(Fulfillment $fulfillment, ?string $carrier = null, ?string $trackingNumber = null, ?string $actor = null): Fulfillment
    {
        if ($carrier !== null && preg_match('/\A[a-z0-9_]{1,32}\z/', $carrier) !== 1) {
            throw new TransactionException('運送会社の識別子が不正です');
        }

        if ($trackingNumber !== null && preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $trackingNumber) !== 1) {
            throw new TransactionException('追跡番号は英数字とハイフンで 64 文字までです');
        }

        return $this->move($fulfillment, [FulfillmentStatus::Pending], FulfillmentStatus::Shipped, 'fulfillment.shipped', $actor,
            static function (Fulfillment $locked) use ($carrier, $trackingNumber): void {
                $locked->carrier = $carrier;
                $locked->tracking_number = $trackingNumber;
                $locked->shipped_at = CarbonImmutable::now();
            });
    }

    public function complete(Fulfillment $fulfillment, ?string $actor = null): Fulfillment
    {
        return $this->move($fulfillment, [FulfillmentStatus::Pending, FulfillmentStatus::Shipped], FulfillmentStatus::Completed, 'fulfillment.completed', $actor,
            function (Fulfillment $locked, Transaction $transaction): void {
                $locked->completed_at = CarbonImmutable::now();

                if ($locked->method->isInbound() || ! $transaction->flow()->reservesInventory) {
                    return;
                }

                foreach ($locked->items()->get() as $item) {
                    $line = $item->line()->firstOrFail();

                    if ($line->inventory_unit_id !== null) {
                        $this->units->commit($line->unit()->firstOrFail());
                    } elseif ($line->variant_id !== null && $line->location_id !== null) {
                        $this->lineStock->commit($line->id, $line->listing_id, (string) $line->variant_id, (string) $line->location_id, $item->quantity);
                    }
                }
            });
    }

    /**
     * お届け先を後から入れる（贈り物の受け取り。ADR 0055 の 1）。お届け先の入力を待つ受渡（配送で、まだお届け先の無い、発送前の受渡）に 1 回だけ。
     * お届け先は暗号化した列へ。イベントには個人情報を入れない
     *
     * @param  array<string, string|null>  $address
     */
    public function setAddress(Fulfillment $fulfillment, array $address, ?string $actor = null): Fulfillment
    {
        $address = self::cleanAddress($address);

        foreach (['name', 'zip', 'prefecture', 'city', 'line1'] as $required) {
            if (! isset($address[$required])) {
                throw new TransactionException("お届け先の {$required} が要ります");
            }
        }

        return DB::transaction(function () use ($fulfillment, $address, $actor): Fulfillment {
            $transaction = $this->lock($fulfillment->transaction()->firstOrFail());
            $locked = Fulfillment::query()->whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            if ($transaction->status->isClosed() || $locked->status !== FulfillmentStatus::Pending || ! $locked->awaitingAddress()) {
                throw new TransactionException('この配送のお届け先は、もう決まっているか、取り消されています');
            }

            $locked->address = $address;
            $locked->save();
            $this->events->append('fulfillment.address_set', 'transaction', $transaction->id, ['fulfillment_id' => $locked->id], $actor);

            return $locked;
        });
    }

    /**
     * 発送を始める日を変える（予約の品の発売日が変わった。ADR 0073）。発送の前の受渡だけ。null で外す（いつでも発送できる）
     */
    public function reschedule(Fulfillment $fulfillment, ?string $shipsOn, ?string $actor = null): Fulfillment
    {
        self::assertDate($shipsOn);

        return DB::transaction(function () use ($fulfillment, $shipsOn, $actor): Fulfillment {
            $transaction = $this->lock($fulfillment->transaction()->firstOrFail());
            $locked = Fulfillment::query()->whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== FulfillmentStatus::Pending || $locked->method->isInbound()) {
                throw new TransactionException('発送の前の配送だけ、発送を始める日を変えられます');
            }

            if ($locked->shipsOn() === $shipsOn) {
                return $locked;
            }

            $from = $locked->shipsOn();
            $locked->ships_on = $shipsOn;
            $locked->save();
            $this->events->append('fulfillment.ships_on_changed', 'transaction', $transaction->id, ['fulfillment_id' => $locked->id, 'from' => $from, 'to' => $shipsOn], $actor);

            return $locked;
        });
    }

    public function cancel(Fulfillment $fulfillment, ?string $actor = null): Fulfillment
    {
        return $this->move($fulfillment, [FulfillmentStatus::Pending, FulfillmentStatus::Shipped], FulfillmentStatus::Cancelled, 'fulfillment.cancelled', $actor);
    }

    /**
     * @param  list<FulfillmentStatus>  $from
     * @param  (callable(Fulfillment, Transaction): void)|null  $change
     */
    private function move(Fulfillment $fulfillment, array $from, FulfillmentStatus $to, string $eventType, ?string $actor, ?callable $change = null): Fulfillment
    {
        return DB::transaction(function () use ($fulfillment, $from, $to, $eventType, $actor, $change): Fulfillment {
            // 連鎖 → 取引 → 受渡の順にロックする（取消と受渡の完了が同時に走っても在庫が二重に動かない）
            $transaction = $this->lock($fulfillment->transaction()->firstOrFail());
            $locked = Fulfillment::query()->whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            if ($transaction->status->isClosed() && $to !== FulfillmentStatus::Cancelled) {
                throw new TransactionException('終わった取引の配送は進められません');
            }

            if (! in_array($locked->status, $from, true)) {
                throw new TransactionException("配送は {$locked->status->value} から {$to->value} へ進めません");
            }

            // お届け先の入力を待つ受渡（贈り物の受け取り待ち。ADR 0055）は、お届け先が入るまで発送・完了にしない
            if ($locked->awaitingAddress() && in_array($to, [FulfillmentStatus::Shipped, FulfillmentStatus::Completed], true)) {
                throw new TransactionException('お届け先が決まっていない配送は発送できません（贈り物の受け取り待ち）');
            }

            // 発送を始める日の前（予約の品の発売日の前。ADR 0073）は、発送・完了にしない
            if ($locked->heldForRelease() && in_array($to, [FulfillmentStatus::Shipped, FulfillmentStatus::Completed], true)) {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', (string) $locked->shipsOn());

                throw new TransactionException('この配送は '.($day === null ? $locked->shipsOn() : $day->month.'月'.$day->day.'日').' から発送できます（予約販売の商品の発売日より前です）');
            }

            if ($change !== null) {
                $change($locked, $transaction);
            }

            $locked->status = $to;
            $locked->save();

            $this->events->append($eventType, 'transaction', $transaction->id, [
                'fulfillment_id' => $locked->id,
                'method' => $locked->method->value,
            ], $actor);

            return $locked;
        });
    }

    /**
     * まだ渡していない数（商品の明細ごとに、明細の数 − 発送した・渡し終えた受渡の数）。全部渡していれば 0
     */
    public function undelivered(Transaction $transaction): int
    {
        $lines = TransactionLine::query()->where('transaction_id', $transaction->id)->where('line_type', LineType::Item)->pluck('quantity', 'id');
        $delivered = FulfillmentItem::query()
            ->join('fulfillments', 'fulfillments.id', '=', 'fulfillment_items.fulfillment_id')
            ->where('fulfillments.transaction_id', $transaction->id)
            ->whereIn('fulfillments.status', [FulfillmentStatus::Shipped->value, FulfillmentStatus::Completed->value])
            ->groupBy('fulfillment_items.transaction_line_id')
            ->selectRaw('fulfillment_items.transaction_line_id AS line_id, SUM(fulfillment_items.quantity)::bigint AS quantity')
            ->pluck('quantity', 'line_id');

        return $lines->sum(static fn (mixed $quantity, string $lineId): int => max(0, (int) $quantity - (int) ($delivered[$lineId] ?? 0)));
    }

    /**
     * @param  array<string, int>  $items
     */
    private function assertRemaining(Transaction $transaction, array $items): void
    {
        foreach ($items as $lineId => $quantity) {
            $line = TransactionLine::query()->whereKey($lineId)->where('transaction_id', $transaction->id)->first();

            if ($line === null || $line->line_type !== LineType::Item) {
                throw new TransactionException('配送の明細がこの取引の商品の明細ではありません');
            }

            $already = (int) FulfillmentItem::query()
                ->join('fulfillments', 'fulfillments.id', '=', 'fulfillment_items.fulfillment_id')
                ->where('fulfillment_items.transaction_line_id', $line->id)
                ->where('fulfillments.status', '!=', FulfillmentStatus::Cancelled->value)
                ->sum('fulfillment_items.quantity');

            if ($quantity < 1 || $already + $quantity > $line->quantity) {
                throw new TransactionException("明細の数量を超えて受け渡せません（明細 {$line->quantity}、手配済み {$already}、今回 {$quantity}）");
            }
        }
    }

    /**
     * @param  array<string, string|null>  $address
     * @return array<string, string>
     */
    private static function cleanAddress(array $address): array
    {
        $clean = [];

        foreach ($address as $key => $value) {
            if (! in_array($key, self::ADDRESS_KEYS, true)) {
                throw new TransactionException("配送先に使えない項目です: {$key}");
            }

            if ($value === null || trim($value) === '') {
                continue;
            }

            if (mb_strlen($value) > 200) {
                throw new TransactionException("配送先の {$key} が長すぎます");
            }

            $clean[$key] = trim($value);
        }

        return $clean;
    }

    private static function assertDate(?string $date): void
    {
        if ($date !== null && (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1 || CarbonImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date)) {
            throw new TransactionException('発送を始める日は Y-m-d の日付です');
        }
    }

    private function lock(Transaction $transaction): Transaction
    {
        $this->events->lock();

        return Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
    }
}
