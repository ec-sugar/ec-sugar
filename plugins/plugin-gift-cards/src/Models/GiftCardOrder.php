<?php

declare(strict_types=1);

namespace EcSugar\PluginGiftCards\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * ギフトカードを売る注文（ADR 0076）。注文を作る時に 1 回だけ書き、後から変えない（DB も断る）。贈る相手のメールとメッセージは暗号化（ADR 0010）
 *
 * @property string $transaction_id
 * @property int $amount
 * @property int $valid_days
 * @property string|null $recipient_email
 * @property string|null $message
 * @property string $buyer_index
 * @property string $source_index
 * @property CarbonImmutable $created_at
 */
final class GiftCardOrder extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'gift_cards_orders';

    protected $primaryKey = 'transaction_id';

    protected $keyType = 'string';

    protected $fillable = ['transaction_id', 'amount', 'valid_days', 'recipient_email', 'message', 'buyer_index', 'source_index'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'valid_days' => 'integer',
            'recipient_email' => TenantEncrypted::class,
            'message' => TenantEncrypted::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
