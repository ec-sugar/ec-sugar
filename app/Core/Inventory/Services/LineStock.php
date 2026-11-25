<?php

declare(strict_types=1);

namespace App\Core\Inventory\Services;

use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Plugin\Services\StockComponents;
use Illuminate\Support\Facades\DB;

/**
 * 取引の行の在庫を動かす。押さえる・解く・出庫する・返品で戻すを 1 か所にまとめ、
 * **セット商品（自分の在庫を持たず、構成する品から引く商品）をここだけで吸収する**。
 *
 * **構成を App に聞くのは押さえる時の 1 回だけ**。聞いた答えを行にひもづけて控え（transaction_line_components）、
 * 解く・出庫する・戻すは必ずその控えから計算する。後から聞き直すと、その間にセットの中身を変えた・セットをやめた・
 * App を止めた（または使うに戻した）・App のデータを消した時に、押さえた数と動かす数が食い違う:
 * 確保が残り続ける・取消や発送が「確保していない数」の例外で通らない・ほかの注文の確保を黙って食って売りすぎる。
 *
 * 控えが無い行は、商品そのものの在庫を押さえた行（ふつうの商品。App を止めていた間に売れたセットも同じ）。
 *
 * 取引の行は ID と中身（商品・種類・拠点）で受け取る。取引のモデルは読まない（在庫の文脈が取引の文脈を知ると、
 * 核の文脈どうしの頼り合いが 1 つ増える。ArchitectureTest が止める）。
 *
 * 呼ぶ側（TransactionService・FulfillmentService・OrderReturns）は、必ず連鎖のロックと行ロックを持つトランザクションの中で呼ぶ。
 * 1 つでも足りなければ StockService が例外を投げ、トランザクションごと戻る（部分的に押さえた状態も、控えも残さない）
 */
final class LineStock
{
    private const string TABLE = 'transaction_line_components';

    public function __construct(
        private readonly StockService $stock,
        private readonly StockComponents $components,
    ) {}

    /**
     * 確保する（足りなければ InsufficientStockException。トランザクションごと戻す）。構成はここで聞いて控える
     */
    public function reserve(string $lineId, ?string $listingId, string $variantId, string $locationId, int $quantity): void
    {
        foreach ($this->targets($lineId, $listingId, $variantId, $locationId, $quantity, remember: true) as [$variant, $location, $n]) {
            $this->stock->reserve($variant, $location, $n);
        }
    }

    /**
     * 確保を解く（取消）。押さえた時の構成で
     */
    public function release(string $lineId, ?string $listingId, string $variantId, string $locationId, int $quantity): void
    {
        foreach ($this->targets($lineId, $listingId, $variantId, $locationId, $quantity) as [$variant, $location, $n]) {
            $this->stock->release($variant, $location, $n);
        }
    }

    /**
     * 確保分を出庫する（受渡）。押さえた時の構成で
     */
    public function commit(string $lineId, ?string $listingId, string $variantId, string $locationId, int $quantity): void
    {
        foreach ($this->targets($lineId, $listingId, $variantId, $locationId, $quantity) as [$variant, $location, $n]) {
            $this->stock->commit($variant, $location, $n);
        }
    }

    /**
     * 返した品を在庫に戻す（返品）。押さえた時の構成の品へ戻す（セットそのものの在庫には入れない）
     *
     * @return list<array{0: ListingVariant, 1: Location, 2: int, 3: InventoryStock}> 戻した種類・拠点・数・戻した後の在庫
     */
    public function receive(string $lineId, ?string $listingId, string $variantId, string $locationId, int $quantity): array
    {
        $received = [];

        foreach ($this->targets($lineId, $listingId, $variantId, $locationId, $quantity) as [$variant, $location, $n]) {
            $received[] = [$variant, $location, $n, $this->stock->receive($variant, $location, $n)];
        }

        return $received;
    }

    /**
     * 動かす先（種類・拠点・数）。控えがあればその構成、無ければ商品そのもの。
     * $remember の時（押さえる時）だけ、控えが無ければ App に聞いて控える
     *
     * @return list<array{0: ListingVariant, 1: Location, 2: int}>
     */
    private function targets(string $lineId, ?string $listingId, string $variantId, string $locationId, int $quantity, bool $remember = false): array
    {
        $location = Location::query()->findOrFail($locationId);
        $components = $this->remembered($lineId);

        if ($components === [] && $remember && $listingId !== null) {
            $components = $this->components->of($listingId);

            foreach ($components as $componentId => $each) {
                DB::table(self::TABLE)->insert(['line_id' => $lineId, 'variant_id' => $componentId, 'each' => $each]);
            }
        }

        if ($components === []) {
            return [[$this->variant($variantId), $location, $quantity]];
        }

        $targets = [];

        foreach ($components as $componentId => $each) {
            $targets[] = [$this->variant((string) $componentId), $location, $each * $quantity];
        }

        return $targets;
    }

    /**
     * 押さえた時に控えた構成（種類の ID => 1 つあたりの数）。ID の順（ロックの順をそろえる）
     *
     * @return array<string, int>
     */
    private function remembered(string $lineId): array
    {
        $rows = DB::table(self::TABLE)->where('line_id', $lineId)->orderBy('variant_id')->get(['variant_id', 'each']);
        $components = [];

        foreach ($rows as $row) {
            $components[(string) $row->variant_id] = (int) $row->each;
        }

        return $components;
    }

    /**
     * 種類は消したものも読む（確保のある種類は消せないが、押さえた後の動きを種類の削除で止めない）
     */
    private function variant(string $id): ListingVariant
    {
        return ListingVariant::query()->withTrashed()->findOrFail($id);
    }
}
