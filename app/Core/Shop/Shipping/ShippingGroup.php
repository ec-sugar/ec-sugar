<?php

declare(strict_types=1);

namespace App\Core\Shop\Shipping;

/**
 * 1 つの便（同じ配送の方法で送る品のまとまり。ADR 0064 の 3）の送料。金額は円の整数。
 * fee は取る額（都道府県の額・贈り物ならいちばん高い額・目安ならいちばん安い額）、max は目安のいちばん高い額（目安でなければ fee と同じ）。
 * basis は決め方: rate（都道府県の額）・highest（いちばん高い額）・free_over（この金額以上で無料）・free_item（送料無料の商品が入っている）・coupon（送料無料のクーポン）
 */
final readonly class ShippingGroup
{
    /**
     * @param  list<string>  $keys  この便の品の鍵（カートの行など。呼ぶ側が渡したもの）
     * @param  array<string, int>  $rates  この方法の 47 県の額（贈り物の差額に写す）
     * @param  int|null  $remaining  あといくらで無料か（無料の線が無い・もう無料なら null）
     */
    public function __construct(
        public ?string $methodId,
        public string $name,
        public array $keys,
        public int $itemsAmount,
        public int $fee,
        public int $max,
        public string $basis,
        public ?int $remaining,
        public array $rates,
    ) {}

    public function waived(): self
    {
        return new self($this->methodId, $this->name, $this->keys, $this->itemsAmount, 0, 0, $this->fee > 0 || $this->max > 0 ? 'coupon' : $this->basis, null, $this->rates);
    }
}
