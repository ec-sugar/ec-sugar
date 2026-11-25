<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle;

use App\Core\Plugin\Contracts\PluginDataPurger;
use EcSugar\PluginBundle\Models\BundleComponent;

/**
 * 止めた店で、オーナーが「この App のデータを消す」を選んだ時に呼ばれる（ADR 0092）。消すのは自分の表の行だけ。
 * App を止めた時点でセットは効かなくなっている（核が構成の品を引かず、セットの商品は自分の在庫で売れる）ので、断る理由は無い。
 * 商品そのもの（核の listings）は消さない。ファイルは持たない
 */
final class BundleDataPurger implements PluginDataPurger
{
    public function refusal(): ?string
    {
        return null;
    }

    public function purgeRows(): array
    {
        return ['bundle_components' => BundleComponent::query()->delete()];
    }

    public function purgeFiles(): int
    {
        return 0;
    }
}
