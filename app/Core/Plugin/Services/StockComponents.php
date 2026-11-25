<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\Contracts\StockComponentsProvider;
use App\Core\Plugin\PluginRegistry;
use Throwable;

/**
 * この商品の在庫はどの品から引くか（StockComponentsProvider）。この店で使っている App のうち、口を持つものだけに聞く。
 *
 * **落ちたら例外をそのまま上げる**（ほかの口とは逆）。ここで「構成する品が無い」と答えてしまうと、
 * セット商品の在庫を引かずに売ってしまうため。在庫とお金に関わるので、分からない時は取引を止める。
 * ショップの一覧のように落としたくない所は、呼ぶ側が捕まえて「売り切れ」に倒す（Catalog）
 */
final class StockComponents
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * その商品の在庫を引く品（種類の ID => 1 つあたりの数）。引く品が無ければ空の配列
     *
     * @return array<string, int>
     */
    public function of(string $listingId): array
    {
        return $this->forMany([$listingId])[$listingId] ?? [];
    }

    /**
     * それぞれの商品の在庫を引く品（商品の ID => 種類の ID => 1 つあたりの数）。
     * 引く品が無い商品は鍵ごと入らない（**ショップの一覧は商品ごとに聞かず、ここで 1 回だけ聞く**）
     *
     * @param  list<string>  $listingIds
     * @return array<string, array<string, int>>
     */
    public function forMany(array $listingIds): array
    {
        $listingIds = array_values(array_unique($listingIds));

        if ($listingIds === []) {
            return [];
        }

        if (count($listingIds) > StockComponentsProvider::MAX_LISTINGS) {
            throw new StockComponentsUnavailable('一度に聞ける商品が多すぎます（'.StockComponentsProvider::MAX_LISTINGS.' まで）');
        }

        $components = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->stockComponents === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            try {
                $answer = $plugin->stockComponents->stockComponents($listingIds);
            } catch (Throwable $e) {
                // 在庫を引かずに売るより止める方が安全（ほかの口のようにフェイルオープンにしない）
                throw new StockComponentsUnavailable("App {$plugin->name} の在庫の構成を読めませんでした", previous: $e);
            }

            foreach ($answer as $listingId => $parts) {
                // 聞いていない商品の答えは捨てる
                if (! in_array((string) $listingId, $listingIds, true)) {
                    continue;
                }

                foreach (self::checked($parts, $plugin->name) as $variantId => $each) {
                    // 同じ品を複数の App が挙げたら足す（ふだんは 1 つの App だけ）
                    $components[(string) $listingId][$variantId] = ($components[(string) $listingId][$variantId] ?? 0) + $each;
                }
            }
        }

        foreach ($components as $listingId => $parts) {
            if (count($parts) > StockComponentsProvider::MAX_COMPONENTS) {
                throw new StockComponentsUnavailable("商品 {$listingId} の在庫を引く品が多すぎます（".StockComponentsProvider::MAX_COMPONENTS.' まで）');
            }
        }

        return $components;
    }

    /**
     * App の答えを確かめる。**App の中身は信じられない**ので、宣言の型ではなく実物を見る
     * （PHP は数字に見える鍵を int にするので、鍵も文字として確かめ直す）。
     * 種類の ID の形が違うものは捨て、数が 1 以上でないものは取引を止める
     *
     * @return array<string, int>
     */
    private static function checked(mixed $parts, string $plugin): array
    {
        if (! is_array($parts)) {
            throw new StockComponentsUnavailable("App {$plugin} が返した在庫の構成の形が不正です");
        }

        $checked = [];

        foreach ($parts as $variantId => $each) {
            // PHP は数字に見える鍵を int にするので、文字へ寄せてから形を見る（鍵は必ず int か string）
            $id = (string) $variantId;

            if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) !== 1) {
                continue;
            }

            // 数は口が整数と約束している（型は phpstan が見張る）。ここで見るのは範囲。
            // 数字でない文字列が来ても 0 に寄るのでここで落ちる
            if ($each < 1) {
                throw new StockComponentsUnavailable("App {$plugin} が返した在庫の数が不正です（{$id}）");
            }

            $checked[$id] = $each;
        }

        return $checked;
    }
}
