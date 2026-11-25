<?php

declare(strict_types=1);

namespace App\Core\Transaction\Models;

use App\Core\Listing\Models\Seller;
use App\Core\Transaction\Enums\QuoteStatus;
use App\Core\Transaction\LineDraft;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 提示（査定額・見積・提案）。1 取引に何度でも、複数の出品者から来てよい（CONCEPT 5.4）。
 * 採られた提示の明細が取引の明細になり、取引は Agreement に進む。
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $seller_id
 * @property QuoteStatus $status
 * @property list<array<string, mixed>> $lines
 * @property int $subtotal_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property CarbonImmutable|null $valid_until
 * @property CarbonImmutable|null $decided_at
 */
final class Quote extends Model
{
    use HasUlids;

    protected $table = 'transaction_quotes';

    protected $fillable = [
        'transaction_id', 'seller_id', 'status', 'lines', 'subtotal_amount', 'tax_amount', 'total_amount',
        'valid_until', 'decided_at',
    ];

    protected $attributes = [
        'status' => 'proposed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'lines' => 'array',
            'subtotal_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'valid_until' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<LineDraft>
     */
    public function drafts(): array
    {
        return array_map(LineDraft::fromArray(...), $this->lines);
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
