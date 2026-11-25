<?php

declare(strict_types=1);

namespace App\Core\Fulfillment\Models;

use App\Core\Crypto\Casts\TenantEncryptedJson;
use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\DeliverySlot;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Inventory\Models\Location;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 受渡（テナント DB）。1 取引に複数あってよい（分納、買取の送付と返送）。状態の変更は FulfillmentService を通す。
 * 配送先（宛名・住所・電話）は個人情報なので暗号化する（ADR 0010）。
 *
 * @property string $id
 * @property string $transaction_id
 * @property FulfillmentMethod $method
 * @property FulfillmentStatus $status
 * @property string|null $location_id
 * @property array<string, mixed>|null $address
 * @property string|null $carrier
 * @property string|null $shipping_method 配送の方法の名前（注文の時に写す。ADR 0064）
 * @property string|null $tracking_number
 * @property CarbonImmutable|null $shipped_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $delivery_date お届けの希望日（Y-m-d）
 * @property DeliverySlot|null $delivery_slot お届けの希望の時間帯
 * @property string|null $ships_on 発送を始める日（Y-m-d。予約の品の発売日。ADR 0073）。この日まで発送できない
 */
final class Fulfillment extends Model
{
    use HasUlids;

    protected $table = 'fulfillments';

    protected $fillable = [
        'transaction_id', 'method', 'status', 'location_id', 'address', 'carrier', 'tracking_number', 'shipped_at', 'completed_at', 'shipping_method',
        'delivery_date', 'delivery_slot', 'ships_on',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => FulfillmentMethod::class,
            'status' => FulfillmentStatus::class,
            'address' => TenantEncryptedJson::class,
            'shipped_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'delivery_slot' => DeliverySlot::class,
        ];
    }

    /**
     * お客さんが選んだお届けの日と時間帯（ADR 0059）。指定なしは null
     */
    public function delivery(): ?DeliveryChoice
    {
        $date = $this->delivery_date;

        return $date === null && $this->delivery_slot === null ? null : new DeliveryChoice($date === null ? null : substr((string) $date, 0, 10), $this->delivery_slot);
    }

    /**
     * 発送を始める日（Y-m-d。予約の品の発売日。ADR 0073）。無ければ null
     */
    public function shipsOn(): ?string
    {
        return $this->ships_on === null ? null : substr((string) $this->ships_on, 0, 10);
    }

    /**
     * 発送を始める日の前（日本の暦の今日がその日より前）。発送・完了にできない
     */
    public function heldForRelease(): bool
    {
        $shipsOn = $this->shipsOn();

        return $shipsOn !== null && $shipsOn > CarbonImmutable::now('Asia/Tokyo')->format('Y-m-d');
    }

    /**
     * お届け先の入力を待つ（配送なのにお届け先がまだ無い。贈り物の受け取り待ち。ADR 0055）
     */
    public function awaitingAddress(): bool
    {
        return $this->method === FulfillmentMethod::Shipping && ($this->address === null || $this->address === []);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<FulfillmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FulfillmentItem::class);
    }
}
