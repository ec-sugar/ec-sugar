<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * カードを預ける先（Stripe の Customer など。ADR 0045）。お客さんの台帳 × 決済代行 × アカウントで 1 つ。作るのは StoredPayments だけ
 *
 * @property string $id
 * @property string $customer_account_id
 * @property string $provider
 * @property string $account
 * @property string $customer_ref
 */
final class StoredPaymentCustomer extends Model
{
    use HasUlids;

    protected $table = 'stored_payment_customers';

    protected $fillable = ['customer_account_id', 'provider', 'account', 'customer_ref'];
}
