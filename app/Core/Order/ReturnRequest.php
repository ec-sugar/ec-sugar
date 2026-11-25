<?php

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Payment\Enums\ReturnReason;

/**
 * 返品・一部返金で店の人が選んだもの（ADR 0048）。品の額はサーバーが明細から決める（店の人は下げることだけできる。金額だけの返金の額は入れる）
 *
 * - items: 明細の位置 => 返す数・在庫に戻すか・その品のオプションの料金も返すか
 * - shipping: 送料（まだ返していない分の全部）も返すか
 * - amount: 金額だけの返金（円。0 なら無し）
 * - channel: provider（決済代行で返す）・outside（店が外で返した。記録だけ）
 * - goodsAmount: 品（商品・オプション・送料の行）の返す額（円）。null なら既定の「品の額と返せる残りの小さい方」。
 *   0 から既定までに下げられる（0 なら品だけ返して在庫に戻し、お金は動かさない）
 */
final readonly class ReturnRequest
{
    /**
     * @param  array<int, array{quantity: int, restock: bool, addons: bool}>  $items
     * @param  'provider'|'outside'  $channel
     */
    public function __construct(
        public array $items,
        public bool $shipping,
        public int $amount,
        public ReturnReason $reason,
        public string $channel,
        public ?int $goodsAmount = null,
    ) {}
}
