<?php

declare(strict_types=1);

namespace EcSugar\PluginWrapping\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 注文ごとに選んだラッピング・のし（ADR 0078）。種類の名前・額・表書きは注文の時の写し（店が後で種類を直しても、その注文の内容は変わらない）。
 * 名入れは個人情報になりうるので店の鍵で暗号化する（ADR 0010）。追記だけ（DB のトリガーで直す・消すを断る）
 *
 * @property string $transaction_id
 * @property ?string $wrapping_type_id
 * @property ?string $wrapping_name
 * @property int $wrapping_amount
 * @property ?string $noshi_title
 * @property ?string $noshi_style
 * @property ?string $noshi_name
 * @property int $noshi_amount
 * @property CarbonImmutable $created_at
 */
final class WrappingOrder extends Model
{
    public const string OUTER = 'outer';

    public const string INNER = 'inner';

    public const array STYLES = [self::OUTER => '外のし', self::INNER => '内のし'];

    protected $table = 'wrapping_orders';

    protected $primaryKey = 'transaction_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = ['transaction_id', 'wrapping_type_id', 'wrapping_name', 'wrapping_amount', 'noshi_title', 'noshi_style', 'noshi_name', 'noshi_amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wrapping_amount' => 'integer',
            'noshi_amount' => 'integer',
            'noshi_name' => TenantEncrypted::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
