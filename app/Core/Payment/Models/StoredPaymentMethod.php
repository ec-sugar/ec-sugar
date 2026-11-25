<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 預けたカード（ADR 0045）。カードの番号は持たない。ブランド・末尾 4 桁・期限は見せるために持つ（PCI DSS で持ってよい値）。
 * 状態は usable（使える）・needs_update（登録し直しが要る）・detached（外した）。書くのは StoredPayments だけ
 *
 * @property string $id
 * @property string $customer_account_id
 * @property string $provider
 * @property string $account
 * @property string $customer_ref
 * @property string $method_ref
 * @property string $status
 * @property string|null $brand
 * @property string|null $last4
 * @property int|null $exp_month
 * @property int|null $exp_year
 * @property string|null $fingerprint カードの指紋（Stripe の card.fingerprint。番号ではない。ADR 0080）
 */
final class StoredPaymentMethod extends Model
{
    use HasUlids;

    protected $table = 'stored_payment_methods';

    protected $fillable = ['customer_account_id', 'provider', 'account', 'customer_ref', 'method_ref', 'status', 'brand', 'last4', 'exp_month', 'exp_year', 'fingerprint'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['exp_month' => 'integer', 'exp_year' => 'integer'];
    }

    /**
     * 「Visa •••• 4242（2028 年 12 月まで）」の形（ADR 0045 の 4）
     */
    public function label(): string
    {
        $brand = $this->brand === null ? 'カード' : ucfirst($this->brand);
        $until = $this->exp_year === null || $this->exp_month === null ? '' : "（{$this->exp_year} 年 {$this->exp_month} 月まで）";

        return $brand.' •••• '.($this->last4 ?? '????').$until;
    }
}
