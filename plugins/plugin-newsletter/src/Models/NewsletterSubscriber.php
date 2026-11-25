<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Crypto\Concerns\HasBlindIndexes;
use Carbon\CarbonImmutable;
use EcSugar\PluginNewsletter\SubscriberStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * お知らせのメールを受け取ると選んだ人（1 行 = 1 つのメール）。書くのは NewsletterSubscribers と配信の仕事（SendCampaignMail は読むだけ）。
 * メールは店の鍵で暗号化し、引くのは email_index（ブラインドインデックス）の完全一致だけ（ADR 0010）
 *
 * @property string $id
 * @property ?string $customer_account_id
 * @property ?string $email
 * @property ?string $email_index
 * @property SubscriberStatus $status
 * @property string $source
 * @property ?string $token_hash
 * @property ?string $mail_token
 * @property ?CarbonImmutable $expires_at
 * @property CarbonImmutable $consented_at
 * @property ?CarbonImmutable $confirmed_at
 * @property ?CarbonImmutable $unsubscribed_at
 * @property ?string $unsubscribed_via
 * @property CarbonImmutable $created_at
 */
final class NewsletterSubscriber extends Model
{
    use HasBlindIndexes;
    use HasUlids;

    protected $table = 'newsletter_subscribers';

    protected $fillable = ['customer_account_id', 'email', 'status', 'source', 'token_hash', 'mail_token', 'expires_at', 'consented_at', 'confirmed_at', 'unsubscribed_at', 'unsubscribed_via'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => TenantEncrypted::class,
            'mail_token' => TenantEncrypted::class,
            'status' => SubscriberStatus::class,
            'expires_at' => 'immutable_datetime',
            'consented_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
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
