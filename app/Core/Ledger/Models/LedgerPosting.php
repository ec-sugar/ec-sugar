<?php

declare(strict_types=1);

namespace App\Core\Ledger\Models;

use App\Core\Ledger\Enums\LedgerAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 仕訳の明細（追記専用）。1 仕訳の明細の合計は 0（DB の遅延制約でも検査する）。
 *
 * @property string $id
 * @property string $journal_id
 * @property string $transaction_id
 * @property LedgerAccount $account
 * @property int $amount
 */
final class LedgerPosting extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'ledger_postings';

    protected $fillable = ['journal_id', 'transaction_id', 'account', 'amount'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('仕訳は追記専用です'));
        self::deleting(static fn (): never => throw new LogicException('仕訳は追記専用です'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account' => LedgerAccount::class,
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LedgerJournal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'journal_id');
    }
}
