<?php

declare(strict_types=1);

namespace App\Core\Shop\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 転送（テナント DB。ADR 0061 の 10）。古い URL（from_path。比べる形）→ 同じショップの中の新しい道筋（to_path）。
 * ショップがその URL を「見つかりません」にする時だけ 301 で送る。変えるのは RedirectService だけ
 *
 * @property string $id
 * @property string $from_path
 * @property string $to_path
 * @property string $source manual（店の人が足した）・auto（slug を変えた時に核が足した）
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Redirect extends Model
{
    use HasUlids;

    public const string MANUAL = 'manual';

    public const string AUTO = 'auto';

    /** 1 つの店で持てる数 */
    public const int MAX = 1000;

    protected $table = 'redirects';

    protected $fillable = ['from_path', 'to_path', 'source'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
