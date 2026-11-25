<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 商品レビューの 1 つ（どの注文のどの商品か・星・題・本文・表示名・状態・店からの返信）。書くのは Reviews だけ（連鎖のロックとイベント）。
 * 状態は pending（店が確かめる前）・published（ショップに出す）・hidden（出さない）・deleted（消した。文は空）
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $listing_id
 * @property int $rating
 * @property ?string $title
 * @property ?string $body
 * @property ?string $display_name
 * @property string $status
 * @property ?string $reply
 * @property ?CarbonImmutable $replied_at
 * @property ?CarbonImmutable $published_at
 * @property CarbonImmutable $created_at
 */
final class Review extends Model
{
    use HasUlids;

    public const array STATUSES = ['pending', 'published', 'hidden', 'deleted'];

    protected $table = 'reviews';

    protected $fillable = ['transaction_id', 'listing_id', 'rating', 'title', 'body', 'display_name', 'status', 'reply', 'replied_at', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'replied_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
