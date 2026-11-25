<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * 店と Stripe の連結アカウントの対応（中央 DB。ADR 0022）。変えるのは StripeConnectService だけ
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property bool $livemode
 */
final class StripeConnectedAccount extends Model
{
    use CentralConnection;
    use HasUlids;

    protected $table = 'stripe_connected_accounts';

    protected $fillable = ['tenant_id', 'account_id', 'livemode'];

    protected function casts(): array
    {
        return ['livemode' => 'boolean'];
    }
}
