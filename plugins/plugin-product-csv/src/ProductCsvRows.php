<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Catalog\Services\CategoryService;
use App\Core\Csv\CsvCells;
use App\Core\Listing\Description\ProductDescription;
use App\Core\Listing\Models\ListingAddon;

/**
 * CSV の 1 行の値を確かめて、決まった形にする（DB は見ない。商品との照らし合わせは ProductCsvImporter）。
 *
 * 空の欄の意味（docs/design/product-csv.md）:
 * - 列が無い: その項目は変えない
 * - 列があって空: 品番・種類の価格・カテゴリ・短い説明・説明・オプションは「無し」にする。商品名・価格・状態・種類の販売・在庫・写真は「変えない」
 *   （商品名と価格は空にできない。在庫を空で 0 にしてしまわないように。写真は足すだけで消さない）
 */
final class ProductCsvRows
{
    /** 価格の上限（管理画面の商品の欄と同じ 1 兆円） */
    public const int MAX_AMOUNT = 1_000_000_000_000;

    /** 在庫の数の上限 */
    public const int MAX_STOCK = 1_000_000;

    /** 種類の値・選ぶ項目の名前の長さ（核の種類と同じ） */
    private const int MAX_LABEL = 40;

    /**
     * @param  array{line: int, values: array<string, string>, complete: bool}  $row
     * @return array{line: int, values: array<string, mixed>, errors: list<array{column: string, message: string}>}
     *                                                                                                              values は列があったものだけ（鍵 => 形をそろえた値）。options は [[名前, 値], …]（列が無ければ鍵が無い）
     */
    public static function parse(array $row, ProductCsvFormat $format): array
    {
        $in = $row['values'];
        $out = [];
        $errors = [];
        $fail = static function (string $column, string $message) use (&$errors): void {
            $errors[] = ['column' => $column, 'message' => $message];
        };

        if (! $row['complete']) {
            $fail('', '列の数が 1 行目（見出し）と合いません。値の中にカンマや改行があるときは、その値を「"」で囲んでください。');
        }

        foreach ($in as $key => $value) {
            if (mb_strlen($value) > ProductCsvReader::MAX_CELL) {
                $fail($key, '長すぎます。');
                unset($in[$key]);
            }
        }

        if (array_key_exists('listing_id', $in)) {
            $id = strtolower($in['listing_id']);
            $out['listing_id'] = $id === '' ? null : $id;

            if ($id !== '' && preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) !== 1) {
                $fail('listing_id', '商品ID が正しくありません。書き出した CSV の値をそのまま使ってください（新しい商品は空にします）。');
            }
        }

