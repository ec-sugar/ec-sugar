<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog\View;

/**
 * 区画「新着のお知らせ」に並べるもの（$section->app->content。テーマが区画を描き直すときも、この形だけを読む。ADR 0072）
 */
final readonly class NewsSection
{
    /**
     * @param  list<PostCard>  $items  新しい順
     * @param  string  $moreUrl  一覧の道筋（/news）
     */
    public function __construct(
        public array $items,
        public string $moreUrl,
    ) {}
}
