<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog\View;

use App\Storefront\View\ShopPhoto;
use Carbon\CarbonImmutable;

/**
 * 一覧と区画に出す記事 1 つ（テーマが news の画面・区画の部品を描き直すときも、この形だけを読む。ADR 0072）。
 * 文は店の人が書いた素の文字で、テーマは必ず {{ }} で出す。url は道筋（/news/{slug}）
 */
final readonly class PostCard
{
    public function __construct(
        public string $title,
        public string $url,
        public CarbonImmutable $publishedAt,
        public string $category,
        public string $categorySlug,
        public ?string $excerpt,
        public ?ShopPhoto $photo,
    ) {}

    /** 画面に出す日付（2026.11.05） */
    public function date(): string
    {
        return $this->publishedAt->setTimezone(BlogReader::TIMEZONE)->format('Y.m.d');
    }

    /** <time datetime> に入れる日付 */
    public function isoDate(): string
    {
        return $this->publishedAt->setTimezone(BlogReader::TIMEZONE)->format('Y-m-d');
    }
}
