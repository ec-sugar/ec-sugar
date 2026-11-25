<?php

declare(strict_types=1);

namespace EcSugar\PluginFaq;

use App\Core\Plugin\Contracts\PluginDataPurger;
use EcSugar\PluginFaq\Models\FaqItem;

/**
 * 店で「よくある質問」のデータを消す（ADR 0092。核の口 PluginDataPurger のいちばん小さい見本）。
 * 核が店の文脈の中・連鎖のロックの中で、App を止めた店でだけ呼ぶ。消すのは自分の表 faq_items の行だけ（表は残す）。ファイルは持たない
 */
final class FaqDataPurger implements PluginDataPurger
{
    public function refusal(): ?string
    {
        return null;
    }

    public function purgeRows(): array
    {
        return ['faq_items' => FaqItem::query()->delete()];
    }

    public function purgeFiles(): int
    {
        return 0;
    }
}
