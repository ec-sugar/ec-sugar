<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Csv\CsvCells;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingOption;
use App\Core\Listing\Models\ListingVariant;
use Illuminate\Support\Facades\DB;

/**
 * 商品を CSV に書き出す（1 行 1 種類。EC-SUGAR の形）。店の文脈の中で呼ぶ。
 *
 * - 書き出すのは、消していない「商品」（kind = product）だけ。商品の欄（商品名・価格・説明など）は、同じ商品のどの行にも同じ値を書く
 * - 在庫は、その商品の出品者の最初の拠点の数（取り込みも同じ拠点へ「この数にする」）
 * - 説明は文の写し（ブロックの文をつないだもの）。見出し・写真などのブロックの形は書かない（取り込みで同じ文なら変えない）
 * - 写真は、ショップの写真の公開の URL（{ショップ}/media/listings/{写真の ID}/large）を並び順に写真1〜写真10へ。取り込みはこの形の URL を
 *   この店の写真として見分け、同じ商品の写真なら取りに行かずにそのまま残す（往復で写真を二重に作らない）。ショップの URL が無い店（ドメインが無い）は空
 * - 文字の欄は差し込みよけ（CsvCells::neutralize）を通す。UTF-8 の BOM つき・改行は CRLF（表計算ソフトでそのまま開けるように）
 */
final class ProductCsvExporter
{
    /**
     * @param  string|null  $shopBase  ショップの URL の頭（https://{ショップのドメイン}。写真の URL に使う。無ければ写真の列は空）
     */
    public function export(?string $shopBase = null): string
    {
        $format = ProductCsvFormat::ecSugar();
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new \RuntimeException('一時保存先を開けません');
        }

        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_map($format->headerOf(...), ProductCsvFormat::KEYS), escape: '', eol: "\r\n");

            // ID（ULID）の順は作った順
            foreach (Listing::query()->where('kind', 'product')->lazyById(200) as $listing) {
                foreach ($this->rowsOf($listing, $format, $shopBase) as $row) {
                    fputcsv($stream, $row, escape: '', eol: "\r\n");
                }
            }

            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return list<list<string>>
     */
    private function rowsOf(Listing $listing, ProductCsvFormat $format, ?string $shopBase): array
    {
        $options = ListingOption::query()->where('listing_id', $listing->id)->orderBy('position')->with('values')->get();
        $labels = [];

        foreach ($options as $option) {
            foreach ($option->values as $value) {
                $labels[$value->id] = $value->label;
            }
        }

        $locationId = ProductCsvCatalog::locationIdFor($listing->seller_id);
        $quantities = $locationId === null ? collect() : InventoryStock::query()->where('listing_id', $listing->id)->where('location_id', $locationId)->pluck('quantity', 'variant_id');
        $categories = DB::table('listing_categories')->join('categories', 'categories.id', '=', 'listing_categories.category_id')
            ->where('listing_categories.listing_id', $listing->id)->orderByDesc('listing_categories.is_primary')->orderBy('categories.slug')
            ->pluck('categories.slug')->all();
        $addons = ProductCsvCatalog::addonsOf($listing->id);

        $product = [
            'listing_id' => $listing->id,
            'title' => CsvCells::neutralize($listing->title),
            'status' => ProductCsvFormat::nameOf($format->statuses, $listing->status->value),
            'unit_amount' => $listing->unit_amount === null ? '' : (string) $listing->unit_amount,
            'categories' => implode(',', $categories),
            'summary' => CsvCells::neutralize($listing->summary),
            'description' => CsvCells::neutralize($listing->description),
            // オプションは JSON（「[」で始まるので式にならない）。無ければ空
            'addons' => $addons === [] ? '' : json_encode($addons, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'tax_class' => ProductCsvFormat::nameOf($format->taxClasses, $listing->tax_class->value),
        ];
        $photos = $shopBase === null ? [] : ListingImage::query()->where('listing_id', $listing->id)->orderBy('position')->orderBy('id')
            ->limit(ProductCsvPhotos::COLUMNS)->pluck('id')->all();

        foreach (ProductCsvPhotos::keys() as $index => $key) {
            $product[$key] = isset($photos[$index]) ? "{$shopBase}/media/listings/{$photos[$index]}/large" : '';
        }

        $rows = [];

        foreach (ListingVariant::query()->where('listing_id', $listing->id)->orderBy('position')->orderBy('id')->get() as $variant) {
            $row = $product + [
                'sku' => (string) $variant->sku,
                'variant_amount' => $variant->unit_amount === null ? '' : (string) $variant->unit_amount,
                'stock' => (string) (int) ($quantities[$variant->id] ?? 0),
                'variant_status' => ProductCsvFormat::nameOf($format->variantStatuses, $variant->status->value),
            ];

            foreach ([1, 2, 3] as $n) {
                $option = $options[$n - 1] ?? null;
                $valueId = $variant->getAttribute("value_{$n}");
                $row["option{$n}_name"] = $option === null ? '' : CsvCells::neutralize($option->name);
                $row["option{$n}_value"] = is_string($valueId) ? CsvCells::neutralize($labels[$valueId] ?? '') : '';
            }

            $rows[] = array_map(static fn (string $key): string => $row[$key], ProductCsvFormat::KEYS);
        }

        return $rows;
    }

    /**
     * 書き出しのファイルの名前（店の ID と日付だけ。日本語を入れない）
     */
    public static function filename(string $tenantId): string
    {
        return 'products-'.preg_replace('/[^a-z0-9_-]/', '', $tenantId).'-'.now()->format('Ymd-His').'.csv';
    }
}
