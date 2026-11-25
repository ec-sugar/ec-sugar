<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog;

/**
 * 1 つの記事を「今の人に出せる形」にしたもの（`BlogPaywall::read()` の答え）。
 * `blocks` は**送ってよいブロックだけ**で、落とした分はここに入らない（レスポンスに入れないため）
 */
final readonly class BlogReading
{
    /**
     * @param  list<array<string, mixed>>  $blocks  送ってよいブロック（核の形。描くのは DescriptionBlock）
     * @param  bool  $locked  有料の分を落としたか（落としたときだけ案内を出す）
     * @param  list<string>  $keys  記事の読める条件（鍵）
     * @param  bool  $signedIn  会員としてログインしているか（案内の文と導線を変えるため）
     * @param  bool  $hasFreePart  無料で読める分があるか（無ければ「この記事は〜」の案内にする）
     */
    public function __construct(
        public array $blocks,
        public bool $locked,
        public array $keys,
        public bool $signedIn,
        public bool $hasFreePart,
    ) {}
}
