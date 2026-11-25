<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites;

/**
 * お気に入りに足した結果
 */
enum AddOutcome
{
    case Added;
    case Already;
    case Full;        // 1 人 500 件を越える
    case Unavailable; // ショップに出ていない商品
}
