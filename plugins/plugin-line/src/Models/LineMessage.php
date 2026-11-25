<?php

declare(strict_types=1);

namespace EcSugar\PluginLine\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * LINE で送った知らせ 1 通の記録（ADR 0070）。書くのは LineNotifier だけ。宛先の ID と本文は持たない（数と結果だけ）
 *
 * @property string $id
 * @property string $dedupe_key
 * @property string $kind
 * @property ?string $transaction_id
 * @property string $status
 * @property ?string $error
 * @property ?CarbonImmutable $sent_at
 * @property CarbonImmutable $created_at
 */
final class LineMessage extends Model
{
    use HasUlids;

    protected $table = 'line_messages';

    protected $fillable = ['dedupe_key', 'kind', 'transaction_id', 'status', 'error', 'sent_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
