<?php

declare(strict_types=1);

namespace EcSugar\PluginGift\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 贈り物の注文（ADR 0055）。差出人の表示名・メッセージ・買う人に出し直す token は店の鍵で暗号化する（ADR 0010）。
 * 引くときは token の sha256（token_hash）で。書くのは GiftOrders だけ（連鎖のロックとイベント）
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $sender
 * @property string $message
 * @property string $status
 * @property ?string $token_hash
 * @property ?string $token_sealed
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $reminded_at
 * @property ?CarbonImmutable $claimed_at
 * @property ?CarbonImmutable $closed_at
 * @property ?CarbonImmutable $updated_at
 */
final class GiftOrder extends Model
{
    use HasUlids;

    protected $table = 'gift_orders';

    protected $fillable = ['transaction_id', 'sender', 'message', 'status', 'token_hash', 'token_sealed', 'expires_at', 'reminded_at', 'claimed_at', 'closed_at'];

    protected $attributes = ['status' => 'preparing'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender' => TenantEncrypted::class,
            'message' => TenantEncrypted::class,
            'token_sealed' => TenantEncrypted::class,
            'expires_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
