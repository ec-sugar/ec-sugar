<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

/**
 * 集めたフィードの行と、出さなかった商品（管理画面に数と理由を出す）
 */
final readonly class FeedResult
{
    /**
     * @param  list<FeedItem>  $items  行（種類ごと）
     * @param  int  $listings  行を出した商品の数
     * @param  array<string, string>  $excluded  店の人が外した商品（商品の ID => 名前）
     * @param  array<string, array{title: string, reason: string}>  $skipped  出せない商品（商品の ID => 名前と FeedBuilder::REASONS の印）
     * @param  int  $over  行の上限を超えて出さなかった商品の数
     */
    public function __construct(
        public array $items,
        public int $listings,
        public array $excluded,
        public array $skipped,
        public int $over,
    ) {}
}
