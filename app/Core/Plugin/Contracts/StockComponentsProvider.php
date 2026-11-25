<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * この商品の在庫は、どの品からいくつ引くかを App が答える口（App「セット商品」が実装する）。
 * 使う店だけで呼ばれる。
 *
 * セット商品は自分の在庫を持たず、構成する品の在庫から引く。核はこの口を通して
 * 「押さえる・解く・出庫する」の 3 つと、「買えるか」「どの拠点から出すか」を決める。
 * 答えがあればその品を、無ければ今までどおり商品そのものの在庫を見る。
 *
 * **1 つでも足りなければ丸ごと断る**（核が同じトランザクションの中で押さえるので、途中で足りなくなれば全部戻る）。
 * セットの中にセットは入れられない（核は 1 段だけ辿る。App が入れ子を断ること）。
 *
 * **まとめて聞く形にしてある**のは、ショップの一覧が商品ごとに App へ問い合わせないため
 * （一覧 1 画面で 1 回だけ呼ぶ。1 件だけ聞きたい核の側は、1 件の配列を渡す）
 */
interface StockComponentsProvider
{
    /** 1 つの商品が引ける品の数の上限（押さえるたびに行のロックを取るので、数を抑える） */
    public const int MAX_COMPONENTS = 20;

    /** 1 回に聞ける商品の数の上限（ショップの一覧の 1 ページ分より多ければ足りる） */
    public const int MAX_LISTINGS = 200;

    /**
     * それぞれの商品の在庫を引く品（商品の ID => 種類の ID => 1 つあたりの数）。
     * 自分の在庫を持つ商品は、鍵を入れないか空の配列を入れる。
     *
     * 種類の ID は `listing_variants.id`。数は 1 以上。MAX_COMPONENTS を超えた分は核が切る。
     * 聞かれていない商品を入れても核が捨てる
     *
     * @param  list<string>  $listingIds  MAX_LISTINGS 件まで
     * @return array<string, array<string, int>>
     */
    public function stockComponents(array $listingIds): array;
}
