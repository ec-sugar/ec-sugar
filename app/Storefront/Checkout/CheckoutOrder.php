<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 注文の合い言葉の指紋 → 取引（二重送信を防ぐ）
 *
 * @property string $id
 * @property string $key_hash
 * @property string $transaction_id
 * @property string|null $source_index 接続元の印（店の鍵の HMAC。入金待ちを数えるためだけ。ADR 0085）
 * @property string|null $source_wide_index 2 段目の接続元の印（IPv6 の /48。IPv4 は null）
 * @property string|null $email_mark 数えるときだけ正規化したメールの印（店の鍵の HMAC。メールの 1 時間の確定の数と入金待ちを数えるためだけ）
 */
final class CheckoutOrder extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['key_hash', 'transaction_id', 'pays_with', 'source_index', 'source_wide_index', 'email_mark'];
}
