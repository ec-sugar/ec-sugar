<?php

declare(strict_types=1);

namespace App\Core\Section;

/**
 * 区画の 1 つの欄の宣言（テーマの theme.json から。SectionSchema が形を確かめてから作る）
 */
final readonly class FieldSpec
{
    /**
     * @param  int|null  $max  文の長さか並びの数の上限（核の上限以下）。上限の無い種類は null
     * @param  array<string, string>  $options  選択肢（値 => 画面の名前）。choice だけ
     * @param  list<string>  $sources  商品の並びの出どころ（FieldType::PRODUCT_SOURCES の中から）。products だけ
     */
    public function __construct(
        public string $key,
        public FieldType $type,
        public string $label,
        public ?int $max = null,
        public array $options = [],
        public array $sources = [],
        public ?string $help = null,
    ) {}

    /**
     * 空のときの値
     */
    public function empty(): mixed
    {
        return $this->type === FieldType::Categories ? [] : null;
    }

    /**
     * 管理画面に渡す形（欄を作るのに使う）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'label' => $this->label,
            'max' => $this->max,
            'options' => $this->options === [] ? null : $this->options,
            'from' => $this->sources === [] ? null : $this->sources,
            'help' => $this->help,
            // HTML の欄: 残す class の一覧（名前 => 何に使うか）。管理画面が欄の説明に出す
            'classes' => $this->type->isHtml() ? SectionHtml::CLASSES : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
