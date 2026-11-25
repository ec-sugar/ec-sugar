<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 記事の種類（お知らせ・ブログと、店が足したもの。ADR 0072）。slug はショップの一覧の絞り込み（/news?category=…）の名前。
 * 書くのは BlogPosts だけ（記事を足す・直す欄の「新しい種類」から）
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property int $position
 * @property CarbonImmutable $created_at
 */
final class BlogCategory extends Model
{
    use HasUlids;

    protected $table = 'blog_categories';

    protected $fillable = ['name', 'slug', 'position'];

    /**
     * 並びの順のすべての種類（多くても 10 個）
     *
     * @return Collection<int, BlogCategory>
     */
    public static function ordered(): Collection
    {
        return self::query()->orderBy('position')->orderBy('id')->get();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
