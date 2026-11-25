<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use Illuminate\Validation\ValidationException;

/**
 * CSV の行を商品ごとにまとめる（ファイルの順）。店の文脈の中で、連鎖のロックを取った後に呼ぶ（品番の持ち主を読むため）。
 *
 * 商品の決め方: 商品ID があればその商品、無ければ品番が今の種類にあればその商品、それも無ければ前の取り込み（止まった取り込みを入れ直す前）で
 * その商品名で作った商品、どれでもなければ商品名ごとに新しい商品（入れ直しで、品番の無い新しい商品を二重に作らない）。
 * 行の誤り（同じ品番が 2 行・ほかの商品の品番など）はその行に、同じ商品の行どうしの食い違いは商品（group の error）に付ける。
 *
 * @phpstan-type Row array{line: int, values: array<string, mixed>, errors: list<array{column: string, message: string}>}
 * @phpstan-type Group array{listing_id: string|null, rows: non-empty-list<Row>, error: array{line: int, column: string, message: string}|null}
 */
final class ProductCsvGroups
{
    /** 同じ商品の行で、そろっていなければならない商品の欄 */
    private const array PRODUCT_FIELDS = ['title', 'status', 'unit_amount', 'categories', 'summary', 'description', 'addons', 'tax_class', 'photos'];

    /**
     * CSV の文を読み、行を確かめて、商品ごとにまとめる（確かめ・反映・写真の段で同じに読む）
     *
     * @param  array<string, string>  $created  前の取り込み（とこの取り込み）で作った商品（商品名 => ID）
     * @param  int  $fromLine  最初の行がこの行より前の商品を除く（続きの仕事。商品は最初の行の順に並んでいる）
     * @return array{format: ProductCsvFormat, rows: int, groups: list<Group>, has_options: bool}
     *
     * @throws ValidationException ファイルの形が違う（file の欄）
     */
    public static function read(ProductCsvReader $reader, string $csv, array $created, int $fromLine = 0): array
    {
        $read = $reader->read($csv);
        $format = $read['format'];
        $hasOptions = in_array('option1_name', $read['columns'], true) || in_array('option1_value', $read['columns'], true);
        $rows = array_map(static fn (array $row): array => ProductCsvRows::parse($row, $format), $read['rows']);
        $groups = array_values(array_filter(self::of($rows, $hasOptions, self::alive($created)), static fn (array $group): bool => $group['rows'][0]['line'] >= $fromLine));

        return ['format' => $format, 'rows' => count($rows), 'groups' => $groups, 'has_options' => $hasOptions];
    }

    /**
     * @param  list<Row>  $rows
     * @param  array<string, string>  $created  前の取り込みで作った商品（商品名 => ID。今もあるものだけ）
     * @return list<Group>
     */
    public static function of(array $rows, bool $hasOptions, array $created = []): array
    {
        $owners = self::owners($rows);
        $groups = [];
        $seenSkus = [];

        foreach ($rows as $row) {
            $values = $row['values'];
            $sku = $values['sku'] ?? null;
            $id = $values['listing_id'] ?? null;
            $title = $values['title'] ?? null;
            $owner = is_string($sku) ? array_keys($owners[$sku] ?? []) : [];

            if (is_string($sku)) {
                if (isset($seenSkus[$sku])) {
                    $row['errors'][] = ['column' => 'sku', 'message' => "同じ品番が {$seenSkus[$sku]} 行目にもあります。品番は 1 行に 1 つです。"];
                }

                $seenSkus[$sku] ??= $row['line'];
            }

            if (is_string($id)) {
                if ($owner !== [] && ! in_array($id, $owner, true)) {
                    $row['errors'][] = ['column' => 'sku', 'message' => 'この品番は、ほかの商品の種類が使っています。'];
                }
            } elseif (count($owner) === 1) {
                $id = $owner[0];
            } elseif ($owner === [] && is_string($title) && isset($created[$title])) {
                $id = $created[$title];
            } elseif (count($owner) > 1) {
                $row['errors'][] = ['column' => 'sku', 'message' => 'この品番の商品が 2 つ以上あります。「商品ID」の列で商品を決めてください。'];
            } elseif (! is_string($title)) {
                $row['errors'][] = ['column' => '', 'message' => '「商品ID」「品番」「商品名」のどれかで商品を決めてください（新しい商品には商品名が要ります）。'];
            }

            $key = match (true) {
                is_string($id) => "id:{$id}",
                count($owner) === 0 && is_string($title) => "new:{$title}",
                default => 'line:'.$row['line'],
            };

            $groups[$key] ??= ['listing_id' => is_string($id) ? $id : null, 'rows' => [], 'error' => null];
            $groups[$key]['rows'][] = $row;
        }

        return array_values(array_map(static fn (array $group): array => ['error' => self::consistency($group['rows'], $hasOptions)] + $group, $groups));
    }

