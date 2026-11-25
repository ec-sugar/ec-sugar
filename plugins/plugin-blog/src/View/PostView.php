<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog\View;

use App\Storefront\Catalog\DescriptionBlock;
use App\Storefront\View\ShopPhoto;
use Carbon\CarbonImmutable;

/**
 * 記事 1 つの画面（テーマの news-post の画面が読む形。ADR 0072）。本文は商品の説明と同じブロック（核の部品 <x-storefront::description-blocks> で描く）。
 * 文は店の人が書いた素の文字で、テーマは必ず {{ }} で出す。publishedAt は下書きのプレビューでは null。
 *
 * blocks は**この人に送ってよいブロックだけ**（有料の記事で権利が無ければ、無料の分までで切ってある）。
 * 切ったときは paywall に案内が入るので、テーマは本文の後ろにそれを出す。plainText も無料の分だけ（検索と共有の説明に使う）
 */
final readonly class PostView
{
    /**
     * @param  list<DescriptionBlock>  $blocks
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?CarbonImmutable $publishedAt,
        public string $category,
        public string $categorySlug,
        public ?string $excerpt,
        public ?ShopPhoto $photo,
        public array $blocks,
        public ?string $plainText,
        public CarbonImmutable $updatedAt,
        public ?PostPaywall $paywall = null,
    ) {}

    public function date(): ?string
    {
        return $this->publishedAt?->setTimezone(BlogReader::TIMEZONE)->format('Y.m.d');
    }

    public function isoDate(): ?string
    {
        return $this->publishedAt?->setTimezone(BlogReader::TIMEZONE)->format('Y-m-d');
    }
}
