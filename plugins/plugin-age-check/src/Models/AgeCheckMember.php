<?php

declare(strict_types=1);

namespace EcSugar\PluginAgeCheck\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 会員が注文で確かめた年齢（「何歳以上か」だけ。ADR 0077）。書くのは注文の確定の錠の中（AgeCheckRules::remember）と退会（消す）だけ
 *
 * @property string $customer_account_id
 * @property int $age
 * @property Carbon $verified_at
 */
final class AgeCheckMember extends Model
{
    protected $table = 'age_check_members';

    protected $primaryKey = 'customer_account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['customer_account_id', 'age', 'verified_at'];

    protected $casts = ['age' => 'integer', 'verified_at' => 'datetime'];
}
