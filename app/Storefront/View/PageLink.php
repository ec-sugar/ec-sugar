<?php

declare(strict_types=1);

namespace App\Storefront\View;

/**
 * メニューに載せる自由なページ（テーマ API 2 の $navPages。ADR 0039 区切り 3）。
 * title は店の人が付けた題（テーマは {{ }} で出す）、url はショップの中の道筋（/pages/{slug}）、current は今そのページを見ているか
 */
final readonly class PageLink
{
    public function __construct(
        public string $title,
        public string $url,
        public bool $current = false,
    ) {}
}
