<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Money\Money;

/**
 * 店頭の商品の詳細（テーマ API の一部）。公開中の商品だけ。在庫は具合だけ（数は low_stock のときの残りだけ）。
 * 説明はブロックの並び（blocks）と仕様の表（specs）。description はブロックの文をつないだ素の文（ADR 0032）。
 * 種類（色・サイズ。ADR 0036）は軸（options）と販売中の種類（variants）。軸の無い商品では両方とも空。
 * オプション（名入れ・ギフト包装など。ADR 0036）は addons。無い商品では空。
 * カートに入れるのを断られて戻ったときだけ、入れていたオプションの値（addonValues）を持つ（次の 1 回の画面だけ）。
 */
final readonly class ProductView
{
    /**
     * @param  string|null  $description  ブロックの文をつないだ素の文（検索エンジン向けの説明の元）
     * @param  list<ListingPhoto>  $photos  並び順に（代わりの文字が無ければ商品名）
     * @param  list<array{name: string, url: string}>  $breadcrumbs  主カテゴリまでの道（一番上から主カテゴリまで）
     * @param  int  $maxQuantity  カートに入れられる数の上限（在庫と 10 の小さい方。売り切れなら 0）
     * @param  list<DescriptionBlock>  $blocks  説明のブロック（描けるものだけ）
     * @param  list<array{label: string, value: string}>  $specs  仕様の表（value は改行を含む）
     * @param  list<array{name: string, values: list<array{id: string, label: string}>}>  $options  選ぶ軸（軸の順。値は並び順）
     * @param  list<ProductVariant>  $variants  販売中の種類（並び順。売り切れも含む）
     * @param  list<ProductAddon>  $addons  オプション（並び順）
     * @param  array<string, string>  $addonValues  断られて戻ったときの欄の値（オプションの id → 値）。ふだんは空
     */
    public function __construct(
        public ListingCard $card,
        public ?string $description,
        public array $photos,
        public array $breadcrumbs,
        public string $url,
        public int $maxQuantity = 0,
        public array $blocks = [],
        public array $specs = [],
        public array $options = [],
        public array $variants = [],
        public array $addons = [],
        public array $addonValues = [],
    ) {}

    /**
     * 断られて戻ったときの欄の値を持たせた写し（この商品のオプションの値だけ）
     *
     * @param  array<string, string>  $values
     */
    public function withAddonValues(array $values): self
    {
        $ids = array_map(static fn (ProductAddon $a): string => $a->id, $this->addons);

        return new self($this->card, $this->description, $this->photos, $this->breadcrumbs, $this->url, $this->maxQuantity, $this->blocks,
            $this->specs, $this->options, $this->variants, $this->addons, array_intersect_key($values, array_flip($ids)));
    }

    /**
     * オプションの欄の初めの値（断られて戻ったときだけ。ふだんは null）
     */
    public function addonValue(string $addonId): ?string
    {
        return $this->addonValues[$addonId] ?? null;
    }

    /**
     * オプションを選ばせる商品か
     */
    public function hasAddons(): bool
    {
        return $this->addons !== [];
    }

    /**
     * はじめの合計（「1 点で ¥2,700」。選んでおく種類の価格 ＋ 欄の初めの値のオプションの額。数は 1）。
     * カートの行の小計と同じ（価格 ＋ オプションの額）× 数。数を変えたら JavaScript が書き直す
     */
    public function addonTotalLabel(int $quantity = 1): string
    {
        $price = $this->selectedVariant()->price ?? $this->card->price;
        $unit = $price + array_sum(array_map(fn (ProductAddon $a): int => $a->amountFor($this->addonValue($a->id)), $this->addons));

        return self::quantityTotalLabel($unit * $quantity, $quantity, $this->card->currency);
    }

    /**
     * 「2 点で ¥5,400」（金額は Money::format の形。円のほかは「2 点で 54 USD」。JavaScript の money.js と同じ形）
     */
    public static function quantityTotalLabel(int $total, int $quantity, string $currency): string
    {
        return "{$quantity} 点で ".Money::format($total, $currency);
    }

    /**
     * 核の部品（addon-fields）が data- の印（JSON）で渡す、合計を出すための価格とオプション。
     * 種類のある商品は種類ごとの価格（選んだ種類は name="variant_id" の値で見る）
     */
    public function addonData(): string
    {
        $prices = [];

        foreach ($this->variants as $variant) {
            $prices[$variant->id] = $variant->price;
        }

        return json_encode([
            'currency' => $this->card->currency,
            'base' => $this->selectedVariant()->price ?? $this->card->price,
            'prices' => (object) $prices,
            'addons' => array_map(static fn (ProductAddon $a): array => $a->toArray(), $this->addons),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 種類を選ばせる商品か（軸があり、販売中の種類がある）
     */
    public function hasVariants(): bool
    {
        return $this->options !== [] && $this->variants !== [];
    }

    /**
     * はじめに選んでおく種類（売り切れでない最初の種類。すべて売り切れなら最初の種類）
     */
    public function selectedVariant(): ?ProductVariant
    {
        foreach ($this->variants as $variant) {
            if (! $variant->soldOut()) {
                return $variant;
            }
        }

        return $this->variants[0] ?? null;
    }

    /**
     * 種類で価格が違うか
     */
    public function pricesVary(): bool
    {
        return count(array_unique(array_map(static fn (ProductVariant $v): int => $v->price, $this->variants))) > 1;
    }

    /**
     * 核の部品（variant-picker）が data- の印（JSON）で渡す、軸と種類
     */
    public function variantData(): string
    {
        return json_encode([
            'options' => $this->options,
            'variants' => array_map(static fn (ProductVariant $v): array => $v->toArray(), $this->variants),
            'selected' => $this->selectedVariant()?->id,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 説明のブロックがあるか
     */
    public function hasDescription(): bool
    {
        return $this->blocks !== [];
    }

    /**
     * 検索エンジン向けの説明（短い説明か、説明の先頭。無ければ null）
     */
    public function summary(): ?string
    {
        if ($this->card->summary !== null) {
            return $this->card->summary;
        }

        if ($this->description === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $this->description));

        return $text === '' ? null : mb_strimwidth($text, 0, 240, '…');
    }
}
