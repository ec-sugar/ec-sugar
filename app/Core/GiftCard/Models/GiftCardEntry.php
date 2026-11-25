<?php

declare(strict_types=1);

namespace App\Core\GiftCard\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * ギフトカードの残高の帳簿の 1 行（ADR 0076）。追記だけで、書き換え・消すのは DB が断る。書くのは GiftCardService だけ。
 * balance_after（この行の後の残高）と card_seq（カードごとの連番）は DB のトリガーが前の行から計算して入れ、負になる行は断る。
 * note は店の人の手の足し引きの理由（自由な文なので暗号化。ADR 0010）
 *
 * @property string $id
 * @property int $seq
 * @property int $card_seq
 * @property string $gift_card_id
 * @property string $kind issue・spend・release・expire・adjust
 * @property int $amount
 * @property int $balance_after
 * @property string|null $transaction_id
 * @property string|null $cause 戻した理由（release だけ: cancel・shipping・refund）
 * @property string|null $note
 * @property string|null $actor
 * @property CarbonImmutable $occurred_at
 */
final class GiftCardEntry extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'gift_card_entries';

    protected $fillable = ['gift_card_id', 'kind', 'amount', 'transaction_id', 'cause', 'note', 'actor', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'card_seq' => 'integer',
            'amount' => 'integer',
            'balance_after' => 'integer',
            'note' => TenantEncrypted::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
