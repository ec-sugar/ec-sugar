<?php

declare(strict_types=1);

namespace App\Core\Shop\Shipping;

use App\Core\Listing\Models\Listing;
use App\Core\Shop\Models\ShippingMethod;
use App\Core\Shop\ShippingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 送料を決める核の 1 か所（ADR 0064）。カート・注文の確定・1 つの商品の注文（定期便の 1 回目）・定期便の約束がここを通る。
 *
 * - 品を商品の配送の方法ごとの便に分ける（方法を選んでいない・消した方法の商品は、いちばん上の方法）
 * - 便に送料無料の商品が入っていれば、その便は無料。そうでなければ、便の品の小計（値引きの後）が方法の「この金額以上で無料」以上なら無料
 * - それ以外はお届け先の都道府県の額。贈り物はいちばん高い額、目安はいちばん安い額〜いちばん高い額
 * - 送料はいつもここで決める（カートやフォームから送られた額・方法を信じない）
 */
final class ShippingCalculator
{
    /**
     * 送料の表（方法と都道府県の額）と、品の商品の配送の方法を 1 度に読む（ADR 0065: カートで割引ごとに送料を見積もり直すとき、読むのは 1 度だけ）。
     * quote() に渡すと、同じ商品の品ならクエリを使わずに額だけを計算する
     *
     * @param  list<string>  $listingIds
     * @return array{0: array<string, ShippingMethod>, 1: array<string, array<string, int>>, 2: array<string, Listing>}
     */
    public function load(array $listingIds): array
    {
        [$methods, $rates] = $this->table();
        $listings = $listingIds === [] ? [] : Listing::query()->whereIn('id', array_values(array_unique($listingIds)))->get(['id', 'shipping_method_id', 'free_shipping'])->keyBy('id')->all();

        return [$methods, $rates, $listings];
    }

    /**
     * @param  list<array{key: string, listingId: string, amount: int}>  $items  今買える品（amount は値引きの後の額）
     * @param  array{0: array<string, ShippingMethod>, 1: array<string, array<string, int>>, 2: array<string, Listing>}|null  $loaded  load() で読んだもの（品の商品をすべて含むときだけ使う）
     */
    public function quote(array $items, ShippingDestination $to, ?array $loaded = null): ShippingQuote
    {
        if ($items === []) {
            return ShippingQuote::none();
        }

        $ids = array_values(array_unique(array_column($items, 'listingId')));

        if ($loaded === null || array_diff($ids, array_keys($loaded[2])) !== []) {
            $loaded = $this->load($ids);
        }

        [$methods, $rates, $listings] = $loaded;
        $default = array_key_first($methods);
        $buckets = [];

        foreach ($items as $item) {
            $listing = $listings[$item['listingId']] ?? null;
            $chosen = $listing?->shipping_method_id;
            $methodId = is_string($chosen) && isset($methods[$chosen]) ? $chosen : $default;
            $bucket = $buckets[$methodId ?? '-'] ?? ['keys' => [], 'amount' => 0, 'free' => false];
            $bucket['keys'][] = $item['key'];
            $bucket['amount'] += $item['amount'];
            $bucket['free'] = $bucket['free'] || ($listing !== null && (bool) $listing->free_shipping);
            $buckets[$methodId ?? '-'] = $bucket;
        }

        $groups = [];

        foreach ($buckets as $methodId => $bucket) {
            $method = $methods[$methodId] ?? null;
            $groups[] = $this->group($method, $method === null ? [] : ($rates[$method->id] ?? []), $bucket['keys'], $bucket['amount'], $bucket['free'], $to);
        }

        // 便の並びは方法の並び（表示と指紋を同じに保つ）
        $order = array_flip(array_keys($methods));
        usort($groups, static fn (ShippingGroup $a, ShippingGroup $b): int => ($order[$a->methodId] ?? PHP_INT_MAX) <=> ($order[$b->methodId] ?? PHP_INT_MAX));

        return new ShippingQuote($groups, count($methods) > 1, $to->isEstimate());
    }

    /**
     * 定期便の約束の送料（ADR 0064 の 7）: その商品の方法と、お届け先の都道府県で 1 つの便として決めた額と無料の線
     */
    public function policyFor(string $listingId, string $prefecture): ShippingPolicy
    {
        [$methods, $rates] = $this->table();
        $listing = Listing::query()->find($listingId, ['id', 'shipping_method_id', 'free_shipping']);

        $chosen = $listing?->shipping_method_id;
        $method = is_string($chosen) && isset($methods[$chosen]) ? $methods[$chosen] : ($methods[array_key_first($methods)] ?? null);

        if ($method === null || ($listing !== null && (bool) $listing->free_shipping)) {
            return new ShippingPolicy(0, null, $method?->name);
        }

        $table = $rates[$method->id] ?? [];

        return new ShippingPolicy($table[$prefecture] ?? self::highestOf($method, $table), $method->free_over, $method->name);
    }

    /**
     * @param  array<string, int>  $rates
     * @param  list<string>  $keys
     */
    private function group(?ShippingMethod $method, array $rates, array $keys, int $amount, bool $freeItem, ShippingDestination $to): ShippingGroup
    {
        $name = $method->name ?? '通常便';
        $freeOver = $method?->free_over;

        if ($freeItem) {
            return new ShippingGroup($method?->id, $name, $keys, $amount, 0, 0, 'free_item', null, $rates);
        }

        if ($method !== null && $freeOver !== null && $amount >= $freeOver) {
            return new ShippingGroup($method->id, $name, $keys, $amount, 0, 0, 'free_over', null, $rates);
        }

        $highest = $method === null ? 0 : self::highestOf($method, $rates);
        [$fee, $max, $basis] = match (true) {
            $to->prefecture !== null => [$rates[$to->prefecture] ?? $highest, $rates[$to->prefecture] ?? $highest, 'rate'],
            $to->highest => [$highest, $highest, 'highest'],
            default => [$rates === [] ? 0 : min($rates), $highest, 'rate'],
        };

        if ($to->prefecture !== null && $method !== null && ! isset($rates[$to->prefecture])) {
            // 表が欠けている（保存は 47 県をそろえるので起きないはず）: 少なく取らないよう、いちばん高い額で
            Log::error('送料の表に都道府県が無い', ['method' => $method->id]);
        }

        $remaining = $freeOver !== null && $max > 0 ? $freeOver - $amount : null;

        return new ShippingGroup($method?->id, $name, $keys, $amount, $fee, $max, $basis, $remaining, $rates);
    }

    /**
     * @param  array<string, int>  $rates
     */
    private static function highestOf(ShippingMethod $method, array $rates): int
    {
        return $rates === [] ? 0 : max($rates);
    }

    /**
     * 方法（並び順）と、方法ごとの都道府県の額
     *
     * @return array{0: array<string, ShippingMethod>, 1: array<string, array<string, int>>}
     */
    private function table(): array
    {
        $methods = ShippingMethod::query()->orderBy('position')->orderBy('id')->get()->keyBy('id')->all();
        $rates = [];

        foreach (DB::table('shipping_rates')->get(['method_id', 'prefecture', 'fee']) as $row) {
            $rates[(string) $row->method_id][(string) $row->prefecture] = (int) $row->fee;
        }

        return [$methods, $rates];
    }
}
