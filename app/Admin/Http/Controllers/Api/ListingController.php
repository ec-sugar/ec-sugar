<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Requests\ListingRules;
use App\Admin\Http\Requests\ListListingsRequest;
use App\Admin\Http\Requests\StoreListingRequest;
use App\Admin\Http\Requests\UpdateListingRequest;
use App\Admin\Http\TenantActor;
use App\Core\Inventory\Enums\AdjustmentReason;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Listing\Description\ProductDescription;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\ListingKinds;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingOption;
use App\Core\Listing\Models\ListingOptionValue;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\StaleListingException;
use App\Core\Shop\Models\ShippingMethod;
use App\Core\Tax\TaxClass;
use App\Models\Tenant;
use App\Storefront\Catalog\Catalog;
use App\Storefront\View\StorefrontUrls;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * テナントの中の出品物（EnterTenant の後。ADR 0014）。
 *
 * 経路の {listingId} はモデルに結びつけない（SubstituteBindings は EnterTenant より前に動くので、中央 DB を引いてしまう）。
 * テナント文脈に入った後で、ここで探す。
 */
final class ListingController
{
    public function __construct(private readonly ListingService $listings) {}

    /**
     * 登録・編集の画面の選択肢（出品者と拠点、種類、状態、調整の理由）。
     */
    public function options(ListingKinds $kinds): JsonResponse
    {
        $locations = Location::query()->orderBy('name')->get()->groupBy('seller_id');

        return response()->json([
            'sellers' => Seller::query()->orderBy('name')->get()->map(static fn (Seller $seller): array => [
                'id' => $seller->id,
                'name' => $seller->name,
                'status' => $seller->status->value,
                'locations' => ($locations->get($seller->id) ?? collect())->map(static fn (Location $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'kind' => $location->kind->value,
                ])->values(),
            ]),
            'kinds' => array_map(static fn (string $kind): array => [
                'kind' => $kind,
                'label' => $kinds->get($kind)->label,
                'pricing' => $kinds->get($kind)->pricing->value,
            ], ListingRules::kinds()),
            'statuses' => array_map(static fn (ListingStatus $status): string => $status->value, ListingStatus::cases()),
            // 消費税の区分（ADR 0063）。率は区分から核が決める
            'tax_classes' => array_map(static fn (TaxClass $class): array => ['value' => $class->value, 'label' => $class->label(), 'rate_bp' => $class->rateBp()], TaxClass::cases()),
            // 配送の方法（ADR 0064。並び順。いちばん上が既定）
            'shipping_methods' => ShippingMethod::query()->orderBy('position')->orderBy('id')->get(['id', 'name'])->map(static fn (ShippingMethod $m): array => ['id' => $m->id, 'name' => $m->name])->values(),
            'adjustment_reasons' => array_map(static fn (AdjustmentReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()], AdjustmentReason::cases()),
            // 説明のエディタの上限（ADR 0032。守るのはサーバー）
            'description_limits' => ProductDescription::listingLimits(),
        ]);
    }

