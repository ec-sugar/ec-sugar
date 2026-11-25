<?php

declare(strict_types=1);

namespace App\Core\Listing\Models;

use App\Core\Catalog\Models\Category;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\InventoryUnit;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\OfferSide;
use App\Core\Listing\Enums\Pricing;
use App\Core\Listing\ListingKinds;
use App\Core\Tax\TaxClass;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 出品物（テナント DB）。商品、定期プラン、買取受付、サービス、仕事の募集をひとつの表で持つ。
 *
 * - kind は ListingKinds に登録済みの種類だけ（保存時に検査）
 * - 金額は最小通貨単位の整数（JPY なら円）。浮動小数は使わない
 * - 商品名や説明は個人情報ではないので暗号化しない（検索と並べ替えが要る）
 * - 種類ごとの追加属性は attributes（jsonb）。核のテーブルに列を足さない
 * - 説明はブロックの並び（description_blocks）・仕様の表（specs）・短い説明（summary）。形は ProductDescription が確かめ、変えるのは ListingService だけ（ADR 0032）。
 *   description はブロックの文をつないだ写し（前のバージョンのアプリと検索エンジン向けの説明のため）
 *
 * @property string $id
 * @property string $seller_id
 * @property string $kind
 * @property OfferSide $offer_side
 * @property Pricing $pricing
 * @property ListingStatus $status
 * @property string $title
 * @property string|null $description ブロックの文の写し（ListingService が書く）
 * @property list<array<string, mixed>> $description_blocks
 * @property list<array{label: string, value: string}> $specs
 * @property string|null $summary
 * @property string|null $seo_title 検索の結果に出す題（空なら商品名。ADR 0061）
 * @property string|null $seo_description 検索の結果に出す説明（空なら短い説明か説明の頭）
 * @property int|null $unit_amount
 * @property TaxClass $tax_class 消費税の区分（ADR 0063。率は区分から核が決める。既定は標準）
 * @property string $currency
 * @property-read string|null $sku 軸の無い商品の品番（種類が 1 つのときだけ。品番は種類が持つ。ADR 0036）
 * @property array<string, mixed>|null $attributes_data
 * @property int $version 楽観ロックのバージョン（ListingService::update が進める）
 * @property string|null $shipping_method_id 配送の方法（null はいちばん上の方法。ADR 0064）
 * @property bool $free_shipping 送料無料の商品（同じ便の送料が無料になる。ADR 0064）
 */
final class Listing extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'listings';

    protected $fillable = [
        'seller_id', 'kind', 'offer_side', 'pricing', 'status',
        'title', 'description', 'unit_amount', 'currency', 'attributes_data',
        'description_blocks', 'specs', 'summary', 'shipping_method_id', 'free_shipping', 'seo_title', 'seo_description', 'tax_class',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'JPY',
        'tax_class' => 'standard',
        'version' => 1,
        'description_blocks' => '[]',
        'specs' => '[]',
    ];

    protected static function booted(): void
    {
        self::saving(static function (self $listing): void {
            $definition = app(ListingKinds::class)->get($listing->kind);

            // 種類の既定を埋める（明示されていれば尊重する）
            $listing->offer_side ??= $definition->offerSide;
            $listing->pricing ??= $definition->pricing;

            if ($listing->pricing === Pricing::Fixed && $listing->unit_amount === null) {
                throw new \InvalidArgumentException('固定価格の出品物には unit_amount が必要です');
            }

            if ($listing->unit_amount !== null && $listing->unit_amount < 0) {
                throw new \InvalidArgumentException('unit_amount は 0 以上の整数です');
            }
        });

        // どの商品も種類を 1 つ以上持つ（ADR 0036）。作ったら、軸の無い種類を 1 つ作る
        self::created(static function (self $listing): void {
            ListingVariant::query()->create(['listing_id' => $listing->id]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offer_side' => OfferSide::class,
            'pricing' => Pricing::class,
            'status' => ListingStatus::class,
            'unit_amount' => 'integer',
            'tax_class' => TaxClass::class,
            'attributes_data' => 'array',
            'description_blocks' => 'array',
            'specs' => 'array',
            'version' => 'integer',
            'free_shipping' => 'boolean',
        ];
    }

    /**
     * この商品の明細の税率（basis point）。区分から核が決める（ADR 0063）
     */
    public function taxRateBp(): int
    {
        return $this->tax_class->rateBp();
    }

    /**
     * @return HasMany<ListingVariant, $this> 並び順に（消したものは除く）
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ListingVariant::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ListingOption, $this> 軸の順に
     */
    public function options(): HasMany
    {
        return $this->hasMany(ListingOption::class)->orderBy('position');
    }

    /**
     * 軸の無い商品のただ 1 つの種類。軸がある・種類が 2 つ以上なら null（どの種類かを選ばせる）。
     */
    public function soleVariant(): ?ListingVariant
    {
        $variants = $this->variants()->limit(2)->get();

        return $variants->count() === 1 && $variants->first()?->value_1 === null ? $variants->first() : null;
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function sku(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->soleVariant()?->sku);
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @return HasMany<InventoryStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    /**
     * @return HasMany<InventoryUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(InventoryUnit::class);
    }

    /**
     * @return BelongsToMany<Category, $this> 主カテゴリは pivot の is_primary
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'listing_categories')->withPivot('is_primary');
    }

    /**
     * @return HasMany<ListingImage, $this> 並び順に
     */
    public function images(): HasMany
    {
        return $this->hasMany(ListingImage::class)->orderBy('position');
    }
}
