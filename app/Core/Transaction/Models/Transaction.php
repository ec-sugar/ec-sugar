<?php

declare(strict_types=1);

namespace App\Core\Transaction\Models;

use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Models\Party;
use App\Core\Transaction\Enums\PartyRole;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TaxRounding;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\TransactionFlow;
use App\Core\Transaction\TransactionFlows;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * 取引（テナント DB）。フェーズの語彙は共通で、並びは種類（kind）ごとに TransactionFlows が決める。
 *
 * 状態（phase / status / 金額）の変更は TransactionService を通す。ここで直接書き換えない
 * （遷移の検査とドメインイベントの記録が一緒に行われないため）。取引は削除しない。
 *
 * @property string $id
 * @property string|null $parent_id
 * @property string $kind
 * @property Phase $phase
 * @property Phase|null $suspended_phase
 * @property TransactionStatus $status
 * @property string|null $seller_id
 * @property string|null $customer_id
 * @property string|null $customer_account_id
 * @property PartyRole $payer
 * @property PartyRole $payee
 * @property string $currency
 * @property bool $prices_include_tax
 * @property TaxRounding $tax_rounding
 * @property int $subtotal_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property CarbonImmutable|null $agreed_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $closed_at
 */
final class Transaction extends Model
{
    use HasUlids;

    protected $table = 'transactions';

    protected $fillable = [
        'parent_id', 'kind', 'phase', 'suspended_phase', 'status', 'seller_id', 'customer_id', 'customer_account_id', 'payer', 'payee',
        'currency', 'prices_include_tax', 'tax_rounding', 'subtotal_amount', 'tax_amount', 'total_amount',
        'agreed_at', 'completed_at', 'closed_at',
    ];

    protected $attributes = [
        'status' => 'open',
        'currency' => 'JPY',
        'prices_include_tax' => true,
        'tax_rounding' => 'floor',
        'subtotal_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
    ];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('取引は削除できません（取消・返金のフェーズで終える）'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => Phase::class,
            'suspended_phase' => Phase::class,
            'status' => TransactionStatus::class,
            'payer' => PartyRole::class,
            'payee' => PartyRole::class,
            'prices_include_tax' => 'boolean',
            'tax_rounding' => TaxRounding::class,
            'subtotal_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'agreed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function flow(): TransactionFlow
    {
        return app(TransactionFlows::class)->get($this->kind);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }

    /**
     * @return HasMany<TransactionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TransactionLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * @return HasMany<LedgerJournal, $this>
     */
    public function journals(): HasMany
    {
        return $this->hasMany(LedgerJournal::class);
    }

    /**
     * @return HasMany<Fulfillment, $this>
     */
    public function fulfillments(): HasMany
    {
        return $this->hasMany(Fulfillment::class);
    }
}
