<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Inventory\Models\Location;
use App\Core\Listing\Models\ListingAddon;

/**
 * 書き出しと取り込みで同じに読む商品の値（オプションの形・在庫の拠点）。読むだけ
 */
final class ProductCsvCatalog
{
    /**
     * 商品のオプションを、書き出しと AddonService の入力と同じ形で
     *
     * @return list<array<string, mixed>>
     */
    public static function addonsOf(string $listingId): array
    {
        return ListingAddon::query()->where('listing_id', $listingId)->orderBy('position')->get()
            ->map(static fn (ListingAddon $addon): array => self::addonShape($addon))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function addonShape(ListingAddon $addon): array
    {
        $shape = ['kind' => $addon->kind->value, 'name' => $addon->name, 'required' => $addon->required];

        return $shape + match ($addon->kind->value) {
            'choice' => ['choices' => $addon->choices],
            'text' => ['amount' => $addon->amount, 'max_length' => $addon->max_length],
            default => ['amount' => $addon->amount],
        };
    }

    /**
     * 出品者の在庫の拠点（最初に作ったもの）。書き出しと取り込みで同じ拠点を使う
     */
    public static function locationIdFor(string $sellerId): ?string
    {
        $id = Location::query()->where('seller_id', $sellerId)->orderBy('created_at')->orderBy('id')->value('id');

        return is_string($id) ? $id : null;
    }
}