    /**
     * 前の取り込みで作った商品のうち、今もある商品（消していない「商品」）だけ
     *
     * @param  array<string, string>  $created
     * @return array<string, string>
     */
    private static function alive(array $created): array
    {
        if ($created === []) {
            return [];
        }

        $alive = Listing::query()->whereIn('id', array_values($created))->where('kind', 'product')->pluck('id')->all();

        return array_filter($created, static fn (string $id): bool => in_array($id, $alive, true));
    }

    /**
     * 値の名前の組み合わせの鍵
     *
     * @param  list<string>  $labels
     */
    public static function key(array $labels): string
    {
        return json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * 品番 => [商品の ID => 種類（kind）]。消していない商品の、消していない種類だけ。品番は出品者ごとに一意なので、店の中で 2 つの商品に当たることがある
     *
     * @param  list<Row>  $rows
     * @return array<string, array<string, string>>
     */
    private static function owners(array $rows): array
    {
        $skus = array_values(array_unique(array_filter(array_map(static fn (array $row): mixed => $row['values']['sku'] ?? null, $rows), is_string(...))));
        $owners = [];

        foreach (array_chunk($skus, 500) as $chunk) {
            $found = ListingVariant::query()->join('listings', 'listings.id', '=', 'listing_variants.listing_id')
                ->whereNull('listings.deleted_at')->whereIn('listing_variants.sku', $chunk)
                ->toBase()->get(['listing_variants.sku', 'listing_variants.listing_id', 'listings.kind']);

            foreach ($found as $variant) {
                $owners[(string) $variant->sku][(string) $variant->listing_id] = (string) $variant->kind;
            }
        }

        return $owners;
    }

    /**
     * 同じ商品の行どうしで、商品の欄の値と選ぶ項目の名前がそろっているか、同じ組み合わせが 2 行に無いか
     *
     * @param  non-empty-list<Row>  $rows
     * @return array{line: int, column: string, message: string}|null
     */
    private static function consistency(array $rows, bool $hasOptions): ?array
    {
        $first = $rows[0];
        $combinations = [];

        foreach ($rows as $index => $row) {
            if ($index > 0) {
                foreach (self::PRODUCT_FIELDS as $key) {
                    $value = $row['values'][$key] ?? null;

                    // 後の行は空でよい（最初の行と同じ）。書いたなら最初の行と同じ値に
                    if ($value !== null && $value !== [] && $value !== ($first['values'][$key] ?? null)) {
                        return ['line' => $row['line'], 'column' => $key === 'photos' ? 'photo1' : $key, 'message' => "同じ商品の行どうしで値が食い違っています。{$first['line']} 行目と同じ値にするか、空にしてください。"];
                    }
                }
            }

            if (! $hasOptions) {
                continue;
            }

            /** @var list<array{0: string, 1: string}> $options */
            $options = $row['values']['options'] ?? [];
            /** @var list<array{0: string, 1: string}> $firstOptions */
            $firstOptions = $first['values']['options'] ?? [];

            if (array_column($options, 0) !== array_column($firstOptions, 0)) {
                return ['line' => $row['line'], 'column' => 'option1_name', 'message' => "同じ商品の行どうしで、選ぶ項目の名前が食い違っています。{$first['line']} 行目と同じにしてください。"];
            }

            $combination = self::key(array_column($options, 1));

            if (isset($combinations[$combination])) {
                return ['line' => $row['line'], 'column' => 'option1_value', 'message' => "同じ組み合わせの種類が {$combinations[$combination]} 行目にもあります。"];
            }

            $combinations[$combination] = $row['line'];
        }

        return null;
    }
}
