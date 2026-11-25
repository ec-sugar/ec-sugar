<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Listing\Models\Listing;

/**
 * デモの店で、App が数える売れる数（予約の枠・予約販売の数・席など）を作った時の数へ戻す口（ADR 0086）。
 * デモの店は誰でも払わずに（銀行振込・代引き・テストのカードで）押さえられるので、1 時間ごとの demo:restock が呼ぶ。
 * デモの店の文脈の中で、その App を使っている時だけ呼ばれる。書き込みは App のいつもの口（連鎖のロックとイベント）を通す。
 * 押さえた注文はそのまま（取り消さない）で、押さえた分だけ数を足して「残り」を作った時の数にそろえる。毎晩の戻しで全部消える
 */
interface DemoRestockHandler
{
    /**
     * @param  array<string, bool|string>  $built  この商品のこの App の欄の、作った時の値（日付などは解かずにそのまま。数の欄だけを読む）
     * @param  string  $actor  記録に残す actor（system:demo-restock）
     * @return int 直した数（枠・回・区分の数。何も変えなければ 0）
     */
    public function restockDemo(Listing $listing, array $built, string $actor): int;
}