    public function index(ListListingsRequest $request): JsonResponse
    {
        [$column, $direction] = $request->sort();
        $q = trim((string) $request->validated('q', ''));
        $sellerId = $request->validated('seller_id');
        $status = $request->validated('status');

        // 検索語と出品者の絞り込み（状態ごとの件数にも効かせる）
        $filter = static function (Builder $query) use ($q, $sellerId): void {
            if ($q !== '') {
                // LIKE の記号は文字として扱う
                $pattern = '%'.addcslashes($q, '\\%_').'%';
                // 品番は種類が持つ（ADR 0036）
                $query->where(static fn (Builder $w) => $w->whereRaw('title ILIKE ?', [$pattern])
                    ->orWhereExists(static fn ($v) => $v->from('listing_variants')->whereColumn('listing_variants.listing_id', 'listings.id')
                        ->whereNull('listing_variants.deleted_at')->whereRaw('listing_variants.sku ILIKE ?', [$pattern])));
            }

            if (is_string($sellerId) && $sellerId !== '') {
                $query->where('seller_id', $sellerId);
            }
        };

        // 状態ごとの件数（絞り込みのタブに出す）。状態の絞り込みは効かせない
        $counts = Listing::query()->tap($filter)->toBase()->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')
            ->map(static fn (mixed $n): int => (int) $n);

        // 在庫は今の種類の行だけを合わせる（軸を決め直して消えた種類の行は数えない。ADR 0036）
        $liveVariant = static fn (Builder $stock) => $stock->whereExists(static fn ($v) => $v->from('listing_variants')
            ->whereColumn('listing_variants.id', 'inventory_stocks.variant_id')->whereNull('listing_variants.deleted_at'));

        $query = Listing::query()
            ->tap($filter)
            ->when(is_string($status) && $status !== '', static fn (Builder $w) => $w->where('status', $status))
            ->withCount('variants')
            ->withSum(['stocks as stock_quantity' => $liveVariant], 'quantity')
            ->withSum(['stocks as stock_reserved' => $liveVariant], 'reserved');

        // 在庫の無い商品は合計が NULL なので、0 と同じく一番少ない側に置く。同じ値の中は ID で順を決める
        $nulls = $column === 'stock_quantity' ? ($direction === 'asc' ? 'NULLS FIRST' : 'NULLS LAST') : 'NULLS LAST';
        $page = $query->orderByRaw("{$column} {$direction} {$nulls}")->orderBy('id')->paginate($request->perPage());

        $statusCounts = [];

        foreach (ListingStatus::cases() as $case) {
            $statusCounts[$case->value] = $counts->get($case->value, 0);
        }

        return response()->json([
            'listings' => collect($page->items())->map(static fn (Listing $listing): array => self::present($listing) + [
                'stock' => ['quantity' => (int) $listing->getAttribute('stock_quantity'), 'reserved' => (int) $listing->getAttribute('stock_reserved')],
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'counts' => ['all' => array_sum($statusCounts)] + $statusCounts,
        ]);
    }

    public function store(StoreListingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $seller = Seller::query()->find($data['seller_id']);

        if ($seller === null) {
            throw ValidationException::withMessages(['seller_id' => '販売者が見つかりません。']);
        }

        $listing = $this->guard(fn (): Listing => $this->listings->create(
            $seller,
            (string) $data['kind'],
            ListingRules::toChanges($data),
            TenantActor::of($request),
        ));

        return response()->json(['listing' => $this->detail($listing)], 201);
    }

    public function show(string $listingId): JsonResponse
    {
        return response()->json(['listing' => $this->detail($this->find($listingId))]);
    }

    public function update(UpdateListingRequest $request, string $listingId): JsonResponse
    {
        $listing = $this->find($listingId);
        $validated = $request->validated();

        try {
            $updated = $this->guard(fn (): Listing => $this->listings->update(
                $listing,
                (int) $validated['version'],
                ListingRules::toChanges($validated),
                TenantActor::of($request),
            ));
        } catch (StaleListingException $e) {
            return response()->json([
                'message' => '別の操作で先に更新されています。最新の内容を読み込み直してから、もう一度保存してください。',
                'current_version' => $e->currentVersion,
            ], 409);
        }

        return response()->json(['listing' => $this->detail($updated)]);
    }

    public static function find(string $listingId): Listing
    {
        return Listing::query()->findOrFail($listingId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Listing $listing): array
    {
        return [
            'id' => $listing->id,
            'seller_id' => $listing->seller_id,
            'kind' => $listing->kind,
            'title' => $listing->title,
            'status' => $listing->status->value,
            'unit_amount' => $listing->unit_amount,
            'currency' => $listing->currency,
            // 消費税の区分と率（ADR 0063。率は区分から核が決める）
            'tax_class' => $listing->tax_class->value,
            'tax_rate_bp' => $listing->taxRateBp(),
            'sku' => $listing->sku,
            // 種類の数（ADR 0036。軸の無い商品は 1）。一覧は withCount で先に数えてある
            'variant_count' => array_key_exists('variants_count', $listing->getAttributes())
                ? (int) $listing->getAttribute('variants_count')
                : $listing->variants()->count(),
            'updated_at' => $listing->updated_at?->toIso8601String(),
        ];
    }

    /**
     * 詳細: 編集に要る項目とバージョン、出品者の拠点ごとの在庫（在庫が無い拠点も 0 で並べる）。
     *
     * @return array<string, mixed>
     */
    public static function detail(Listing $listing): array
    {
        $variants = $listing->variants()->get();
        // 在庫は種類 × 拠点の行（ADR 0036）。商品の欄は拠点ごとに今の種類を合わせた数（消えた種類の行は数えない）、種類の欄はその種類の数
        $stocks = InventoryStock::query()->where('listing_id', $listing->id)->whereIn('variant_id', $variants->modelKeys())->get();
        $locations = Location::query()->where('seller_id', $listing->seller_id)->orderBy('name')->get();
        $stockRows = static fn (Collection $rows): Collection => $locations->map(static function (Location $location) use ($rows): array {
            $here = $rows->where('location_id', $location->id);

            return [
                'location_id' => $location->id,
                'location_name' => $location->name,
                'quantity' => (int) $here->sum('quantity'),
                'reserved' => (int) $here->sum('reserved'),
                'available' => (int) $here->sum(static fn (InventoryStock $stock): int => $stock->available()),
            ];
        })->values();

        // 選ぶ軸と値（軸の順・値の順）。種類の名前は値の ID から引く
        $options = ListingOption::query()->where('listing_id', $listing->id)->orderBy('position')->with('values')->get();
        $labels = [];

        foreach ($options as $option) {
            foreach ($option->values as $value) {
                $labels[$value->id] = $value->label;
            }
        }

        $blocks = ProductDescription::readBlocks($listing->description_blocks);
        $specs = ProductDescription::readSpecs($listing->specs);

        return self::present($listing) + [
            'description' => $listing->description,
            // 説明のブロック・仕様の表・短い説明（ADR 0032）。写真のブロックは写真の ID だけ（画面はショップの写真の一覧と合わせて見せる）
            'description_blocks' => $blocks,
            'specs' => $specs,
            // 保存済みの値に、今は断る文字や崩れたブロックがあり、外して見せている（画面が知らせる。説明を保存し直すまで保存済みの値はそのまま）
            'description_repaired' => ProductDescription::canonical($blocks) !== ProductDescription::canonical($listing->description_blocks)
                || ProductDescription::canonical($specs) !== ProductDescription::canonical($listing->specs),
            'summary' => $listing->summary,
            // 検索の結果に出す題と説明（ADR 0061。空なら自動）
            'seo_title' => $listing->seo_title,
            'seo_description' => $listing->seo_description,
            // 配送の方法（null はいちばん上の方法）と送料無料の商品（ADR 0064）
            'shipping_method_id' => $listing->shipping_method_id,
            'free_shipping' => $listing->free_shipping,
            'pricing' => $listing->pricing->value,
            // ショップのこの商品の画面（ショップに出ているときだけ。出す条件はショップと同じ。「ショップで見る」）
            'shop_url' => self::shopUrl($listing),
            // プレビューできる商品か（売る側の固定価格の商品だけ。「プレビュー」のボタン）
            'previewable' => Catalog::previewable($listing),
            'version' => $listing->version,
            'categories' => DB::table('listing_categories')->join('categories', 'categories.id', '=', 'listing_categories.category_id')
                ->where('listing_categories.listing_id', $listing->id)->orderByDesc('listing_categories.is_primary')->orderBy('categories.name') // 主カテゴリを先頭に
                ->get(['categories.id', 'categories.name', 'listing_categories.is_primary'])
                ->map(static fn (object $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name, 'primary' => (bool) $row->is_primary])->values(),
            'images' => ListingImage::query()->where('listing_id', $listing->id)->orderBy('position')->get()
                ->map(static fn (ListingImage $image): array => ListingImageController::present($image))->values(),
            'stocks' => $stockRows($stocks),
            'options' => $options->map(static fn (ListingOption $option): array => [
                'id' => $option->id,
                'name' => $option->name,
                'values' => $option->values->map(static fn (ListingOptionValue $value): array => ['id' => $value->id, 'label' => $value->label])->values(),
            ])->values(),
            // 種類（並び順）。価格が空なら商品の価格で売る。軸の無い商品は名前の無い種類が 1 つ
            'variants' => $variants->map(static fn (ListingVariant $variant): array => [
                'id' => $variant->id,
                'name' => implode(' / ', array_map(static fn (string $id): string => (string) ($labels[$id] ?? ''), $variant->valueIds())),
                'value_ids' => $variant->valueIds(),
                'sku' => $variant->sku,
                'unit_amount' => $variant->unit_amount,
                'status' => $variant->status->value,
                'image_id' => $variant->image_id,
                'stocks' => $stockRows($stocks->where('variant_id', $variant->id)),
            ])->values(),
            // オプション（名入れ・ギフト包装など。並び順）。額は「入れる文字」「付ける」の足す額、選ぶは選択肢ごとの額
            'addons' => ListingAddon::query()->where('listing_id', $listing->id)->orderBy('position')->get()->map(static fn (ListingAddon $addon): array => [
                'id' => $addon->id,
                'kind' => $addon->kind->value,
                'name' => $addon->name,
                'required' => $addon->required,
                'amount' => $addon->amount,
                'choices' => $addon->choices,
                'max_length' => $addon->max_length,
                'tax_class' => $addon->tax_class,
            ])->values(),
        ];
    }

    /**
     * ショップの商品の画面の URL。ショップに出ていない（下書き・終了・出品者が止まっている・0 円を売らない店の 0 円など）か、店のドメインが無ければ null。
     * 会員だけ・ランクだけの商品（ADR 0079）も、店の人には出ている商品として見せる（forShop）
     */
    private static function shopUrl(Listing $listing): ?string
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || ! app(Catalog::class)->forShop()->isPublished($listing->id)) {
            return null;
        }

        return StorefrontUrls::of($tenant, route('storefront.product', ['listingId' => $listing->id], false));
    }

    /**
     * 核の規則違反（固定価格なのに金額が無い等）と品番の重複を、入力の誤り（422）として返す。
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    private function guard(Closure $action): mixed
    {
        try {
            return $action();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'この販売者には、同じ品番の商品がすでにあります。']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }
    }
}
