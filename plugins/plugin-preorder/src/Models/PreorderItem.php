<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 注文ごとの予約（店の DB。ADR 0073）。注文の確定の錠の中で作り、発売日が変わった時だけ release_date を直す（PreorderListings）
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $listing_id
 * @property int $round
 * @property int $quantity
 * @property string $release_date この注文に約束した発売日（Y-m-d）
 */
final class PreorderItem extends Model
{
    use HasUlids;

    protected $table = 'preorder_items';

    protected $fillable = ['transaction_id', 'listing_id', 'round', 'quantity', 'release_date'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['round' => 'integer', 'quantity' => 'integer'];
    }

    public function releaseDate(): string
    {
        return substr((string) $this->release_date, 0, 10);
    }
}
