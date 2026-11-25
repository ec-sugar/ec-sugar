<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use EcSugar\PluginProductFeed\FeedFormat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * フィードの行き先 1 つ（Google・Meta。移行で作る）と、その URL の秘密のトークン。書くのは FeedTokens だけ（連鎖のロックとイベント）。
 * token は店の鍵で暗号化して置き（管理画面に URL を出すため）、照らすのは token_hash（SHA-256）で
 *
 * @property string $id
 * @property FeedFormat $format
 * @property ?string $token
 * @property ?string $token_hash
 * @property ?CarbonImmutable $issued_at
 */
final class Feed extends Model
{
    use HasUlids;

    protected $table = 'product_feed_feeds';

    protected $fillable = ['token', 'token_hash', 'issued_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['format' => FeedFormat::class, 'token' => TenantEncrypted::class, 'issued_at' => 'immutable_datetime'];
    }
}
