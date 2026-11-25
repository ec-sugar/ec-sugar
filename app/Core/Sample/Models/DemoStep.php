<?php

declare(strict_types=1);

namespace App\Core\Sample\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * デモの店を作るスクリプトが済ませた手順の印（テナント DB。ADR 0086）。書くのはデモの店を作るスクリプトだけ。
 * `built:<扱い>` があれば、スクリプトを最後まで流し終えた店（毎晩の戻しの条件の 1 つ）
 *
 * @property string $id
 * @property string $key
 * @property CarbonImmutable $created_at
 */
final class DemoStep extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'demo_steps';

    protected $fillable = ['key'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * スクリプトを流し終えた店か（店の文脈の中で）
     */
    public static function built(): bool
    {
        return self::query()->where('key', 'like', 'built:%')->exists();
    }
}
