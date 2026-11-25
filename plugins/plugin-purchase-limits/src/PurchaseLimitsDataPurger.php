<?php

declare(strict_types=1);

namespace EcSugar\PluginPurchaseLimits;

use App\Core\Plugin\Contracts\PluginDataPurger;
use EcSugar\PluginPurchaseLimits\Models\ListingLimit;

/**
 * 止めた店で、オーナーが「この App のデータを消す」を選んだ時に呼ばれる（ADR 0092）。消すのは自分の表の行（商品ごとの上限）だけ。
 * App を止めた時点で決まりは効かなくなっている（商品はいくつでも、いくらからでも買える）ので、断る理由は無い。
 * 商品そのもの（核の listings）は消さない。ファイルは持たない
 */
final class PurchaseLimitsDataPurger implements PluginDataPurger
{
    public function refusal(): ?string
    {
        return null;
    }

    public function purgeRows(): array
    {
        return ['purchase_limits_listings' => ListingLimit::query()->delete()];
    }

    public function purgeFiles(): int
    {
        return 0;
    }
}