        if (array_key_exists('sku', $in)) {
            $sku = $in['sku'];
            $out['sku'] = $sku === '' ? null : $sku;

            if ($sku !== '' && (strlen($sku) > 64 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $sku) !== 1)) {
                $fail('sku', '品番は、半角の英数字と「.」「_」「-」で 64 文字まで、英数字で始めてください。');
            }
        }

        if (array_key_exists('title', $in)) {
            $title = $in['title'];
            $out['title'] = $title === '' ? null : $title;

            if (mb_strlen($title) > 200 || preg_match('/[\p{Cc}\p{Cf}]/u', $title) === 1) {
                $fail('title', '商品名は改行や見えない文字を入れずに 200 文字までです。');
            }
        }

        if (array_key_exists('status', $in)) {
            $out['status'] = self::named($in['status'], $format->statuses, 'status', '状態は「'.implode('」「', array_slice(array_keys($format->statuses), 0, 3)).'」のどれかです。', $fail);
        }

        foreach (['unit_amount' => '価格', 'variant_amount' => '種類の価格'] as $key => $label) {
            if (array_key_exists($key, $in)) {
                $out[$key] = self::integer($in[$key], self::MAX_AMOUNT, $key, "{$label}は 0 以上の整数（円）です。", $fail);
            }
        }

        if (array_key_exists('stock', $in)) {
            $out['stock'] = self::integer($in['stock'], self::MAX_STOCK, 'stock', '在庫は 0 から '.number_format(self::MAX_STOCK).' までの整数です。', $fail);
        }

        // 税率（ADR 0063）。全角の「％」「８」「１０」も読む。空なら変えない（新しい商品は標準）
        if (array_key_exists('tax_class', $in)) {
            $out['tax_class'] = self::named(mb_convert_kana(str_replace('％', '%', $in['tax_class']), 'n'), $format->taxClasses, 'tax_class', '税率は「標準」「軽減」（10%・8%）のどちらかです。', $fail);
        }

        if (array_key_exists('variant_status', $in)) {
            $out['variant_status'] = self::named($in['variant_status'], $format->variantStatuses, 'variant_status', '種類の販売は「販売中」「停止中」のどちらかです。', $fail);
        }

        if (array_key_exists('option1_name', $in) || array_key_exists('option1_value', $in)) {
            $out['options'] = self::options($in, $fail);
        }

        if (array_key_exists('categories', $in)) {
            $out['categories'] = self::categories($in['categories'], $fail);
        }

        if (array_key_exists('summary', $in)) {
            $out['summary'] = $in['summary'] === '' ? null : $in['summary'];

            if (mb_strlen($in['summary']) > ProductDescription::MAX_SUMMARY) {
                $fail('summary', '短い説明は '.ProductDescription::MAX_SUMMARY.' 文字までです。');
            }
        }

        if (array_key_exists('description', $in)) {
            // 改行は \n にそろえる（表計算ソフトは CRLF で書く）。ほかの中身は核の説明の決まり（ProductDescription）が見る
            $description = str_replace(["\r\n", "\r"], "\n", $in['description']);
            $out['description'] = $description === '' ? null : $description;
        }

        if (array_key_exists('addons', $in)) {
            $out['addons'] = self::addons($in['addons'], $fail);
        }

        // 写真: 写真の列が 1 つでもあれば、空でない欄を列の順に。駄目な値も行の誤りにはせず、理由を付けて写真だけを飛ばす（ProductCsvPhotos）
        $photoKeys = array_values(array_filter(ProductCsvPhotos::keys(), static fn (string $key): bool => array_key_exists($key, $in)));

        if ($photoKeys !== []) {
            $out['photos'] = [];

            foreach ($photoKeys as $key) {
                if ($in[$key] !== '') {
                    $out['photos'][] = ['column' => $key] + ProductCsvPhotos::parse($in[$key]);
                }
            }
        }

        return ['line' => $row['line'], 'values' => $out, 'errors' => $errors];
    }

    /**
     * @param  array<string, string>  $names
     * @param  callable(string, string): void  $fail
     */
    private static function named(string $value, array $names, string $column, string $message, callable $fail): ?string
    {
        if ($value === '') {
            return null;
        }

        $found = $names[$value] ?? $names[strtolower($value)] ?? null;

        if ($found === null) {
            $fail($column, $message);
        }

        return $found;
    }

    /**
     * 整数（全角の数字と 3 桁ごとのカンマは読む）。空は null
     *
     * @param  callable(string, string): void  $fail
     */
    private static function integer(string $value, int $max, string $column, string $message, callable $fail): ?int
    {
        if ($value === '') {
            return null;
        }

        $digits = str_replace(',', '', mb_convert_kana($value, 'n'));

        if (preg_match('/\A[0-9]{1,13}\z/', $digits) !== 1 || (int) $digits > $max) {
            $fail($column, $message);

            return null;
        }

        return (int) $digits;
    }

    /**
     * 選ぶ項目と値（1 から順に。名前と値は組で、間を空けない）
     *
     * @param  array<string, string>  $in
     * @param  callable(string, string): void  $fail
     * @return list<array{0: string, 1: string}>
     */
    private static function options(array $in, callable $fail): array
    {
        $options = [];
        $ended = false;

        foreach ([1, 2, 3] as $n) {
            $name = $in["option{$n}_name"] ?? '';
            $value = $in["option{$n}_value"] ?? '';

            if ($name === '' && $value === '') {
                $ended = true;

                continue;
            }

            if ($name === '' || $value === '' || $ended) {
                $fail("option{$n}_name", "選ぶ項目{$n}は、名前と値を両方入れてください（選ぶ項目1 から順に使います）。");

                continue;
            }

            foreach ([[$name, "option{$n}_name"], [$value, "option{$n}_value"]] as [$label, $column]) {
                if (mb_strlen($label) > self::MAX_LABEL || preg_match('/[\p{Cc}\p{Cf}]/u', $label) === 1) {
                    $fail($column, '選ぶ項目の名前と値は、見えない文字を入れずに '.self::MAX_LABEL.' 文字までです。');
                }
            }

            $options[] = [$name, $value];
        }

        if (count(array_unique(array_column($options, 0))) !== count($options)) {
            $fail('option1_name', '選ぶ項目の名前が重複しています。');
        }

        return $options;
    }

    /**
     * カテゴリの URL の名前（slug）をカンマで区切ったもの。最初のものが主カテゴリ
     *
     * @param  callable(string, string): void  $fail
     * @return list<string>
     */
    private static function categories(string $value, callable $fail): array
    {
        if ($value === '') {
            return [];
        }

        $slugs = array_values(array_filter(array_map('trim', preg_split('/[,、，]/u', $value) ?: []), static fn (string $s): bool => $s !== ''));

        foreach ($slugs as $slug) {
            if (strlen($slug) > 60 || preg_match('/\A[a-z0-9]+(-[a-z0-9]+)*\z/', $slug) !== 1) {
                $fail('categories', 'カテゴリは、URL に使う名前（半角の英小文字・数字・「-」）をカンマで区切って入れてください: '.CsvCells::preview($slug));

                return [];
            }
        }

        if (count($slugs) > CategoryService::MAX_PER_LISTING || count(array_unique($slugs)) !== count($slugs)) {
            $fail('categories', 'カテゴリは、重複なしで '.CategoryService::MAX_PER_LISTING.' 個まで入れられます。');

            return [];
        }

        return $slugs;
    }

    /**
     * オプション（JSON の並び）。中身は核の AddonService が確かめる
     *
     * @param  callable(string, string): void  $fail
     * @return list<mixed>
     */
    private static function addons(string $value, callable $fail): array
    {
        if ($value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 5, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (! is_array($decoded) || ! array_is_list($decoded) || count($decoded) > ListingAddon::MAX_PER_LISTING) {
            $fail('addons', 'オプションの書き方が正しくありません。書き出した CSV と同じ書き方（JSON の配列）で、'.ListingAddon::MAX_PER_LISTING.' 個までにしてください。');

            return [];
        }

        return $decoded;
    }
}
