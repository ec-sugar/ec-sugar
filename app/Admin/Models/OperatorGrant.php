<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * スタッフの店ごとの権限（ADR 0024）。変えるのは OperatorAccess だけ
 *
 * @property string $id
 * @property string $operator_id
 * @property string $tenant_id
 * @property string $level read | operate | money
 */
final class OperatorGrant extends Model
{
    use CentralConnection;
    use HasUlids;

    protected $table = 'operator_grants';

    protected $fillable = ['operator_id', 'tenant_id', 'level'];
}
