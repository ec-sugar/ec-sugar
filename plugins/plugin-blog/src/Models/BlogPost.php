<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * お知らせ・ブログの記事 1 つ（ADR 0072）。書くのは BlogPosts だけ（連鎖のロックとイベント）。
 * 本文（body）は商品の説明と同じブロックの並び（ADR 0032。確かめるのは核の ProductDescription）。
 * 状態は下書き（draft）か公開（published）。公開の日時（published_at）が今より先の公開は「予約」で、その時までショップに出ない。
 * 有料の記事は、読める条件（access_keys）と「ここから有料」より後ろの本文（paid_body）を持つ。
 * **どこで切るかを決めるのは BlogPaywall だけ**（この列を直に読んで出さない）
 *
 * @property string $id
 * @property string $category_id
 * @property string $title
 * @property string $slug
 * @property ?string $excerpt
 * @property ?string $image_id
 * @property list<array<string, mixed>> $body
 * @property list<string> $access_keys
 * @property list<array<string, mixed>> $paid_body
 * @property string $status
 * @property ?CarbonImmutable $publish_at
 * @property ?CarbonImmutable $published_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BlogPost extends Model
{
    use HasUlids;

    public const string DRAFT = 'draft';

    public const string PUBLISHED = 'published';

    protected $table = 'blog_posts';

    protected $fillable = ['category_id', 'title', 'slug', 'excerpt', 'image_id', 'body', 'access_keys', 'paid_body', 'status', 'publish_at', 'published_at'];

    /** 新しい行の最初の値（DB の既定と同じ。作ったばかりのモデルから読んでも null にしない） */
    protected $attributes = ['access_keys' => '[]', 'paid_body' => '[]'];

    /**
     * ショップに出ている記事だけ（公開で、公開の日時が今まで）。ショップ・サイトマップ・Atom・区画はどれもここを通る
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public static function visible(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        return $query->where('status', self::PUBLISHED)->where('published_at', '<=', $now ?? CarbonImmutable::now());
    }

    /**
     * @return Builder<BlogPost>
     */
    public static function shown(): Builder
    {
        return self::visible(self::query());
    }

    /**
     * 無料で読める分がある記事だけ。有料の記事でも無料の分は検索の入口にするので載せ、
     * **全部有料**（読める条件があって「ここから有料」が空）の記事だけ外す（検索の機械に出せる中身が無いため）。
     * 切り方の決まりは `BlogPaywall::free()` と同じで、こちらは SQL で数えるだけ（崩れたブロックの数は見ない）
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public static function withFreeContent(Builder $query): Builder
    {
        return $query->whereRaw('(jsonb_array_length(access_keys) = 0 OR (jsonb_array_length(body) > 0 AND jsonb_array_length(paid_body) > 0))');
    }

    public function isScheduled(): bool
    {
        return $this->status === self::PUBLISHED && $this->published_at !== null && $this->published_at->isFuture();
    }

    public function isLive(): bool
    {
        return $this->status === self::PUBLISHED && $this->published_at !== null && ! $this->published_at->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => 'array',
            'access_keys' => 'array',
            'paid_body' => 'array',
            'publish_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
