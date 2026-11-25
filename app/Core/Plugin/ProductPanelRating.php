<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use InvalidArgumentException;

/**
 * 商品の画面の並び（ProductPanel）の上に出す、平均の星と件数（App「商品レビュー」。2026-11-04）。
 * 平均は 10 倍した整数で持つ（4.3 は 43。浮動小数で持たない）。描くのは核の部品（星の絵と「5 段階中 4.3」の読み上げ）
 */
final readonly class ProductPanelRating
{
    public function __construct(
        public int $tenths,
        public int $count,
    ) {
        if ($tenths < 10 || $tenths > 50 || $count < 1) {
            throw new InvalidArgumentException('平均の星は 1.0〜5.0（10 倍して 10〜50）、件数は 1 以上');
        }
    }

    /**
     * 画面に出す平均（「4.3」）
     */
    public function average(): string
    {
        return intdiv($this->tenths, 10).'.'.($this->tenths % 10);
    }

    /**
     * 星の絵に使う、いちばん近い整数の星の数（4.3 は 4、4.5 は 5）
     */
    public function stars(): int
    {
        return intdiv($this->tenths + 5, 10);
    }
}
