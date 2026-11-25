<?php

declare(strict_types=1);

namespace EcSugar\PluginServices;

use App\Core\Listing\Enums\OfferSide;
use App\Core\Listing\Enums\Pricing;
use App\Core\Listing\ListingKindDefinition;
use App\Core\Listing\ListingKinds;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「サービスの販売」。商品の扱い「サービス」を足し、設置の代行・作業の請け負いなど物を送らない役務を、
 * ふだんのカートと注文の流れで売る（オプションはふだんの商品と同じ。送料・お届け先・在庫を使わず、1 回の注文で 1 点まで）。
 * 受渡は役務（in_person）で、作業を終えたら店の人が管理画面の注文の「提供済みにする」で完了まで進める（核の SaleOrderService::deliverService）。
 * ほかの扱いの商品と同じ注文には入れない（カートが役務の行を止め、注文の確定でも断る）。App を止めた店では新しく売らない（核が種類を売らない）
 */
final class ServicesServiceProvider extends ServiceProvider
{
    public const string KIND = 'service_item';

    public function boot(PluginRegistry $plugins): void
    {
        $this->callAfterResolving(ListingKinds::class, static function (ListingKinds $kinds): void {
            if (! $kinds->has(self::KIND)) {
                $kinds->register(new ListingKindDefinition(self::KIND, OfferSide::Sell, Pricing::Fixed, 'サービス', plugin: 'services', stockless: true, inPerson: true));
            }
        });

        $plugins->register(new PluginDefinition(
            name: 'services',
            title: 'サービスの販売',
            description: '設置の代行や作業の請け負いなど、物を送らないサービスを、オプションつきでふだんのカートから売ります。作業を終えたら、注文の画面の「提供済みにする」で完了にします。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
        ));
    }
}
