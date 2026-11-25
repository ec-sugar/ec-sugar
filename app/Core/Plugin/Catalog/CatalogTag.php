<?php

declare(strict_types=1);

namespace App\Core\Plugin\Catalog;

/**
 * ショップの一覧の「タグで絞り込む」のタグ 1 つ（App「並び順と絞り込み」が決め、核が確かめて使う）。
 * key は URL に出る言葉で、核は App が返した key と同じものだけを App に渡し直す（入力から列や SQL を作らない）
 */
final readonly class CatalogTag
{
    public function __construct(
        public string $key,
        public string $label,
    ) {}
}
