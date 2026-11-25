<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 領収書を出した回数（店の DB。注文ごとに 1 行）。書くのは ReceiptIssues だけ。宛名や但し書きは持たない
 *
 * @property string $id
 * @property string $transaction_id
 * @property int $issued_count
 * @property CarbonImmutable $first_issued_at
 * @property CarbonImmutable $last_issued_at
 */
final class OrderPrintReceipt extends Model
{
    use HasUlids;

    protected $table = 'order_print_receipts';

    protected $fillable = ['transaction_id', 'issued_count', 'first_issued_at', 'last_issued_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_count' => 'integer',
            'first_issued_at' => 'immutable_datetime',
            'last_issued_at' => 'immutable_datetime',
        ];
    }
}
