<?php

declare(strict_types=1);

namespace App\Storefront\Seo;

use DateTimeInterface;

/**
 * 記事のページの構造化データ（Article）に載せる事実（ADR 0072。SeoBuilder::forAppPage に渡す）。
 * 見出し・説明・写真・URL はページの題などから核が入れるので、ここは日時と区分だけ
 */
final readonly class SeoArticle
{
    public function __construct(
        public DateTimeInterface $published,
        public DateTimeInterface $modified,
        public ?string $section = null,
    ) {}
}
