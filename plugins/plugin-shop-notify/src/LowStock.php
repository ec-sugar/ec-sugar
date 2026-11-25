<?php

declare(strict_types=1);

namespace EcSugar\PluginShopNotify;

use App\Core\Inventory\Models\InventoryStock;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Catalog\Catalog;
use EcSugar\PluginShopNotify\Jobs\SendShopNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 在庫が少なくなったかを見る（キューの仕事 CheckLowStock から、店の文脈の中で）。
 * 見るのはショップでカートに入れられる種類だけ（Catalog::purchasableVariants と同じ決まり）。買える数 = 拠点をまたいだ「在庫 − 確保」。
 * しきい値以下になったら種類に印を付けて 1 回だけ知らせ、しきい値より多く戻ったら印を消す（また減ったらもう 1 回）。
 * 印は 1 文の「無ければ入れる」で付けるので、同時に 2 つ動いても知らせは 1 回
 */
final class LowStock
{
    /** 1 回に見る種類の数の上限（1 つの注文・在庫の操作で動く種類は多くない） */
    private const int MAX = 100;

    public function __construct(
        private readonly PluginStates $states,
        private readonly ShopNotifySettings $settings,
        private readonly Catalog $catalog,
    ) {}

    /**
     * @param  list<string>  $variantIds
     */
    public function check(array $variantIds): void
    {
        if (! $this->states->enabled(ShopNotifySettings::PLUGIN)) {
            return;
        }

        $variantIds = array_slice(array_values(array_unique($variantIds)), 0, self::MAX);
        // 会員だけ・ランクだけの商品（ADR 0079）も店の在庫なので、見る人を問わずに読む
        $purchasable = $this->catalog->forShop()->purchasableVariants($variantIds);
        $threshold = $this->settings->threshold();

        foreach ($variantIds as $variantId) {
            $available = $purchasable[$variantId]['available'] ?? null;

            // 売っていない種類（下書き・止めた・消した）と、しきい値より多い種類と、予約の商品（数は在庫でなく予約の残り。ADR 0073）は印を消す
            if ($available === null || $available > $threshold || ($purchasable[$variantId]['preorder'] ?? null) !== null) {
                DB::table('shop_notify_stock_marks')->where('variant_id', $variantId)->delete();

                continue;
            }

            $mark = strtolower((string) Str::ulid());
            $inserted = DB::table('shop_notify_stock_marks')->insertOrIgnore(['variant_id' => $variantId, 'mark_id' => $mark, 'created_at' => Carbon::now()]);

            if ($inserted === 1 && $this->settings->wants('low_stock')) {
                SendShopNotice::dispatch('low_stock', $variantId, "{$variantId}:{$mark}");
            }
        }
    }

    /**
     * 種類の買える数（拠点をまたいだ在庫 − 確保。0 より小さくしない）
     */
    public static function available(string $variantId): int
    {
        return max(0, (int) InventoryStock::query()->where('variant_id', $variantId)->sum(DB::raw('quantity - reserved')));
    }
}
