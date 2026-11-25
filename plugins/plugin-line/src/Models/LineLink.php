<?php

declare(strict_types=1);

namespace EcSugar\PluginLine\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 会員と LINE の利用者のつなぎ（ADR 0070）。書くのは LineLinks だけ。
 * LINE の利用者の ID は店の鍵で暗号化し（line_user）、引くのは line_user_index（LineLinks::index）の完全一致だけ
 *
 * @property string $id
 * @property string $customer_account_id
 * @property string $line_user
 * @property string $line_user_index
 * @property bool $friend
 * @property ?int $friend_at_ms
 * @property CarbonImmutable $linked_at
 */
final class LineLink extends Model
{
    use HasUlids;

    protected $table = 'line_links';

    protected $fillable = ['customer_account_id', 'line_user', 'line_user_index', 'friend', 'friend_at_ms', 'linked_at'];

    // LINE の利用者の ID は、モデルを配列や JSON にしても出さない
    protected $hidden = ['line_user', 'line_user_index'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_user' => TenantEncrypted::class,
            'friend' => 'boolean',
            'friend_at_ms' => 'integer',
            'linked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
