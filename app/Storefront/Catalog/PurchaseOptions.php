<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PurchaseOption;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Plugin\Services\SaleHolds;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ショップの商品の画面に置く、App の買い方（ADR 0050）。この店で使っている App のうち、買い方の口を持つものだけに聞く。
 * App が落ちても商品の画面は描く（その App の買い方を出さないだけ）。
 * App が売り方を押さえた商品（抽選販売など。ADR 0080）は、押さえた App の買い方と、押す・戻すのボタン（お気に入りなど）だけを出す
 */
final class PurchaseOptions
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
        private readonly SaleHolds $holds,
    ) {}

    /**
     * @return list<PurchaseOption>
     */
    public function forListing(string $listingId): array
    {
        $listing = null;
        $options = [];
        $hold = null;

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->purchaseOptions === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            $listing ??= Listing::query()->find($listingId);

            if ($listing === null) {
                return [];
            }

            $hold ??= ['held' => $this->holds->for($listing->id)];

            try {
                $option = $plugin->purchaseOptions->forListing($listing);
            } catch (Throwable $e) {
                Log::error('App の買い方を出せなかった', ['plugin' => $plugin->name, 'error' => $e::class]);

                continue;
            }

            if ($option !== null && $option->plugin === $plugin->name && ($hold['held'] === null || $hold['held']['plugin'] === $plugin->name || $option->isToggle())) {
                $options[] = $option;
            }
        }

        return $options;
    }
}
