<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Storefront\View\ShopPhoto;

/**
 * 商品の説明の 1 つのブロック（テーマ API の一部。ADR 0032）。中身は店の人が書いた素の文字で、テーマは必ず {{ }} で出す。
 * 描くのは核の部品（<x-storefront::description-blocks>）。テーマが自分で描くときも、この形だけを読む。
 *
 * - paragraph: text（改行を含む。white-space: pre-line で出す）
 * - heading: level（2 = 大・3 = 小）、text
 * - list: ordered（番号つきか）、items
 * - image: photo（ShopPhoto。代わりの文字はショップの写真の説明）、caption
 * - table: header（1 行目が見出しか）、caption、rows（文字の並びの並び。空のますは ''）
 * - note: tone（info = お知らせ・warning = ご注意）、text
 */
final readonly class DescriptionBlock
{
    /**
     * @param  list<string>  $items
     * @param  list<list<string>>  $rows
     */
    private function __construct(
        public string $type,
        public ?string $text = null,
        public int $level = 2,
        public bool $ordered = false,
        public array $items = [],
        public ?ShopPhoto $photo = null,
        public ?string $caption = null,
        public bool $header = false,
        public array $rows = [],
        public string $tone = 'info',
    ) {}

    /**
     * 確かめた形のブロック（ProductDescription::readBlocks）から。写真はショップの写真にあるものだけ（無ければ null を返し、描かない）
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, ShopPhoto>  $photos  写真の ID => 写真
     */
    public static function from(array $block, array $photos): ?self
    {
        return match ($block['type']) {
            'paragraph' => new self('paragraph', text: $block['text']),
            'heading' => new self('heading', text: $block['text'], level: $block['level']),
            'list' => new self('list', ordered: $block['style'] === 'number', items: $block['items']),
            'image' => isset($photos[$block['media_id']]) ? new self('image', photo: $photos[$block['media_id']], caption: $block['caption']) : null,
            'table' => new self('table', caption: $block['caption'], header: $block['header'], rows: $block['rows']),
            'note' => new self('note', text: $block['text'], tone: $block['tone']),
            default => null,
        };
    }

    /**
     * 見出しの要素の名前。$top は大きい見出しの段（商品名が h1、「商品の説明」が h2 なら 3）
     */
    public function headingTag(int $top = 3): string
    {
        $level = max(2, min(5, $top)) + ($this->level === 3 ? 1 : 0);

        return "h{$level}";
    }

    /**
     * 表の見出しの行（header のときの 1 行目）
     *
     * @return list<string>
     */
    public function headRow(): array
    {
        return $this->header ? ($this->rows[0] ?? []) : [];
    }

    /**
     * 表の中身の行
     *
     * @return list<list<string>>
     */
    public function bodyRows(): array
    {
        return $this->header ? array_slice($this->rows, 1) : $this->rows;
    }
}
