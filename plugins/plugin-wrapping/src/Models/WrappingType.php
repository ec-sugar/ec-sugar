<?php

declare(strict_types=1);

namespace EcSugar\PluginWrapping\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * ラッピングの種類（ADR 0078）。名前と税込みの料金は店の人が決める。書くのは WrappingTypes だけ（連鎖のロックとイベント）
 *
 * @property string $id
 * @property string $name
 * @property int $price
 * @property bool $active
 * @property int $position
 */
final class WrappingType extends Model
{
    use HasUlids;

    protected $table = 'wrapping_types';

    protected $fillable = ['name', 'price', 'active', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'integer', 'active' => 'boolean', 'position' => 'integer'];
    }
}
