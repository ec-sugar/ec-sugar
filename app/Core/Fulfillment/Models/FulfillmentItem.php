<?php

declare(strict_types=1);

namespace App\Core\Fulfillment\Models;

use App\Core\Transaction\Models\TransactionLine;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受渡に含まれる明細と数量（分納のため明細の数量の一部でよい）。
 *
 * @property string $id
 * @property string $fulfillment_id
 * @property string $transaction_line_id
 * @property int $quantity
 */
final class FulfillmentItem extends Model
{
    use HasUlids;

    protected $table = 'fulfillment_items';

    protected $fillable = ['fulfillment_id', 'transaction_line_id', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Fulfillment, $this>
     */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(Fulfillment::class);
    }

    /**
     * @return BelongsTo<TransactionLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(TransactionLine::class, 'transaction_line_id');
    }
}
