<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\ProductPanel;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ショップの商品の画面の説明の下に置く、App の開いて読む並び（よくある質問など）。
 * この店で使っている App のうち、口（ProductPanelProvider）を持つものだけに聞く。App が落ちても商品の画面は描く（その App の並びを出さないだけ）
 */
final class ProductPanels
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * @return list<ProductPanel>
     */
    public function forListing(string $listingId): array
    {
        $listing = null;
        $panels = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->productPanel === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            $listing ??= Listing::query()->find($listingId);

            if ($listing === null) {
                return [];
            }

            try {
                $panel = $plugin->productPanel->forListing($listing);
            } catch (Throwable $e) {
                Log::error('App の商品の画面の並びを出せなかった', ['plugin' => $plugin->name, 'error' => $e::class]);

                continue;
            }

            // ほかの App の名前を名乗る並びは出さない
            if ($panel !== null && $panel->plugin === $plugin->name) {
                $panels[] = $panel;
            }
        }

        return $panels;
    }
}
