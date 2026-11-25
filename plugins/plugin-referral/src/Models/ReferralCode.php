<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 会員の紹介のリンクの印（ADR 0075）。会員に 1 つ。書くのは ReferralCodes だけ
 *
 * @property string $customer_account_id
 * @property string $code
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ReferralCode extends Model
{
    public $incrementing = false;

    protected $table = 'referral_codes';

    protected $primaryKey = 'customer_account_id';

    protected $keyType = 'string';

    protected $fillable = ['customer_account_id', 'code'];

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
