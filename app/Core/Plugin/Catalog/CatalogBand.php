<?php

declare(strict_types=1);

namespace App\Core\Plugin\Catalog;

/**
 * ショップの一覧の「値段で絞り込む」の帯 1 つ（App「並び順と絞り込み」が決め、核が確かめて使う）。
 * 金額は整数（円）。max が null なら「min 円以上」。key は URL に出る短い言葉（核が形を確かめる）
 */
final readonly class CatalogBand
{
    public function __construct(
        public string $key,
        public string $label,
        public int $min,
        public ?int $max,
    ) {}
}
