<?php

declare(strict_types=1);

namespace App\Core\Transaction;

use App\Core\Inventory\Models\InventoryUnit;
use App\Core\Inventory\Models\Location;
use App\Core\Listing\Enums\Pricing;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Transaction\Enums\LineType;
use InvalidArgumentException;

/**
 * 明細の下書き。取引の開始と提示（Quote）で使い、合意で transaction_lines になる。
 *
 * 金額の上限は整数演算が溢れないための安全柵（1 行 1 兆、1 取引 500 行。税計算の掛け算でも PHP_INT_MAX に届かない）。
 * 店頭や API から来る値をそのまま渡さないこと。商品の価格は fromListing で出品物から取る。
 */
final readonly class LineDraft
{
    public const int MAX_AMOUNT = 1_000_000_000_000;

    public const int MAX_QUANTITY = 1_000_000;

    public const int MAX_TAX_RATE_BP = 10_000;

    public int $amount;

    public function __construct(
        public LineType $lineType,
        public string $description,
        public int $quantity,
        public int $unitAmount,
        public int $taxRateBp,
        public ?string $kind = null,
        public ?string $listingId = null,
        public ?string $inventoryUnitId = null,
        public ?string $locationId = null,
        public ?string $variantId = null,
        public ?string $sku = null,
        public ?int $parentPosition = null,
        public ?string $addonText = null,
    ) {
        if (trim($description) === '' || mb_strlen($description) > 200) {
            throw new InvalidArgumentException('明細の名称は 1〜200 文字です');
        }

        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException('明細の数量は 1〜'.self::MAX_QUANTITY.' です');
        }

        if ($taxRateBp < 0 || $taxRateBp > self::MAX_TAX_RATE_BP) {
            throw new InvalidArgumentException('税率が範囲外です');
        }

        if (abs($unitAmount) > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('単価が上限を超えています');
        }

        if ($kind !== null && preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', $kind) !== 1) {
            throw new InvalidArgumentException("明細の種類名が不正です: {$kind}");
        }

        if ($lineType === LineType::Item) {
            if ($unitAmount < 0) {
                throw new InvalidArgumentException('商品の明細の単価は 0 以上です（値引きは調整行で）');
            }
        } elseif ($quantity !== 1 || $kind === null || $listingId !== null || $inventoryUnitId !== null || $locationId !== null || $variantId !== null || $sku !== null) {
            throw new InvalidArgumentException('調整行は数量 1、種類が必須で、出品物・個体・拠点を持ちません');
        }

        // オプションの行（ADR 0036）は、商品の行（位置）に従う調整の行。入れた文字は個人情報になりうるので、持てるのはオプションの行だけ
        if (($kind === 'addon') !== ($parentPosition !== null) || ($addonText !== null && $kind !== 'addon') || ($parentPosition !== null && $parentPosition < 1)) {
            throw new InvalidArgumentException('オプションの行は、従う商品の行を指す調整の行です');
        }

        if ($variantId !== null && $listingId === null) {
            throw new InvalidArgumentException('種類の明細は出品物も指します');
        }

        if ($sku !== null && mb_strlen($sku) > 64) {
            throw new InvalidArgumentException('品番は 64 文字までです');
        }

        if ($inventoryUnitId !== null && $quantity !== 1) {
            throw new InvalidArgumentException('個体の明細は数量 1 です');
        }

        $amount = $quantity * $unitAmount;

        // 数量と単価の上限から、掛け算が整数の範囲を溢れることはない
        if (abs($amount) > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('明細の金額が上限を超えています');
        }

        $this->amount = $amount;
    }

    /**
     * 固定価格の出品物から明細を作る。価格は出品物のもの（呼び出し側から渡させない）。
     * 種類を選ばない形なので、軸の無い商品（種類が 1 つ）だけ。種類のある商品は fromVariant。
     */
    public static function fromListing(Listing $listing, int $quantity, int $taxRateBp, ?Location $location = null, ?InventoryUnit $unit = null): self
    {
        $variant = $listing->soleVariant() ?? throw new InvalidArgumentException('種類のある商品は、種類を選んで明細にします');

        return self::fromVariant($listing, $variant, $quantity, $taxRateBp, $location, $unit);
    }

    /**
     * 固定価格の出品物の種類から明細を作る。価格は種類（空なら出品物）のもの。名前に種類の名前（「赤 / M」）を、品番を写して残す（ADR 0036）。
     */
    public static function fromVariant(Listing $listing, ListingVariant $variant, int $quantity, int $taxRateBp, ?Location $location = null, ?InventoryUnit $unit = null): self
    {
        if ($variant->listing_id !== $listing->id) {
            throw new InvalidArgumentException('種類がこの出品物のものではありません');
        }

        $price = $variant->price($listing);

        if ($listing->pricing !== Pricing::Fixed || $price === null) {
            throw new InvalidArgumentException('固定価格の出品物ではありません（査定・見積は提示で金額を決める）');
        }

        $label = $variant->label();

        return new self(
            lineType: LineType::Item,
            description: mb_substr($label === '' ? $listing->title : $listing->title.'（'.$label.'）', 0, 200),
            quantity: $quantity,
            unitAmount: $price,
            taxRateBp: $taxRateBp,
            listingId: $listing->id,
            inventoryUnitId: $unit?->id,
            locationId: $location->id ?? $unit?->location_id,
            variantId: $variant->id,
            sku: $variant->sku,
        );
    }

    /**
     * 種類を指していない商品の明細（種類の前に保存した提示）に、種類を入れた写し。
     */
    public function withVariant(ListingVariant $variant): self
    {
        return new self($this->lineType, $this->description, $this->quantity, $this->unitAmount, $this->taxRateBp, $this->kind,
            $this->listingId, $this->inventoryUnitId, $this->locationId, $variant->id, $this->sku ?? $variant->sku, $this->parentPosition, $this->addonText);
    }

    /**
     * オプションの行（ADR 0036）。数の分を 1 行にまとめ（調整の行は数 1）、税率は従う商品の行と同じ。入れた文字は名前に入れず addonText に
     */
    public static function addon(int $parentPosition, string $description, int $amount, int $taxRateBp, ?string $text = null): self
    {
        return new self(LineType::Adjustment, $description, 1, $amount, $taxRateBp, 'addon', parentPosition: $parentPosition, addonText: $text);
    }

    public static function adjustment(string $kind, string $description, int $amount, int $taxRateBp): self
    {
        return new self(LineType::Adjustment, $description, 1, $amount, $taxRateBp, $kind);
    }

    /**
     * 入れた文字（addon_text）は平文で入る。取引の行のモデルが暗号化して書く。提示（jsonb）には入れさせない（TransactionService::propose が断る）
     *
     * @return array{line_type: string, kind: string|null, listing_id: string|null, variant_id: string|null, sku: string|null, inventory_unit_id: string|null, location_id: string|null, description: string, quantity: int, unit_amount: int, amount: int, tax_rate_bp: int, parent_position: int|null, addon_text: string|null}
     */
    public function toArray(): array
    {
        return [
            'line_type' => $this->lineType->value,
            'kind' => $this->kind,
            'listing_id' => $this->listingId,
            'variant_id' => $this->variantId,
            'sku' => $this->sku,
            'inventory_unit_id' => $this->inventoryUnitId,
            'location_id' => $this->locationId,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unitAmount,
            'amount' => $this->amount,
            'tax_rate_bp' => $this->taxRateBp,
            'parent_position' => $this->parentPosition,
            'addon_text' => $this->addonText,
        ];
    }

    /**
     * 保存した提示（jsonb）から戻す。コンストラクタの検査をもう一度通る。
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
        $int = static fn (string $key): int => isset($data[$key]) && is_int($data[$key]) ? $data[$key] : throw new InvalidArgumentException("明細の {$key} が不正です");

        return new self(
            lineType: LineType::from((string) $string('line_type')),
            description: (string) $string('description'),
            quantity: $int('quantity'),
            unitAmount: $int('unit_amount'),
            taxRateBp: $int('tax_rate_bp'),
            kind: $string('kind'),
            listingId: $string('listing_id'),
            inventoryUnitId: $string('inventory_unit_id'),
            locationId: $string('location_id'),
            variantId: $string('variant_id'),
            sku: $string('sku'),
            parentPosition: isset($data['parent_position']) && is_int($data['parent_position']) ? $data['parent_position'] : null,
            addonText: $string('addon_text'),
        );
    }
}
