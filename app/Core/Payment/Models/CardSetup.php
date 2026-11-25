<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * カードを替える画面を開いた記録（ADR 0045）。書くのは StoredPayments だけ
 *
 * @property string $id
 * @property string $customer_account_id
 * @property string $provider
 * @property string $account
 * @property string|null $session_ref
 * @property string $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 */
final class CardSetup extends Model
{
    use HasUlids;

    protected $table = 'card_setups';

    protected $fillable = ['customer_account_id', 'provider', 'account', 'session_ref', 'status', 'expires_at', 'completed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
