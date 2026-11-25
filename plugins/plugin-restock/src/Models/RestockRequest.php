<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Crypto\Concerns\HasBlindIndexes;
use Carbon\CarbonImmutable;
use EcSugar\PluginRestock\RestockStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 再入荷のお知らせの登録（1 つの種類への 1 人の登録）。書くのは RestockRequests と RestockNotifier だけ。
 * メールは店の鍵で暗号化し、引くのは email_index（ブラインドインデックス）の完全一致だけ（ADR 0010）。終わった登録ではメールも索引も空
 *
 * @property string $id
 * @property string $listing_id
 * @property string $variant_id
 * @property ?string $customer_account_id
 * @property ?string $email
 * @property ?string $email_index
 * @property string $token_hash
 * @property ?string $mail_token
 * @property RestockStatus $status
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $confirmed_at
 * @property ?CarbonImmutable $notified_at
 * @property ?CarbonImmutable $cancelled_at
 * @property CarbonImmutable $created_at
 */
final class RestockRequest extends Model
{
    use HasBlindIndexes;
    use HasUlids;

    protected $table = 'restock_requests';

    protected $fillable = ['listing_id', 'variant_id', 'customer_account_id', 'email', 'token_hash', 'mail_token', 'status', 'expires_at', 'confirmed_at', 'notified_at', 'cancelled_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => TenantEncrypted::class,
            'mail_token' => TenantEncrypted::class,
            'status' => RestockStatus::class,
            'expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function blindIndexes(): array
    {
        return ['email' => 'email_index'];
    }
}
