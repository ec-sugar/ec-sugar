<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use EcSugar\PluginProductFeed\Models\FeedListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管理画面の商品の画面の「商品フィード」の欄（ADR 0050 の口）: フィードに出さない・ブランド・Google の商品カテゴリ・状態・品番を MPN として出す。
 * 上の案内に、この商品がフィードに何行出るか（出ないなら理由）を出す。
 * 書くときは連鎖のロック → 行のロック → イベント（product_feed.listing_updated。中身は商品の ID と変えた欄の名前だけで、値は入れない）
 */
final class FeedListingPanel implements ListingPanelHandler
{
    /**
     * @return list<SettingSpec>
     */
    public function fields(): array
    {
        return [
            new SettingSpec(key: 'excluded', type: SettingType::Toggle, label: 'フィードに出さない', help: 'オンにすると、この商品は Google と Meta のフィードに載りません（ショップには表示されます）。'),
            new SettingSpec(key: 'brand', type: SettingType::Text, label: 'ブランド', help: '空なら App の設定の「ブランドの既定」（それも空なら店の名前）を使います。70 文字まで。',
                pattern: '[^\p{C}]{1,70}', max: 70),
            new SettingSpec(key: 'google_product_category', type: SettingType::Text, label: 'Google の商品カテゴリ（番号）',
                help: 'Google の商品カテゴリの番号です（例: 166）。空なら App の設定の「Google の商品カテゴリの既定」を使います。', pattern: '[0-9]{1,6}', max: 6),
            new SettingSpec(key: 'condition', type: SettingType::Choice, label: '商品の状態', help: '中古品・再生品は必ず選んでください（Google と Meta が求めます）。',
                required: true, options: FeedListing::CONDITIONS, default: 'new'),
            new SettingSpec(key: 'mpn_from_sku', type: SettingType::Toggle, label: '品番をメーカーの型番（MPN）として出す',
                help: 'メーカーが付けた型番を品番に入れている時だけオンにします。お店で付けた管理用の番号ならオンにしません（実際と違う型番を送ると、Google で商品が不承認になります）。'),
        ];
    }

    public function note(Listing $listing): string
    {
        $state = app(FeedBuilder::class)->describe($listing->id);

        return $state['reason'] ?? ($state['rows'] === 1 ? 'この商品はフィードに 1 行載ります。' : "この商品はフィードに、種類ごとに {$state['rows']} 行載ります。");
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function read(Listing $listing): array
    {
        $row = FeedListing::query()->where('listing_id', $listing->id)->first();

        return [
            'excluded' => $row->excluded ?? false,
            'brand' => $row?->brand,
            'google_product_category' => $row?->google_product_category,
            'condition' => $row->condition ?? 'new',
            'mpn_from_sku' => $row->mpn_from_sku ?? false,
        ];
    }

    /**
     * @param  array<string, mixed>  $values  核が形を確かめ、全部の欄をそろえた値
     */
    public function write(Listing $listing, array $values, string $actor): void
    {
        $condition = is_string($values['condition'] ?? null) && isset(FeedListing::CONDITIONS[$values['condition']]) ? $values['condition'] : null;

        if ($condition === null) {
            throw ValidationException::withMessages(['condition' => '商品の状態は選択肢から選んでください。']);
        }

        $brand = is_string($values['brand'] ?? null) ? trim($values['brand']) : '';
        $next = [
            'excluded' => ($values['excluded'] ?? false) === true,
            'brand' => $brand === '' ? null : $brand,
            'google_product_category' => is_string($values['google_product_category'] ?? null) ? $values['google_product_category'] : null,
            'condition' => $condition,
            'mpn_from_sku' => ($values['mpn_from_sku'] ?? false) === true,
        ];

        DB::transaction(function () use ($listing, $next, $actor): void {
            $events = app(EventStore::class);
            $events->lock();
            $row = FeedListing::query()->where('listing_id', $listing->id)->lockForUpdate()->first();
            $before = $row === null ? ['excluded' => false, 'brand' => null, 'google_product_category' => null, 'condition' => 'new', 'mpn_from_sku' => false]
                : ['excluded' => $row->excluded, 'brand' => $row->brand, 'google_product_category' => $row->google_product_category, 'condition' => $row->condition, 'mpn_from_sku' => $row->mpn_from_sku];
            $changed = array_keys(array_filter($next, static fn (mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                return;
            }

            $row ??= new FeedListing(['listing_id' => $listing->id]);
            $row->fill($next)->save();
            $events->append('product_feed.listing_updated', 'product_feed_listing', $row->id, ['listing_id' => $listing->id, 'changed' => $changed, 'excluded' => $next['excluded']], $actor);
        });
    }
}
