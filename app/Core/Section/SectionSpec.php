<?php

declare(strict_types=1);

namespace App\Core\Section;

use Closure;

/**
 * 区画の種類の宣言: 欄と、繰り返しの組（1 段だけ。例: 特集の 1〜3 個、安心の一行の 4 つ）。
 * ページの並び（ADR 0039）のために、見た目のパターン（例: ヒーローの全面・左右・格子）と、区画の中の小さなブロックの種類も持てる。
 * 見本（example・example_blocks）は、編集の画面で区画やブロックを足したときに最初から入れておく値（SectionSchema が宣言の決まりで確かめ済み）
 *
 * @phpstan-type Example array{values: array<string, mixed>, items: list<array<string, mixed>>}
 * @phpstan-type ExampleBlock array{type: string, values: array<string, mixed>}
 */
final readonly class SectionSpec
{
    /** パターンを宣言しない区画のパターン（今の書き方のテーマは、どの区画もこれ 1 つ） */
    public const string DEFAULT_PATTERN = 'default';

    /**
     * @param  array<string, FieldSpec>  $fields
     * @param  array<string, FieldSpec>  $itemFields  繰り返しの組の欄（組が無ければ空）
     * @param  int  $maxItems  繰り返しの組の数の上限（組が無ければ 0）
     * @param  array<string, string>  $patterns  見た目のパターン（名前 => 画面の名前。宣言の順。1 つ以上）
     * @param  array<string, BlockSpec>  $blocks  区画の中に置けるブロックの種類（無ければ空）
     * @param  int  $maxBlocks  区画の中のブロックの数の上限（ブロックが無ければ 0）
     * @param  int|null  $limit  1 つのページに置ける数（書かなければページの上限まで）
     * @param  Example|null  $example  欄と繰り返しの見本の値（書かなければ null。足した区画は空）
     * @param  list<ExampleBlock>  $exampleBlocks  ブロックの見本の並び（書かなければ空）
     * @param  array<string, string>  $wireframes  パターン => ワイヤーの絵の名前（Wireframe の語彙。書いたパターンだけ）
     * @param  (Closure(array<string, mixed>): array<string, string>)|null  $check  欄の値をもう一段確かめる（App の区画の係。ADR 0040）。
     *                                                                              保存のときだけ、宣言どおりに確かめた欄の値を渡し、欄の名前 => 断る理由 を返す
     * @param  SectionRole|null  $role  テーマの区画の役目（ヒーロー・カテゴリ・商品の並び・安心の一行。ページの雛形が種類を選ぶのに使う）
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $fields,
        public array $itemFields = [],
        public int $maxItems = 0,
        public ?string $itemLabel = null,
        public array $patterns = [self::DEFAULT_PATTERN => '標準'],
        public array $blocks = [],
        public int $maxBlocks = 0,
        public ?int $limit = null,
        public ?array $example = null,
        public array $exampleBlocks = [],
        public array $wireframes = [],
        public ?Closure $check = null,
        public ?SectionRole $role = null,
    ) {}

    /**
     * 見本を付けた宣言
     *
     * @param  Example|null  $example
     * @param  list<ExampleBlock>  $blocks
     */
    public function withExample(?array $example, array $blocks): self
    {
        return new self($this->key, $this->label, $this->fields, $this->itemFields, $this->maxItems, $this->itemLabel,
            $this->patterns, $this->blocks, $this->maxBlocks, $this->limit, $example, $blocks, $this->wireframes, $this->check, $this->role);
    }

    /**
     * 欄の値をもう一段確かめる係を付けた宣言（App の区画。ADR 0040）
     *
     * @param  Closure(array<string, mixed>): array<string, string>  $check
     */
    public function withCheck(Closure $check): self
    {
        return new self($this->key, $this->label, $this->fields, $this->itemFields, $this->maxItems, $this->itemLabel,
            $this->patterns, $this->blocks, $this->maxBlocks, $this->limit, $this->example, $this->exampleBlocks, $this->wireframes, $check, $this->role);
    }

    public function hasItems(): bool
    {
        return $this->maxItems > 0;
    }

    public function hasBlocks(): bool
    {
        return $this->maxBlocks > 0;
    }

    /**
     * パターンを選ばなかったときのパターン（宣言の最初）
     */
    public function defaultPattern(): string
    {
        return (string) array_key_first($this->patterns);
    }

    public function hasPattern(string $pattern): bool
    {
        return array_key_exists($pattern, $this->patterns);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $section = [
            'label' => $this->label,
            'fields' => array_map(static fn (FieldSpec $f): array => $f->toArray(), $this->fields),
            'patterns' => $this->patterns,
        ];

        if ($this->hasItems()) {
            $section['items'] = array_filter([
                'label' => $this->itemLabel,
                'max' => $this->maxItems,
                'fields' => array_map(static fn (FieldSpec $f): array => $f->toArray(), $this->itemFields),
            ], static fn (mixed $value): bool => $value !== null);
        }

        if ($this->hasBlocks()) {
            $section['blocks'] = array_map(static fn (BlockSpec $b): array => $b->toArray(), $this->blocks);
            $section['max_blocks'] = $this->maxBlocks;
        }

        if ($this->limit !== null) {
            $section['limit'] = $this->limit;
        }

        // 見本（編集の画面が、足した区画とブロックに入れる）
        if ($this->example !== null) {
            $section['example'] = $this->example;
        }

        if ($this->exampleBlocks !== []) {
            $section['example_blocks'] = $this->exampleBlocks;
        }

        // ワイヤーの絵の名前（編集の画面の「＋ 区画を足す」が絵で出す。書いていないパターンは汎用の絵）
        if ($this->wireframes !== []) {
            $section['wireframes'] = $this->wireframes;
        }

        return $section;
    }
}
