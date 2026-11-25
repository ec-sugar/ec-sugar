<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

/**
 * 店頭に出す商品の写真（テーマ API の一部）。大きい方（large）の縦横を持ち、中くらい（medium）は同じ比率で縮めたもの。
 * プレビューの画面では、公開していない商品の写真も見せるため、URL にプレビューの署名（$preview）を付ける
 */
final readonly class ListingPhoto
{
    /**
     * @param  string  $alt  代わりの文字（登録が無ければ商品名）
     * @param  array<string, string>  $preview  プレビューの署名（ListingPreviewLink::query()。ふだんは空）
     */
    public function __construct(
        public string $id,
        public string $alt,
        public int $width,
        public int $height,
        private array $preview = [],
    ) {}

    /**
     * @param  'large'|'medium'  $variant
     */
    public function url(string $variant = 'medium'): string
    {
        return route('storefront.media.listing', ['imageId' => $this->id, 'variant' => $variant] + $this->preview, false);
    }

    /**
     * img の srcset。写真が小さく、中くらいと大きい方が同じなら 1 つだけ
     */
    public function srcset(): string
    {
        $medium = (int) round($this->width * min(1.0, 800 / max($this->width, $this->height)));

        return $medium < $this->width
            ? $this->url('medium')." {$medium}w, ".$this->url('large')." {$this->width}w"
            : $this->url('large')." {$this->width}w";
    }
}
