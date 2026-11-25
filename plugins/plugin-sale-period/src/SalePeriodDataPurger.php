<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod;

use App\Core\Plugin\Contracts\PluginDataPurger;
use EcSugar\PluginSalePeriod\Models\SaleWindow;

/**
 * 止めた店で、オーナーが「この App のデータを消す」を選んだ時に呼ばれる（ADR 0092）。消すのは自分の表の行だけ。
 * App を止めた時点で期間は効かなくなっている（商品はふだんどおり売れる）ので、断る理由は無い。ファイルは持たない
 */
final class SalePeriodDataPurger implements PluginDataPurger
{
    public function refusal(): ?string
    {
        return null;
    }

    public function purgeRows(): array
    {
        return ['sale_period_windows' => SaleWindow::query()->delete()];
    }

    public function purgeFiles(): int
    {
        return 0;
    }
}
