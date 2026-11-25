<?php

declare(strict_types=1);

namespace App\Core\Section;

/**
 * 区画の HTML を整えた結果（SectionHtml::clean）
 */
final readonly class CleanedHtml
{
    /**
     * @param  string  $html  整えた HTML（ショップにそのまま出してよい形）。断ったときは空
     * @param  list<string>  $removed  外したもの（管理画面に知らせる。「<script>」「onclick の属性（スクリプト）」など）
     * @param  string|null  $refused  断った理由（大きすぎる・深すぎる・壊れた文字）。断ったら何も残さない
     */
    public function __construct(
        public string $html,
        public array $removed = [],
        public ?string $refused = null,
    ) {}

    public static function refused(string $reason): self
    {
        return new self('', [], $reason);
    }
}
