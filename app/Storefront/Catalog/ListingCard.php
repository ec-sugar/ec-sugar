<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Money\Money;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Tax\TaxClass;

/**
 * 店頭に出す商品の形（テーマ API の一部）。在庫の数そのものは出さず、在庫の具合だけを渡す。
 */
final readonly class ListingCard
{
    public const string IN_STOCK = 'in_stock';

    public const string LOW_STOCK = 'low_stock';

    public const string SOLD_OUT = 'sold_out';

    /**
     * @param  int  $price  最小通貨単位（円）。税込み
     * @param  string  $availability  in_stock | low_stock | sold_out
     * @param  int|null  $remaining  low_stock のときだけ残りの数
     * @param  ListingPhoto|null  $photo  1 枚目の写真（無ければ null）
     * @param  string|null  $summary  一覧に出す短い説明（1 行。店の人が書いた素の文字。ADR 0032）
     * @param  bool  $priceVaries  種類で価格が違う（price は一番安い種類。priceLabel() は「¥4,800〜」。ADR 0036）
     * @param  int  $taxRateBp  消費税の率（basis point。10% = 1000。商品の区分から核が決める。ADR 0063）
     * @param  string|null  $preorder  予約の商品の発売日（Y-m-d。発送を始める日。ADR 0073）。予約でなければ null。在庫の具合は予約の残りで決まる
     */
    public function __construct(
        public string $id,
        public string $title,
        public int $price,
        public string $currency,
        public string $availability,
        public ?int $remaining,
        public ?ListingPhoto $photo = null,
        public ?string $summary = null,
        public bool $priceVaries = false,
        public int $taxRateBp = 1000,
        public ?string $preorder = null,
    ) {}

    /**
     * 予約の商品の一言（「予約商品（11月20日ごろ発送）」。予約でなければ null。ADR 0073）
     */
    public function preorderLabel(): ?string
    {
        return $this->preorder === null ? null : PreorderTerms::label($this->preorder);
    }

    /**
     * 在庫の具合の文（「在庫あり」「残り 2 点」「売り切れ」。予約の商品は「予約商品（11月20日ごろ発送）」「…・残り 2 点」「予約の受付を終えました」。ADR 0073）
     */
    public function availabilityLabel(): string
    {
        return self::describe($this->availability, $this->remaining, $this->preorder);
    }

    /**
     * 在庫の具合の文（札と種類で同じ決め方）
     */
    public static function describe(string $availability, ?int $remaining, ?string $preorder): string
    {
        if ($preorder !== null) {
            return match ($availability) {
                self::SOLD_OUT => '予約の受付は終了しました',
                self::LOW_STOCK => PreorderTerms::label($preorder)."・残り {$remaining} 点",
                default => PreorderTerms::label($preorder),
            };
        }

        return match ($availability) {
            self::SOLD_OUT => '売り切れ',
            self::LOW_STOCK => "残り {$remaining} 点",
            default => '在庫あり',
        };
    }

    /**
     * 「カートに入れる」の文字（予約の商品は「予約する」。ADR 0073）
     */
    public function addToCartLabel(): string
    {
        return $this->preorder === null ? 'カートに入れる' : '予約する';
    }

    /**
     * 軽減税率（8%）の対象か（「※ 軽減税率の対象」と添える。ADR 0063）
     */
    public function reducedTax(): bool
    {
        return TaxClass::isReducedRate($this->taxRateBp);
    }

    /**
     * 商品の詳細の URL
     */
    public function url(): string
    {
        return route('storefront.product', ['listingId' => $this->id], false);
    }

    /**
     * 価格の表示。種類で価格が違えば、一番安いものから（「¥4,800〜」）
     */
    public function priceLabel(): string
    {
        return Money::format($this->price, $this->currency).($this->priceVaries ? '〜' : '');
    }
}
