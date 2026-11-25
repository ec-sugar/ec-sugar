<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Core\Media\ImageProcessor;

/**
 * 店の写真（テーマ API の一部。ヒーローの写真と区画の写真。ファイルはショップの写真の置き場にある。ADR 0028 区切り 1）。大きい方（large）の縦横を持ち、中くらい（medium）は同じ比率で縮めたもの。
 * ヒーローは飾りの写真なので代わりの文字を持たない（意味は見出しで伝える。テーマは alt="" で出す）。区画の写真は店の人が書いた説明を持つ。
 */
final readonly class ShopPhoto
{
    /** 区画の試し見の仮の写真（無地の枠。核が public に置く静的なファイル） */
    public const string PLACEHOLDER = '/storefront/trial-photo.svg';

    /**
     * @param  string|null  $alt  店の人が書いた写真の説明（区画の写真。意味のある写真に使う。飾りなら alt="" で出す）
     * @param  string|null  $file  置き場の写真でなく、決まったファイルを指すとき（試し見の仮の写真だけ）
     */
    public function __construct(
        public string $id,
        public int $width,
        public int $height,
        public ?string $alt = null,
        private ?string $file = null,
    ) {}

    /**
     * 区画の試し見の仮の写真。見本には写真を書けない（ADR 0039）ので、写真が無いと何も描かない区画も形が分かるように、無地の枠を入れる
     */
    public static function placeholder(): self
    {
        return new self('placeholder', 1200, 800, null, self::PLACEHOLDER);
    }

    /**
     * @param  'large'|'medium'  $variant
     */
    public function url(string $variant = 'large'): string
    {
        return $this->file ?? route('storefront.media.shop', ['mediaId' => $this->id, 'variant' => $variant], false);
    }

    public function srcset(): string
    {
        if ($this->file !== null) {
            return "{$this->file} {$this->width}w";
        }

        $limit = ImageProcessor::SHOP_VARIANTS['medium'];
        $medium = (int) round($this->width * min(1.0, $limit / max($this->width, $this->height)));

        return $medium < $this->width
            ? $this->url('medium')." {$medium}w, ".$this->url('large')." {$this->width}w"
            : $this->url('large')." {$this->width}w";
    }
}
