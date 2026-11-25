<?php

declare(strict_types=1);

namespace EcSugar\PluginWrapping;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use EcSugar\PluginWrapping\Models\WrappingListing;

/**
 * 管理画面の商品の画面の「ラッピング・のし」の欄（ADR 0050 の口）: ラッピングできる・のしを付けられる。
 * 書くのは WrappingTypes（連鎖のロック → 行のロック → イベント）
 */
final class WrappingListingPanel implements ListingPanelHandler
{
    /**
     * @return list<SettingSpec>
     */
    public function fields(): array
    {
        return [
            new SettingSpec(key: 'wrappable', type: SettingType::Toggle, label: 'ラッピングできる',
                help: 'オンにすると、この商品の入ったご注文で、お客さんが App の画面で決めたラッピングを選べます（ご注文の商品をまとめて 1 つに包みます）。'),
            new SettingSpec(key: 'noshi', type: SettingType::Toggle, label: 'のしを付けられる',
                help: 'オンにすると、この商品の入ったご注文で、お客さんがのしの表書きと掛け方を選べます（App の設定で「のしを受け付ける」をオンにしている時だけ）。'),
        ];
    }

    public function note(Listing $listing): ?string
    {
        return null;
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function read(Listing $listing): array
    {
        $row = WrappingListing::query()->find($listing->id);

        return ['wrappable' => $row->wrappable ?? false, 'noshi' => $row->noshi ?? false];
    }

    /**
     * @param  array<string, mixed>  $values  核が形を確かめ、全部の欄をそろえた値
     */
    public function write(Listing $listing, array $values, string $actor): void
    {
        app(WrappingTypes::class)->saveListing($listing, ($values['wrappable'] ?? false) === true, ($values['noshi'] ?? false) === true, $actor);
    }
}
