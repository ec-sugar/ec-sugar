<?php

declare(strict_types=1);

namespace EcSugar\PluginContact\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 店からの返信の 1 通（メールで送った文）。書くのは ContactInquiries だけ。本文は店の鍵で暗号化（お客さんの名前などを書くため）。
 * sent_at はメールを送れた時刻（送る仕事が済むまで null）
 *
 * @property string $id
 * @property string $inquiry_id
 * @property string $body
 * @property ?CarbonImmutable $sent_at
 * @property CarbonImmutable $created_at
 */
final class ContactReply extends Model
{
    use HasUlids;

    protected $table = 'contact_replies';

    protected $fillable = ['inquiry_id', 'body', 'sent_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => TenantEncrypted::class,
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
