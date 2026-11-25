<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use App\Core\Crypto\Casts\TenantEncryptedJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 店の決済代行の鍵（ADR 0019）。変えるのは PaymentGatewayService だけ。鍵（credentials）は画面・ログ・イベントに出さない。
 *
 * @property string $id
 * @property string $provider
 * @property array<string, mixed>|null $credentials
 * @property string $mode
 * @property string $key_hint
 * @property string|null $connect_status つないだ店（stripe_connect）の登録の状態
 * @property CarbonImmutable $updated_at
 */
final class PaymentGateway extends Model
{
    use HasUlids;

    protected $table = 'payment_gateways';

    protected $fillable = ['provider', 'credentials', 'mode', 'key_hint', 'connect_status'];

    /** 配列や JSON にしても鍵を出さない */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => TenantEncryptedJson::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
