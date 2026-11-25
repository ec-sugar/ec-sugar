<?php

declare(strict_types=1);

namespace EcSugar\PluginCatalogSort;

use App\Core\Money\Money;
use App\Core\Plugin\Catalog\CatalogBand;
use App\Core\Plugin\Catalog\CatalogTag;
use App\Core\Plugin\CatalogBrowse;
use App\Core\Plugin\Contracts\CatalogBrowseHandler;
use EcSugar\PluginCatalogSort\Models\CatalogSortItem;
use Illuminate\Support\Facades\DB;

/**
 * ショップの一覧の並びと絞り込みの口（核の CatalogBrowseHandler）。使う店の文脈の中だけで呼ばれる。
 *
 * - order(): 店が手で決めた並び（catalog_sort_items の position の順）
 * - bands(): 設定の境目から作る値段の帯（「¥1,000 未満」「¥1,000〜¥2,999」「¥5,000 以上」）
 * - tags(): 店の人が商品に付けたタグ（付けた商品の多い順）。URL に出る key はタグの名前から作る短い印（日本語をそのまま URL に出さない）
 * - taggedListings(): そのタグを付けた商品。タグの名前は値として渡す（SQL に埋め込まない）
 */
final class CatalogSortBrowse implements CatalogBrowseHandler
{
    public function order(): array
    {
        /** @var list<string> */
        return CatalogSortItem::query()
            ->orderBy('position')
            ->limit(CatalogBrowse::MAX_ORDER)
            ->pluck('listing_id')
            ->all();
    }

    public function firstSortIsShopOrder(): bool
    {
        return self::settings()->shopOrderFirst();
    }

    public function bands(): array
    {
        if (! self::settings()->showsPrice()) {
            return [];
        }

        $edges = self::settings()->bandEdges();
        $bands = [];
        $min = 0;

        foreach ($edges as $i => $edge) {
            $bands[] = new CatalogBand(
                key: 'b'.$i,
                label: $i === 0 ? Money::format($edge).' 未満' : Money::format($min).'〜'.Money::format($edge - 1),
                min: $min,
                max: $edge - 1,
            );
            $min = $edge;
        }

        $bands[] = new CatalogBand(key: 'b'.count($edges), label: Money::format($min).' 以上', min: $min, max: null);

        return $bands;
    }

    public function tags(): array
    {
        if (! self::settings()->showsTags()) {
            return [];
        }

        // 店の人が付けたタグを、付けた商品の多い順・同じ数なら名前の順に（核が数を MAX_OPTIONS で切る）
        $rows = DB::query()
            ->fromSub(DB::table('catalog_sort_items')->selectRaw('jsonb_array_elements_text(tags) AS tag'), 'tags')
            ->selectRaw('tag, count(*) AS items')
            ->groupBy('tag')
            ->orderByDesc('items')
            ->orderBy('tag')
            ->limit(CatalogBrowse::MAX_OPTIONS)
            ->get();

        return $rows->map(static fn (object $row): CatalogTag => new CatalogTag(self::key((string) $row->tag), (string) $row->tag))->all();
    }

    public function showsInStock(): bool
    {
        return self::settings()->showsInStock();
    }

    public function taggedListings(string $tag): array
    {
        $name = null;

        foreach ($this->tags() as $known) {
            if ($known->key === $tag) {
                $name = $known->label;
            }
        }

        if ($name === null) {
            return [];
        }

        /** @var list<string> */
        return CatalogSortItem::query()
            ->whereRaw('tags @> ?::jsonb', [(string) json_encode([$name], JSON_UNESCAPED_UNICODE)])
            ->orderBy('position')
            ->limit(CatalogBrowse::MAX_TAGGED)
            ->pluck('listing_id')
            ->all();
    }

    /**
     * 設定を読む係（店の文脈の中で呼ばれた時に作る。登録の時には作らない）
     */
    private static function settings(): CatalogSortSettings
    {
        return app(CatalogSortSettings::class);
    }

    /**
     * タグの名前から作る、URL に出る短い印（核の key の形〔英小文字と数字〕に収める。同じ名前はいつも同じ印）
     */
    public static function key(string $tag): string
    {
        return 't'.substr(hash('sha256', $tag), 0, 10);
    }
}
