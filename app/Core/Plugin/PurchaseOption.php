<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use InvalidArgumentException;

/**
 * App がショップの商品の画面に置く買い方（ADR 0050）。ボタンの文字・行き先・短い案内。行き先はその App のショップの経路（/p/<名前>/…）だけ。
 * whenSoldOut は、商品が売り切れの時にも出す印（App「再入荷のお知らせ」の「入荷したら知らせる」など）。印の無い買い方は、カートに入れられる時だけ出す。
 * pressed を付けると、リンクでなく「押す・戻す」のボタン（トグル）になる（App「お気に入り」のハートなど。ADR 0071）。
 * 核の部品が行き先へ POST するフォーム（CSRF の合い言葉つき）で描き、真なら押してある形（aria-pressed）。label は今押すとすること（「お気に入りに追加」）。
 * JavaScript があれば核の部品（toggles.js）が画面を移らずに送り、答え（{pressed, label}）で形を替える
 */
final readonly class PurchaseOption
{
    public function __construct(
        public string $plugin,
        public string $label,
        public string $path,
        public ?string $note = null,
        public bool $whenSoldOut = false,
        public ?bool $pressed = null,
    ) {
        if (preg_match('#\A/p/'.preg_quote($plugin, '#').'/[A-Za-z0-9/_-]{1,200}\z#', $path) !== 1) {
            throw new InvalidArgumentException("App {$plugin} の買い方の行き先は、自分の /p/{$plugin}/ の下だけです");
        }

        if ($label === '' || mb_strlen($label) > 40 || ($note !== null && mb_strlen($note) > 120)) {
            throw new InvalidArgumentException("App {$plugin} の買い方の文字が長すぎます");
        }
    }

    /**
     * 押す・戻すのボタン（トグル）か
     */
    public function isToggle(): bool
    {
        return $this->pressed !== null;
    }

    /**
     * 商品の画面に出す買い方。売り切れの商品（$soldOut）では、売り切れの時にも出す印のあるものだけ（核の部品 purchase-options が使う）
     *
     * @param  list<self>  $options
     * @return list<self>
     */
    public static function shown(array $options, bool $soldOut): array
    {
        return $soldOut ? array_values(array_filter($options, static fn (self $option): bool => $option->whenSoldOut)) : $options;
    }
}
