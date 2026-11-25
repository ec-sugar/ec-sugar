<?php

declare(strict_types=1);

namespace EcSugar\PluginCatalogSort;

use App\Core\Plugin\Contracts\PluginDataPurger;
use EcSugar\PluginCatalogSort\Models\CatalogSortItem;

/**
 * 店で「並び順と絞り込み」のデータを消す（ADR 0092）。核が店の文脈・連鎖のロックの中で、App を止めた店でだけ呼ぶ。
 * 消すのは自分の表 catalog_sort_items の行だけ（表と商品は残す）。ファイルは持たない。
 * お客さんが受け取る物は無いので、断る理由は無い（止めた時点でショップの並びと絞り込みは出ていない）
 */
final class CatalogSortDataPurger implements PluginDataPurger
{
    public function refusal(): ?string
    {
        return null;
    }

    public function purgeRows(): array
    {
        return ['catalog_sort_items' => CatalogSortItem::query()->delete()];
    }

    public function purgeFiles(): int
    {
        return 0;
    }
}
