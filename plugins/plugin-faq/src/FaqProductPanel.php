<?php

declare(strict_types=1);

namespace EcSugar\PluginFaq;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ProductPanelProvider;
use App\Core\Plugin\ProductPanel;
use App\Core\Plugin\ProductPanelItem;
use EcSugar\PluginFaq\Models\FaqItem;

/**
 * ショップの商品の画面の「よくある質問」。公開している質問のうち、全部の商品のものとこの商品のものを並びの順で。
 * 無ければ出さない。描くのは核の部品（<details> で開閉。文はエスケープ）
 */
final class FaqProductPanel implements ProductPanelProvider
{
    public function forListing(Listing $listing): ?ProductPanel
    {
        $items = FaqItem::query()
            ->where('published', true)
            ->where(static fn ($q) => $q->whereNull('listing_id')->orWhere('listing_id', $listing->id))
            ->orderBy('position')
            ->limit(ProductPanel::ITEMS_MAX)
            ->get(['question', 'answer']);

        if ($items->isEmpty()) {
            return null;
        }

        return new ProductPanel('faq', 'よくある質問', $items->map(static fn (FaqItem $item): ProductPanelItem => new ProductPanelItem($item->question, $item->answer))->values()->all());
    }
}
