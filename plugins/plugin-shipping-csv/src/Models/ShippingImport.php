<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 取り込んだ CSV 1 つ（確かめ待ち pending → 発送にした applied・取りやめた discarded）。書くのは Imports だけ（連鎖のロックとイベント）
 *
 * @property string $id
 * @property string $carrier
 * @property string $status
 * @property string $file_hash
 * @property int $rows_count
 * @property int $ready_count
 * @property int $skipped_count
 * @property int $shipped_count
 * @property string $created_by
 * @property ?string $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?CarbonImmutable $created_at
 */
final class ShippingImport extends Model
{
    use HasUlids;

    protected $table = 'shipping_csv_imports';

    protected $fillable = ['carrier', 'status', 'file_hash', 'rows_count', 'ready_count', 'skipped_count', 'shipped_count', 'created_by', 'decided_by', 'decided_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rows_count' => 'integer',
            'ready_count' => 'integer',
            'skipped_count' => 'integer',
            'shipped_count' => 'integer',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ShippingImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ShippingImportRow::class, 'import_id');
    }
}
