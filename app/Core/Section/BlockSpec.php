<?php

declare(strict_types=1);

namespace App\Core\Section;

/**
 * 区画の中の小さなブロックの宣言（ADR 0039。例: 見出し・文・ボタン）。ブロックの種類ごとに欄を持つ。
 * 欄の種類は区画と同じ核の 7 つだけ（SectionSchema が形を確かめてから作る）
 */
final readonly class BlockSpec
{
    /**
     * @param  array<string, FieldSpec>  $fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $fields,
    ) {}

    /**
     * 管理画面に渡す形
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'fields' => array_map(static fn (FieldSpec $f): array => $f->toArray(), $this->fields)];
    }
}
