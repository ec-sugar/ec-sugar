<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog\View;

/**
 * 記事の一覧の画面（テーマの news の画面が読む形。ADR 0072）。種類で絞る札（categories: name・url・current）とページ送りの道筋つき。
 * URL はどれも道筋。文は店の人が書いた素の文字で、テーマは必ず {{ }} で出す
 */
final readonly class NewsList
{
    /**
     * @param  list<PostCard>  $items
     * @param  list<array{name: string, url: string, current: bool}>  $categories  「すべて」を先頭に
     */
    public function __construct(
        public array $items,
        public array $categories,
        public ?string $category,
        public int $page,
        public int $lastPage,
        public int $total,
        public ?string $previousUrl,
        public ?string $nextUrl,
        public string $feedUrl,
    ) {}

    /** 画面の見出し（種類で絞っているときは種類の名前） */
    public function title(): string
    {
        return $this->category ?? 'お知らせ';
    }
}
