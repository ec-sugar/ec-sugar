<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 取り込んだ CSV の 1 行（発送にする ready・飛ばす skipped〔reason〕・発送にした shipped）。送り状番号は店の鍵で暗号化する。
 * reference はファイルに書いてあった注文番号（形の合うものだけ。大文字にそろえる）
 *
 * @property string $id
 * @property string $import_id
 * @property int $line
 * @property ?string $reference
 * @property ?string $transaction_id
 * @property ?string $fulfillment_id
 * @property ?string $tracking_number
 * @property string $status
 * @property ?string $reason
 */
final class ShippingImportRow extends Model
{
    use HasUlids;

    protected $table = 'shipping_csv_import_rows';

    protected $fillable = ['import_id', 'line', 'reference', 'transaction_id', 'fulfillment_id', 'tracking_number', 'status', 'reason'];

    /** 送り状番号は配列にも JSON にも出さない */
    protected $hidden = ['tracking_number'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['line' => 'integer', 'tracking_number' => TenantEncrypted::class];
    }
}
