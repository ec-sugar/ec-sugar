<?php

declare(strict_types=1);

namespace App\Core\GiftCard\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 発行したギフトカード（ADR 0076）。コードそのものは持たない（店の鍵の HMAC と末尾 4 文字だけ）。test はテストの支払いで売ったカード（本番の鍵の店では使えない）。残高は帳簿（gift_card_entries）の最後の行。
 * 書くのは GiftCardService だけ（止める・コードを出し直す・届けた時刻も、イベントを残してサービスから）
 *
 * @property string $id
 * @property string $code_hash
 * @property string $last4
 * @property int $amount 額面（円）
 * @property string|null $purchase_transaction_id 売った注文（App の注文）
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $stopped_at
 * @property string|null $stopped_reason staff・refund
 * @property CarbonImmutable|null $mailed_at 買った人に届けた
 * @property CarbonImmutable|null $recipient_mailed_at 贈る相手に届けた
 * @property int $reissues
 * @property bool $test
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class GiftCard extends Model
{
    use HasUlids;

    protected $table = 'gift_cards';

    protected $fillable = ['code_hash', 'last4', 'amount', 'purchase_transaction_id', 'expires_at', 'test'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expires_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
            'mailed_at' => 'immutable_datetime',
            'recipient_mailed_at' => 'immutable_datetime',
            'reissues' => 'integer',
            'test' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * 期限を過ぎたか（期限の時刻ちょうどで使えなくなる）
     */
    public function expired(CarbonImmutable $now): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now);
    }
}
