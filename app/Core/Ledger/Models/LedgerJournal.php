<?php

declare(strict_types=1);

namespace App\Core\Ledger\Models;

use App\Core\Ledger\Enums\JournalType;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * 仕訳（テナント DB、追記専用）。書き込みは LedgerService だけ。訂正は逆仕訳。
 *
 * @property string $id
 * @property string $transaction_id
 * @property JournalType $type
 * @property string $currency
 * @property string|null $reverses_journal_id
 * @property string|null $reference
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
final class LedgerJournal extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'ledger_journals';

    protected $fillable = ['transaction_id', 'type', 'currency', 'reverses_journal_id', 'reference', 'occurred_at'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('仕訳は追記専用です（逆仕訳で訂正する）'));
        self::deleting(static fn (): never => throw new LogicException('仕訳は追記専用です（逆仕訳で訂正する）'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JournalType::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return HasMany<LedgerPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(LedgerPosting::class, 'journal_id');
    }

    /**
     * @return HasOne<LedgerJournal, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_journal_id');
    }
}
