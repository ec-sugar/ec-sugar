<?php

declare(strict_types=1);

namespace EcSugar\PluginShopNotify\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 店への知らせ 1 通（宛先ごとに 1 行）。書くのは ShopNotices だけ。facts に個人情報を入れない、宛先を写さない（表の移行の説明）
 *
 * @property string $id
 * @property string $kind
 * @property string $dedupe_key
 * @property string $channel
 * @property array<string, mixed> $facts
 * @property string $status
 * @property int $attempts
 * @property ?string $error
 * @property ?CarbonImmutable $sent_at
 * @property CarbonImmutable $created_at
 */
final class ShopNotice extends Model
{
    use HasUlids;

    protected $table = 'shop_notify_notices';

    protected $fillable = ['kind', 'dedupe_key', 'channel', 'facts', 'status', 'attempts', 'error', 'sent_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
