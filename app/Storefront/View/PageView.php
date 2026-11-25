<?php

declare(strict_types=1);

namespace App\Storefront\View;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * ページの区画の並び（テーマ API 2 の $page。ADR 0039）。店の人が公開した順に、出す区画だけを持つ。
 * 店の人が隠した区画と、テーマに部品（Blade）が無い種類の区画は入れない（エラーにせず飛ばす）。
 *
 * 自由なページ（ADR 0039 区切り 3）では title（題）と url も持つ。
 *
 * テーマは並びの順に部品を読み込むだけ（foreach で回し、それぞれを include($section->view, ['section' => $section])。docs/design/theme-api.md）
 *
 * @implements IteratorAggregate<int, SectionView>
 */
final readonly class PageView implements Countable, IteratorAggregate
{
    /**
     * @param  list<SectionView>  $sections  それぞれ view（部品の名前）が入っている
     * @param  string|null  $title  自由なページの題（ADR 0039 区切り 3。トップでは null）
     * @param  string|null  $url  自由なページのショップの URL（道筋。トップでは null）
     */
    public function __construct(
        public string $name,
        public array $sections,
        public ?string $title = null,
        public ?string $url = null,
    ) {}

    public function withTitle(string $title, string $url): self
    {
        return new self($this->name, $this->sections, $title, $url);
    }

    /**
     * @return Traversable<int, SectionView>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->sections);
    }

    public function count(): int
    {
        return count($this->sections);
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }
}
