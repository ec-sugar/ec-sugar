<?php

declare(strict_types=1);

namespace EcSugar\PluginContact\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Crypto\Concerns\HasBlindIndexes;
use Carbon\CarbonImmutable;
use EcSugar\PluginContact\ContactKind;
use EcSugar\PluginContact\ContactStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * お問い合わせの 1 件。書くのは ContactInquiries だけ（連鎖のロックとイベント）。
 * 名前・メール・本文は店の鍵で暗号化し、メールを引くのは email_index（ブラインドインデックス）の完全一致だけ（ADR 0010）
 *
 * @property string $id
 * @property ContactKind $kind
 * @property ?string $listing_id
 * @property ?string $transaction_id
 * @property ?string $customer_account_id
 * @property string $name
 * @property string $email
 * @property string $email_index
 * @property string $body
 * @property ContactStatus $status
 * @property ?CarbonImmutable $last_replied_at
 * @property ?CarbonImmutable $closed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ContactInquiry extends Model
{
    use HasBlindIndexes;
    use HasUlids;

    protected $table = 'contact_inquiries';

    protected $fillable = ['kind', 'listing_id', 'transaction_id', 'customer_account_id', 'name', 'email', 'body', 'status', 'last_replied_at', 'closed_at'];

    /**
     * お客さんに伝える受付番号（ID を大文字で。注文番号と同じ形）
     */
    public function number(): string
    {
        return strtoupper($this->id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ContactKind::class,
            'status' => ContactStatus::class,
            'name' => TenantEncrypted::class,
            'email' => TenantEncrypted::class,
            'body' => TenantEncrypted::class,
            'last_replied_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
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
