<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 受渡ごとの「書き出した」の印（最後の日時・形・回数）。書くのは Exports だけ（連鎖のロックとイベント）
 *
 * @property string $fulfillment_id
 * @property string $transaction_id
 * @property string $format
 * @property int $times
 * @property CarbonImmutable $exported_at
 */
final class ExportedMark extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'shipping_csv_exported';

    protected $primaryKey = 'fulfillment_id';

    protected $keyType = 'string';

    protected $fillable = ['fulfillment_id', 'transaction_id', 'format', 'times', 'exported_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['times' => 'integer', 'exported_at' => 'immutable_datetime'];
    }
}
