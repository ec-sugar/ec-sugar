<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Money\Money;

/**
 * 商品の詳細に出す種類（テーマ API 2 の付け足し。ADR 0036）。販売中の種類だけ。在庫は具合だけ（数は low_stock のときの残りだけ）。
 */
final readonly class ProductVariant
{
    /**
     * @param  list<string>  $valueIds  選ぶ値の ID（軸の順。ProductView::options の値の id）
     * @param  string  $label  種類の名前（「墨 / M」）
     * @param  int  $price  最小通貨単位（円）。税込み
     * @param  string  $availability  in_stock | low_stock | sold_out
     * @param  int|null  $remaining  low_stock のときだけ残りの数
     * @param  int  $maxQuantity  カートに入れられる数の上限（在庫と 10 の小さい方。売り切れなら 0）
     * @param  ListingPhoto|null  $photo  この種類の写真（無ければ null。商品の写真のどれか）
     * @param  string|null  $preorder  予約の商品の発売日（Y-m-d。ADR 0073）。在庫の具合は予約の残りで決まる
     */
    public function __construct(
        public string $id,
        public array $valueIds,
        public string $label,
        public int $price,
        public string $currency,
        public string $availability,
        public ?int $remaining,
        public int $maxQuantity,
        public ?ListingPhoto $photo = null,
        public ?string $preorder = null,
    ) {}

    public function priceLabel(): string
    {
        return Money::format($this->price, $this->currency);
    }

    public function soldOut(): bool
    {
        return $this->availability === ListingCard::SOLD_OUT;
    }

    /**
     * 在庫の具合の文（「在庫あり」「残り 2 点」「売り切れ」）。予約の商品は「予約商品（11月20日ごろ発送）」（残りわずか・受付の終わりは添える。ADR 0073）
     */
    public function availabilityLabel(): string
    {
        return ListingCard::describe($this->availability, $this->remaining, $this->preorder);
    }

    /**
     * 核の部品（variant-picker）が data- の印で渡す形。写真の URL は店頭のオリジンの道筋だけ
     *
     * @return array{id: string, values: list<string>, label: string, price: string, availability: string, availability_label: string, max: int, photo: array{src: string, srcset: string, alt: string, width: int, height: int}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'values' => $this->valueIds,
            'label' => $this->label,
            'price' => $this->priceLabel(),
            'availability' => $this->availability,
            'availability_label' => $this->availabilityLabel(),
            'max' => $this->maxQuantity,
            'photo' => $this->photo === null ? null : [
                'src' => $this->photo->url('large'),
                'srcset' => $this->photo->srcset(),
                'alt' => $this->photo->alt,
                'width' => $this->photo->width,
                'height' => $this->photo->height,
            ],
        ];
    }
}
