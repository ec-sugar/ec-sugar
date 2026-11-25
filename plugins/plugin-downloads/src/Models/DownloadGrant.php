<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 注文 × ファイルのリンク（ADR 0084）。書くのは DownloadGrants だけ（連鎖のロック → 行のロックの中で、イベントと一緒に）
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $file_id
 * @property int $count
 * @property int $max_count
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_downloaded_at
 * @property CarbonImmutable|null $stopped_at
 * @property string|null $stop_reason
 * @property CarbonImmutable $created_at
 */
final class DownloadGrant extends Model
{
    use HasUlids;

    /** 止めた理由の見せ方 */
    public const array STOP_REASONS = ['cancel' => '注文の取消', 'refund' => '返金', 'staff' => 'お店による停止'];

    protected $table = 'downloads_grants';

    protected $fillable = ['transaction_id', 'file_id', 'count', 'max_count', 'expires_at'];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'max_count' => 'integer',
            'expires_at' => 'immutable_datetime',
            'last_downloaded_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * 今ダウンロードできない理由（できるなら null）。期限はちょうどから切れる
     */
    public function refusal(CarbonImmutable $now): ?string
    {
        return match (true) {
            $this->stopped_at !== null => 'このファイルは今はダウンロードできません（'.(self::STOP_REASONS[(string) $this->stop_reason] ?? '停止中').'）。',
            $this->expires_at->lessThanOrEqualTo($now) => 'ダウンロードの期限が過ぎました。お店へお問い合わせください。',
            $this->count >= $this->max_count => 'ダウンロードの回数（'.$this->max_count.' 回）を使い切りました。お店へお問い合わせください。',
            default => null,
        };
    }
}
