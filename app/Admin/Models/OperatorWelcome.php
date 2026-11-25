<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * 管理画面のホームの「ようこそ」を閉じた印（中央 DB）。運営ごと・店ごとに 1 行。変えるのは OperatorWelcomes だけ
 *
 * @property string $id
 * @property string $operator_id
 * @property string $tenant_id
 * @property CarbonImmutable $seen_at
 */
final class OperatorWelcome extends Model
{
    use CentralConnection;
    use HasUlids;

    public $timestamps = false;

    protected $table = 'operator_welcomes';

    protected $fillable = ['operator_id', 'tenant_id', 'seen_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['seen_at' => 'immutable_datetime'];
    }
}
