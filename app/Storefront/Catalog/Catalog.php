<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Catalog\Models\Category;
use App\Core\Inventory\Services\ComponentStock;
use App\Core\Listing\Description\ProductDescription;
use App\Core\Listing\Enums\AddonKind;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\OfferSide;
use App\Core\Listing\Enums\Pricing;
use App\Core\Listing\Enums\SellerStatus;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Listing\ListingKinds;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingOption;
use App\Core\Listing\Models\ListingOptionValue;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Media\Models\ShopMedia;
use App\Core\Order\Preorders;
use App\Core\Plugin\Contracts\StockComponentsProvider;
use App\Core\Plugin\MemberBenefits;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Plugin\Services\ListingVisibility;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Plugin\Services\StockComponents;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Access\MemberPhotoLink;
use App\Storefront\Cart\CartService;
use App\Storefront\Members\Member;
use App\Storefront\View\ShopPhoto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * 店頭が商品を読む窓口（テナント文脈の中で使う）。テーマは DB に触らず、ここが返す ListingCard だけを表示する。
 *
 * 店頭に出すのは、公開中で、売る側の固定価格の商品だけ（下書き・終了・見積もりで決まる種類は出さない）。
 * 予約販売（ADR 0073）: 発売日の前の予約の商品は、在庫の代わりに予約の残り（上限の無い予約は UNLIMITED）を「利用可能な数」にし、札に発売日を添える。
 * 受付を終えた・上限に届いた予約は売り切れ。決まりは予約販売の App に聞く（使っていない店では何もしない）
 * 公開の予約と販売期間: App が「今は出さない」とした商品は、誰にも出さない（一覧・検索・商品の画面・サイトマップ・フィード。店の内側の処理〔forShop〕は読む）。
 * 「今は売らない」とした商品は売り切れとして出す（カートと注文の確定を断るのは売り方を押さえる口。ADR 0080）。
 * 会員だけ・ランクだけの商品（ADR 0079）: 会員の特典の App が「この人に見せない」とした商品は、一覧・検索・商品の画面・写真・カート・注文の確定から除く。
 * 見る人はログインした会員（ゲストは誰でもない）。フィードとサイトマップのように誰に見せるか分からない所は asGuest() で、いつもゲストとして読む。
 * 店の内側の処理（在庫の知らせ・再入荷の知らせの入荷の見方・管理画面の「ショップで見る」など）は forShop() で、見る人を問わずに読む（送る相手ごとの確かめは呼ぶ側で）。
 * 見てよい会員に描く会員だけの商品の写真には、写真の経路（セッションを持たない）で確かめられる署名（MemberPhotoLink）を付ける
 */
final class Catalog
{
    /** 上限の無い予約の「利用可能な数」（カートの 1 行の上限より十分に大きい数） */
    public const int UNLIMITED = 1_000_000;

    /** いつもゲストとして読む（asGuest） */
    private bool $guest = false;

    /** 見る人を問わずに読む（forShop。店の内側の処理） */
    private bool $shop = false;

    /**
     * 要求 => 見る人 => 見せない商品（同じ要求の中で読み直さない。経路が持ち続ける窓口でも、次の要求では読み直す）
     *
     * @var WeakMap<Request, array<string, list<string>>>|null
     */
    private ?WeakMap $hidden = null;

    public function __construct(
        private readonly ShopSettingsService $settings,
        private readonly Preorders $preorders,
        private readonly MemberBenefits $benefits,
        private readonly ListingVisibility $visibility,
    ) {}

    /**
     * ログインした会員に関わらず、ゲストに見せる商品だけを読む窓口（フィード・サイトマップ。ADR 0079。会員が開いた答えを外へ出さない）
     */
    public function asGuest(): self
    {
        $catalog = clone $this;
        $catalog->guest = true;

        return $catalog;
    }

    /**
     * 見る人を問わずに読む窓口（店の内側の処理。ADR 0079）。会員だけ・ランクだけの商品も、ほかの決まり（公開中・販売中など）に合えば読む。
     * お客さんに見せる・送る時は、呼ぶ側が相手ごとに MemberBenefits::hides() で確かめる
     */
    public function forShop(): self
    {
        $catalog = clone $this;
        $catalog->shop = true;

        return $catalog;
    }

    /**
     * ゲストにも見せる商品か（会員だけ・ランクだけの商品でない。ADR 0079。写真を共有のキャッシュに置いてよいかを決める）
     */
    public function forEveryone(string $listingId): bool
    {
        return ! in_array($listingId, $this->hiddenFor(null), true);
    }

    /**
     * 見る人に見せない商品（同じ要求の中では読み直さない）
     *
     * @return list<string>
     */
    private function hiddenFor(?string $viewer): array
    {
        $request = request();
        $this->hidden ??= new WeakMap;
        $known = $this->hidden[$request] ?? [];
        $hidden = $known[$viewer ?? ''] ??= $this->benefits->hiddenListings($viewer);
        $this->hidden[$request] = $known;

        return $hidden;
    }

    /**
     * 写真の URL に付ける署名: プレビューならその署名、見てよい会員に描く会員だけの商品なら会員の写真の署名（MemberPhotoLink）、ほかは無し
     *
     * @param  array<string, string>  $preview
     * @return array<string, string>
     */
    private function photoGrant(string $listingId, array $preview = []): array
    {
        if ($preview !== [] || $this->guest || $this->shop || tenant() === null || $this->forEveryone($listingId)) {
            return $preview;
        }

        return MemberPhotoLink::query((string) tenant()->getTenantKey(), $listingId);
    }

    /**
     * 見る人（ログインした会員の台帳。ゲスト・asGuest は null）
     */
    private function viewer(): ?string
    {
        if ($this->guest) {
            return null;
        }

        return Member::viewerId();
    }

    /**
     * @return list<ListingCard> 新しく更新された順
     */
    public function featured(int $limit): array
    {
        return $this->withTerms($this->cards($this->published())
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all());
    }

    public const int PER_PAGE = 24;

    /** 札（ListingCard）に要る列 */
    private const array CARD_COLUMNS = ['listings.id', 'listings.kind', 'listings.title', 'listings.unit_amount', 'listings.currency', 'listings.summary', 'listings.tax_class'];

    public const int MAX_PAGE = 500;

    /**
     * 一覧: 公開中の商品を、カテゴリ（下の段も含む）・検索語（商品名）・並べ替え・絞り込みで絞り、ページに分ける
     *
     * @param  ?CatalogFilters  $filters  App「並び順と絞り込み」が決めた並びと、お客さんが選んだ絞り込み（使わない店では null）
     * @return array{items: list<ListingCard>, total: int, lastPage: int}
     */
    public function browse(?Category $category, ?string $query, string $sort, int $page, ?CatalogFilters $filters = null): array
    {
        $builder = $this->published();

        if ($category !== null) {
            $builder->whereIn('id', DB::table('listing_categories')->select('listing_id')->whereIn('category_id', $this->withDescendants($category)));
        }

        if ($query !== null) {
            // LIKE の記号は文字として扱う
            $builder->whereRaw('title ILIKE ?', ['%'.addcslashes($query, '\\%_').'%']);
        }

        $this->narrow($builder, $filters);
        $total = (clone $builder)->count();
        // 同じ値どうしは ID で並びを決める（ULID は作った順。新着順は同じ時刻でも新しい方が先）。
        // 価格の順は札の値段（販売中の種類の一番安い価格。ADR 0036）で並べる。高い順も同じ値で（利用者の決定、2026-10-30）。
        // $sort は ProductController が選択肢の名前に絞ったもので、SQL には入れない（ここで決まった式を選ぶだけ）
        match ($sort) {
            'price_asc' => $builder->orderBy($this->sortAmount())->orderBy('listings.id'),
            'price_desc' => $builder->orderByDesc($this->sortAmount())->orderBy('listings.id'),
            ProductListView::SHOP_SORT => self::shopOrder($builder, $filters->order ?? []),
            default => $builder->orderByDesc('created_at')->orderByDesc('listings.id'),
        };

        $items = $this->withTerms($this->cards($builder)
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get()
            ->all());

        return ['items' => $items, 'total' => $total, 'lastPage' => max(1, (int) ceil($total / self::PER_PAGE))];
    }

    /**
     * カートに入れられる商品: 公開中で固定価格のものだけ。在庫は「利用可能な数」（数そのものは店頭に出さない）
     *
     * @param  list<string>  $listingIds
     * @return array<string, array{card: ListingCard, available: int}>
     */
    public function purchasable(array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        $listings = $this->cards($this->published()
            ->whereIn('id', array_values(array_filter($listingIds, static fn (string $id): bool => preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1))))
            ->get();
        $terms = $this->preorders->terms($listings->pluck('id')->all());
        // 自分の在庫を持たない商品（セット商品）の分も、まとめて先に聞く
        $this->prefetchBuildable($listings->pluck('id')->all());

        return $listings->mapWithKeys(function (Listing $listing) use ($terms): array {
            $available = $this->available($listing, $terms[$listing->id] ?? null);

            return [$listing->id => ['card' => $this->card($listing, $available, preorder: $terms[$listing->id] ?? null), 'available' => $available]];
        })->all();
    }

    /**
     * カートに入れられる種類（ADR 0036）: 公開中で固定価格の商品の、販売中の種類だけ。価格は種類（空なら商品）、在庫はその種類の「利用可能な数」。
     * カードの名前には種類の名前（「赤 / M」）を添える。preorder は予約の商品の発売日（ADR 0073。予約でなければ null）。
     * 予約の商品の available は在庫でなく予約の残りなので、在庫を見る App（再入荷のお知らせ・在庫の少ない知らせ）は preorder で除く
     *
     * @param  list<string>  $variantIds
     * @return array<string, array{card: ListingCard, available: int, listingId: string, kind: string, preorder: string|null, digital: bool, inPerson: bool}>
     */
    public function purchasableVariants(array $variantIds): array
    {
        $variantIds = array_values(array_filter($variantIds, static fn (string $id): bool => preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1));

        if ($variantIds === []) {
            return [];
        }

        $variants = ListingVariant::query()
            ->whereIn('id', $variantIds)
            ->where('status', VariantStatus::Active)
            ->whereIn('listing_id', $this->published()->select('id'))
            ->withSum('stocks as stock_quantity', 'quantity')
            ->withSum('stocks as stock_reserved', 'reserved')
            ->get();
        $listings = $this->cards($this->published()->whereIn('id', $variants->pluck('listing_id')->unique()->values()->all()))->get()->keyBy('id');
        // App が足した種類は、その App を使っている店で、App が今売れると答えた商品だけ（ファイルの無いダウンロードの商品は売らない）
        $sellable = array_flip(self::sellable($listings->all()));
        $listings = $listings->filter(static fn (Listing $listing): bool => isset($sellable[$listing->id]));
        // 予約の商品（ADR 0073）は、在庫の代わりに予約の残り（商品ごと。種類違いで分け合う）
        $terms = $this->preorders->terms($listings->keys()->all());
        // 「今は売らない商品」も、カートの行の分をまとめて先に聞く（見せ方だけ。断るのは売り方の押さえ）
        $this->visibility->unsellable($listings->keys()->all());
        // 自分の在庫を持たない商品（セット商品）の分も、まとめて先に聞く
        $this->prefetchBuildable($listings->keys()->all());
        $result = [];

        foreach ($variants as $variant) {
            $listing = $listings->get($variant->listing_id);
            $price = $listing === null ? null : $variant->price($listing);

            // 0 円の種類は、店の設定で売ると決めた店だけ
            if ($listing === null || $price === null || ($price === 0 && ! $this->settings->sellsFreeItems())) {
                continue;
            }

            $term = $terms[$listing->id] ?? null;
            // 送らない品（ダウンロードなど）は在庫を使わず、1 回の注文で 1 点まで
            $available = self::digital($listing) ? 1 : $this->available($variant, $term);
            $label = $variant->label();
            $result[$variant->id] = [
                'card' => $this->card($listing, $available, $price, $label === '' ? $listing->title : $listing->title.'（'.$label.'）', preorder: $term),
                'available' => $available,
                'listingId' => $listing->id,
                // 商品の扱い（App が足した種類はカートに入れさせないことがある）
                'kind' => $listing->kind,
                'preorder' => $term?->releaseDate,
                // 送らない品（ダウンロードなど。送料・お届け先・在庫を使わない）
                'digital' => self::digital($listing),
                // 役務（設置の代行など。受渡は in_person。ほかの品と同じ注文に入れない）
                'inPerson' => self::inPerson($listing),
            ];
        }

        return $result;
    }

    /**
     * 種類を選ばずにカートへ入れたときの種類: 公開中の軸の無い商品の、ただ 1 つの種類（種類のある商品は null。選ばせる）
     */
    public function soleVariantId(string $listingId): ?string
    {
        if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $listingId) !== 1) {
            return null;
        }

        return $this->published()->whereKey($listingId)->first()?->soleVariant()?->id;
    }

    /**
     * 商品がショップに出ているか（管理画面の「ショップで見る」）。出す条件は一覧・詳細と同じ
     */
    public function isPublished(string $listingId): bool
    {
        return $this->published()->whereKey($listingId)->exists();
    }

    /**
     * 商品の詳細。公開中の商品だけ（無ければ null）
     */
    public function product(string $listingId): ?ProductView
    {
        return $this->detail($this->published(), $listingId);
    }

    /**
     * プレビューできる商品か: 売る側の固定価格の商品だけ（買取の商品・見積もりで決まる商品はショップの商品の画面に出ない）
     */
    public static function previewable(Listing $listing): bool
    {
        return $listing->offer_side === OfferSide::Sell && $listing->pricing === Pricing::Fixed;
    }

    /**
     * プレビューの商品の詳細。その商品だけを、状態（下書き・終了）・出品者の止まり・0 円に関係なく描く。
     * 売る側の固定価格の商品だけ（previewable と同じ。買取の商品などは null）。
     * 種類は今のショップと同じ描き方（止めた種類・価格の無い種類は出さない）。写真の URL にはプレビューの署名を付ける
     * （公開していない商品の写真は、署名の合うときだけ配る。MediaController）。署名を確かめるのは呼ぶ側（ProductController::preview）
     *
     * @param  array<string, string>  $preview  ListingPreviewLink::query()
     */
    public function preview(string $listingId, array $preview): ?ProductView
    {
        return $this->detail(Listing::query()->where('offer_side', OfferSide::Sell)->where('pricing', Pricing::Fixed), $listingId, $preview);
    }

    /**
     * プレビューの写真を ID で探す（公開に関係なく）。署名を照らすのは呼ぶ側で、写真の持ち主の商品の ID で照らす
     */
    public function previewImage(string $imageId): ?ListingImage
    {
        return ListingImage::query()->whereKey($imageId)->first();
    }

    /**
     * @param  Builder<Listing>  $query
     * @param  array<string, string>  $preview  プレビューの署名（写真の URL に付ける。ふだんは空）
     */
    private function detail(Builder $query, string $listingId, array $preview = []): ?ProductView
    {
        $listing = $this->withVariantPrices($query
            ->whereKey($listingId)
            ->withSum('stocks as stock_quantity', 'quantity')
            ->withSum('stocks as stock_reserved', 'reserved')
            ->with(['images' => static fn ($q) => $q->where('position', 0)]))
            ->first();

        if ($listing === null) {
            return null;
        }

        $photos = ListingImage::query()->where('listing_id', $listing->id)->orderBy('position')->get()
            ->map(fn (ListingImage $image): ListingPhoto => new ListingPhoto($image->id, $image->alt ?? $listing->title, $image->width, $image->height, $this->photoGrant($listing->id, $preview)))
            ->values()->all();

        $breadcrumbs = [];
        $primary = $listing->categories()->wherePivot('is_primary', true)->first();

        if ($primary instanceof Category) {
            [$ancestors] = $this->categoryPath($primary);
            $breadcrumbs = array_map(static fn (Category $c): array => ['name' => $c->name, 'url' => route('storefront.category', ['slug' => $c->slug], false)], [...$ancestors, $primary]);
        }

        $term = $this->preorders->terms([$listing->id])[$listing->id] ?? null;
        $available = self::digital($listing) ? 1 : (self::stockless($listing) ? CartService::MAX_QUANTITY : $this->available($listing, $term));
        // 今は売れない App の種類の商品（App を止めた店・ファイルの無いダウンロードの商品）は売り切れとして出す（カートと確定も断る）。
        // App が「今は売らない」とした商品（販売の終わりの日時を過ぎた商品など）も、ボタンを「売り切れ」にする
        $unsellable = self::sellable([$listing]) === [] || $this->visibility->unsellable([$listing->id]) !== [];
        $available = $unsellable ? 0 : $available;
        [$options, $variants] = $this->variantsOf($listing, $photos, $term, $unsellable);

        // 種類を選ばせる商品は、入れられる数の上限を種類ごとに持つ。商品の上限は、いちばん多く入れられる種類に合わせる（どれも売り切れなら 0）
        $maxQuantity = $variants === []
            ? min(CartService::MAX_QUANTITY, $available)
            : max(array_map(static fn (ProductVariant $v): int => $v->maxQuantity, $variants));

        $blocks = ProductDescription::readBlocks($listing->description_blocks);

        // 説明の素の文は、読んだブロック（断る文字を外したもの）からつなぐ。保存済みの写しに向きを変える印などが残っていても出さない
        return new ProductView($this->card($listing, $available, preview: $preview, preorder: $term, unsellable: $unsellable), ProductDescription::plainText($blocks), $photos, $breadcrumbs, route('storefront.product', ['listingId' => $listing->id]),
            $maxQuantity, $this->descriptionBlocks($blocks), ProductDescription::readSpecs($listing->specs), $options, $variants, $this->addonsOf($listing));
    }

    /**
     * 商品のオプション（名入れ・ギフト包装など。ADR 0036）。並び順に。額は見せるためだけ（カートに入れるときに核が定義と照らし直す）
     *
     * @return list<ProductAddon>
     */
    private function addonsOf(Listing $listing): array
    {
        return ListingAddon::query()->where('listing_id', $listing->id)->orderBy('position')->get()
            ->map(static fn (ListingAddon $addon): ProductAddon => new ProductAddon(
                $addon->id,
                $addon->kind->value,
                $addon->name,
                $addon->required,
                $addon->amount,
                array_map(static fn (array $choice): array => ['label' => (string) $choice['label'], 'amount' => (int) $choice['amount']], $addon->choices ?? []),
                $addon->kind === AddonKind::Text ? ($addon->max_length ?? ListingAddon::MAX_TEXT_LENGTH) : null,
                $listing->currency,
            ))
            ->values()->all();
    }

    /**
     * 商品の詳細の軸と種類（ADR 0036）。軸の無い商品は両方とも空。
     * 種類は販売中のものだけ（売り切れも含めて出し、選べない形にする）。価格の無い・0 円を売らない店での 0 円の種類は出さない（カートに入れられない）。
     * 軸の値は、出す種類のどれかが使うものだけ
     *
     * @param  list<ListingPhoto>  $photos  商品の写真（種類の写真はこの中から）
     * @param  ?PreorderTerms  $term  予約の商品の決まり（ADR 0073。種類の在庫の代わりに予約の残りを見せる）
     * @return array{0: list<array{name: string, values: list<array{id: string, label: string}>}>, 1: list<ProductVariant>}
     */
    private function variantsOf(Listing $listing, array $photos, ?PreorderTerms $term = null, bool $unsellable = false): array
    {
        $options = ListingOption::query()->where('listing_id', $listing->id)->orderBy('position')->with('values')->get();

        if ($options->isEmpty()) {
            return [[], []];
        }

        $labels = [];

        foreach ($options as $option) {
            foreach ($option->values as $value) {
                $labels[$value->id] = $value->label;
            }
        }

        $photosById = [];

        foreach ($photos as $photo) {
            $photosById[$photo->id] = $photo;
        }

        $variants = [];
        $used = [];

        $rows = ListingVariant::query()
            ->where('listing_id', $listing->id)
            ->where('status', VariantStatus::Active)
            ->orderBy('position')
            ->orderBy('id')
            ->withSum('stocks as stock_quantity', 'quantity')
            ->withSum('stocks as stock_reserved', 'reserved')
            ->get();

        foreach ($rows as $row) {
            $price = $row->price($listing);
            $valueIds = $row->valueIds();

            // 0 円の種類は、店の設定で売ると決めた店だけ。軸の値の欠けた種類は選べないので出さない
            if ($price === null || ($price === 0 && ! $this->settings->sellsFreeItems()) || $valueIds === [] || array_diff($valueIds, array_keys($labels)) !== []) {
                continue;
            }

            $available = $unsellable ? 0 : (self::digital($listing) ? 1 : $this->available($row, $term));
            // 送らない品は在庫の具合を見ずに「在庫あり」（入れられる数だけ 1）
            [$availability, $remaining] = self::availability(self::digital($listing) && ! $unsellable ? CartService::MAX_QUANTITY : $available);
            $variants[] = new ProductVariant($row->id, $valueIds, implode(' / ', array_map(static fn (string $id): string => $labels[$id], $valueIds)),
                $price, $listing->currency, $availability, $remaining, min(CartService::MAX_QUANTITY, $available),
                $row->image_id === null ? null : ($photosById[$row->image_id] ?? null), $term?->releaseDate);
            $used += array_fill_keys($valueIds, true);
        }

        $result = [];

        foreach ($options as $option) {
            $values = $option->values->filter(static fn (ListingOptionValue $v): bool => isset($used[$v->id]))
                ->map(static fn (ListingOptionValue $v): array => ['id' => $v->id, 'label' => $v->label])->values()->all();
            $result[] = ['name' => $option->name, 'values' => $values];
        }

        return [$result, $variants];
    }

    /**
     * 説明のブロック（ADR 0032）。写真がショップの写真に無い写真のブロックは描かない
     *
     * @param  list<array<string, mixed>>  $blocks  ProductDescription::readBlocks の後
     * @return list<DescriptionBlock>
     */
    private function descriptionBlocks(array $blocks): array
    {
        $ids = ProductDescription::mediaIds($blocks);
        $photos = $ids === [] ? [] : ShopMedia::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(static fn (ShopMedia $m): array => [$m->id => new ShopPhoto($m->id, $m->width, $m->height, $m->alt)])
            ->all();

        return array_values(array_filter(array_map(static fn (array $block): ?DescriptionBlock => DescriptionBlock::from($block, $photos), $blocks)));
    }

    /**
     * @return array{0: list<Category>, 1: list<Category>} [一番上から親まで, 下の段]
     */
    public function categoryPath(Category $category): array
    {
        $ancestors = [];
        $parentId = $category->parent_id;

        while ($parentId !== null && count($ancestors) < Category::MAX_DEPTH) {
            $parent = Category::query()->find($parentId);
            if ($parent === null) {
                break;
            }
            array_unshift($ancestors, $parent);
            $parentId = $parent->parent_id;
        }

        return [$ancestors, $category->children()->get()->all()];
    }

    /**
     * 選んだ商品の札を、選んだ順に（公開中のものだけ）
     *
     * @param  list<string>  $ids
     * @return list<ListingCard>
     */
    public function picked(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = $this->cards($this->published()->whereIn('id', $ids))->get()->keyBy('id');
        $cards = [];

        foreach ($ids as $id) {
            if ($found->has($id)) {
                $cards[] = $this->card($found->get($id));
            }
        }

        return $cards;
    }

    /**
     * カテゴリ（下の段も含む）の公開中の商品の札を、新しく更新された順に
     *
     * @return list<ListingCard>
     */
    public function inCategory(string $categoryId, int $limit): array
    {
        $category = Category::query()->find($categoryId);

        if ($category === null) {
            return [];
        }

        return $this->cards($this->published()
            ->whereIn('id', DB::table('listing_categories')->select('listing_id')->whereIn('category_id', $this->withDescendants($category))))
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Listing $listing): ListingCard => $this->card($listing))
            ->values()
            ->all();
    }

    /**
     * 札に要る列（説明のブロックや仕様の表は読まない。ADR 0032）と、在庫の数と 1 枚目の写真を一緒に読む
     *
     * @param  Builder<Listing>  $query
     * @return Builder<Listing>
     */
    private function cards(Builder $query): Builder
    {
        return $this->withVariantPrices($query
            ->select(self::CARD_COLUMNS)
            ->withSum('stocks as stock_quantity', 'quantity')
            ->withSum('stocks as stock_reserved', 'reserved')
            ->with(['images' => static fn ($q) => $q->where('position', 0)]));
    }

    /**
     * 販売中の種類の一番安い価格と一番高い価格（ADR 0036。札の「¥4,800〜」）。種類に価格が無ければ商品の価格。
     * 0 円を売らない店では、0 円の種類を数えない（カートに入れられない）
     *
     * @param  Builder<Listing>  $query
     * @return Builder<Listing>
     */
    private function withVariantPrices(Builder $query): Builder
    {
        return $query->addSelect([
            'variant_min_amount' => $this->variantPrices('MIN('.self::VARIANT_PRICE.')'),
            'variant_max_amount' => $this->variantPrices('MAX('.self::VARIANT_PRICE.')'),
        ]);
    }

    /**
     * 絞り込みを一覧に効かせる。列は核が決めた決まった列だけで、お客さんの入力からは作らない
     * （値段の帯は札の値段と同じ式、タグは App が返した商品の ID、在庫は在庫の表。CatalogFilters が選択肢に合う値だけを持つ）
     *
     * @param  Builder<Listing>  $builder
     */
    private function narrow(Builder $builder, ?CatalogFilters $filters): void
    {
        if ($filters === null) {
            return;
        }

        if ($filters->band !== null) {
            $this->whereAmount($builder, '>=', $filters->band->min);

            if ($filters->band->max !== null) {
                $this->whereAmount($builder, '<=', $filters->band->max);
            }
        }

        // タグを選んだのに商品が無ければ、1 件も出さない（whereIn の空は 0 = 1）
        if ($filters->tag !== null) {
            $builder->whereIn('listings.id', $filters->taggedIds);
        }

        if ($filters->inStock) {
            // 在庫の数を使わない種類（予約の品・ダウンロード・役務）は、札と同じく「在庫あり」として残す
            $stockless = app(ListingKinds::class)->stocklessNames();
            $builder->where(static fn (Builder $query): Builder => $query
                ->whereExists(static fn (QueryBuilder $stocks): QueryBuilder => $stocks
                    ->from('inventory_stocks')
                    ->whereColumn('inventory_stocks.listing_id', 'listings.id')
                    ->whereColumn('inventory_stocks.quantity', '>', 'inventory_stocks.reserved'))
                ->when($stockless !== [], static fn (Builder $q): Builder => $q->orWhereIn('listings.kind', $stockless)));
        }
    }

    /**
     * 札の値段（販売中の種類の一番安い価格。価格の順と同じ式）と金額を比べる。向きは核のコードの中の決まった文字だけ
     *
     * @param  Builder<Listing>  $builder
     * @param  '<='|'>='  $operator
     */
    private function whereAmount(Builder $builder, string $operator, int $amount): void
    {
        $sub = $this->sortAmount();
        $builder->whereRaw('('.$sub->toSql().') '.$operator.' ?', [...$sub->getBindings(), $amount]);
    }

    /**
     * 店が手で決めた並び。決めた商品を先頭から順に、残りは新着順。
     * 並びは App が返した商品の ID の配列（CatalogBrowse が形と数〔MAX_ORDER〕を確かめたもの）で、SQL には値として渡す
     *
     * @param  Builder<Listing>  $builder
     * @param  list<string>  $order
     */
    private static function shopOrder(Builder $builder, array $order): void
    {
        if ($order !== []) {
            $builder->orderByRaw('COALESCE(array_position(?::text[], listings.id::text), 2147483647)', ['{'.implode(',', $order).'}']);
        }

        $builder->orderByDesc('created_at')->orderByDesc('listings.id');
    }

    /** 種類の価格（空なら商品の価格） */
    private const string VARIANT_PRICE = 'COALESCE(listing_variants.unit_amount, listings.unit_amount)';

    /**
     * 一覧の価格の順に使う値: 札の値段と同じ（販売中の種類の一番安い価格。数えられる種類が無ければ商品の価格。card() と合わせる）
     *
     * @return Builder<ListingVariant>
     */
    private function sortAmount(): Builder
    {
        return $this->variantPrices('COALESCE(MIN('.self::VARIANT_PRICE.'), listings.unit_amount)');
    }

    /**
     * 商品ごとの販売中の種類の価格を集める副問い合わせ（外の listings の行を指す）。0 円を売らない店では、0 円の種類を数えない
     *
     * @param  string  $expression  決まった式だけ（外から来た値を入れない）
     * @return Builder<ListingVariant>
     */
    private function variantPrices(string $expression): Builder
    {
        return ListingVariant::query()
            ->selectRaw($expression)
            ->whereColumn('listing_variants.listing_id', 'listings.id')
            ->where('listing_variants.status', VariantStatus::Active->value)
            ->when(! $this->settings->sellsFreeItems(), static fn (Builder $q) => $q->whereRaw(self::VARIANT_PRICE.' > 0'));
    }

    /**
     * 「カテゴリから探す」の札。選んだカテゴリを順に、選んでいなければ一番上の段を並び順に。
     * 写真は、カテゴリの代表の写真が公開中の商品のものならそれ、無ければそのカテゴリ（下の段も含む）の公開中の商品の 1 枚目。写真の無いカテゴリは出さない
     *
     * @param  list<string>  $chosen
     * @return list<array{label: string, slug: string, photo: ListingPhoto}>
     */
    public function categoryTiles(array $chosen, int $limit = 8): array
    {
        $categories = $chosen === []
            ? Category::query()->whereNull('parent_id')->orderBy('position')->limit($limit)->get()
            : Category::query()->whereIn('id', $chosen)->get()->sortBy(static fn (Category $c): int => (int) array_search($c->id, $chosen, true))->values();

        $tiles = [];

        foreach ($categories as $category) {
            $image = $category->image_id === null ? null : ListingImage::query()
                ->whereKey($category->image_id)
                ->whereIn('listing_id', $this->published()->select('id'))
                ->first();

            $image ??= ListingImage::query()
                ->where('position', 0)
                ->whereIn('listing_id', $this->published()->select('id')
                    ->whereIn('id', DB::table('listing_categories')->select('listing_id')->whereIn('category_id', $this->withDescendants($category))))
                ->orderByDesc('created_at')
                ->orderBy('id')
                ->first();

            if ($image !== null) {
                $tiles[] = ['label' => $category->name, 'slug' => $category->slug, 'photo' => new ListingPhoto($image->id, '', $image->width, $image->height, $this->photoGrant($image->listing_id))];
            }
        }

        return array_slice($tiles, 0, $limit);
    }

    /**
     * @return list<string> 自分と下の段（3 段まで）の ID
     */
    private function withDescendants(Category $category): array
    {
        $ids = [$category->id];
        $level = [$category->id];

        for ($i = 1; $i < Category::MAX_DEPTH && $level !== []; $i++) {
            $level = Category::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * 公開中の商品の写真（店頭で配ってよいものだけ）
     */
    public function publishedImage(string $imageId): ?ListingImage
    {
        return ListingImage::query()
            ->whereKey($imageId)
            ->whereIn('listing_id', $this->published()->select('id'))
            ->first();
    }

    /**
     * ショップに出す商品（一覧・詳細・サイトマップ〔ADR 0061〕で同じ条件。会員だけ・ランクだけの商品は見る人で変わる。ADR 0079）
     *
     * @return Builder<Listing>
     */
    public function published(): Builder
    {
        // 会員だけ・ランクだけの商品（ADR 0079）は、見せない人には無い商品と同じ（一覧・商品の画面・カート・注文の確定）。
        // App が「今は出さない」とした商品（公開の日時の前の商品など）も、誰にも出さない（店の内側の処理〔forShop〕は読む）
        $hidden = $this->shop ? [] : [...$this->hiddenFor($this->viewer()), ...$this->visibility->hidden()];

        // 出品者が止まっていれば出さない（注文を作るときに断られるので、見えるのに買えない、を防ぐ）
        return Listing::query()
            ->when($hidden !== [], static fn (Builder $query) => $query->whereNotIn('listings.id', $hidden))
            ->where('status', ListingStatus::Active)
            ->where('offer_side', OfferSide::Sell)
            ->where('pricing', Pricing::Fixed)
            ->whereNotNull('unit_amount')
            ->whereIn('seller_id', Seller::query()->where('status', SellerStatus::Active)->select('id'))
            // 0 円の品は、店の設定で売ると決めた店だけ
            ->when(! $this->settings->sellsFreeItems(), static fn (Builder $query) => $query->where('unit_amount', '>', 0));
    }

    /**
     * @param  int|null  $price  決まった価格（カートの行の種類）。無ければ販売中の種類の一番安い価格（読んでいなければ商品の価格）
     * @param  array<string, string>  $preview  プレビューの署名（写真の URL に付ける。ふだんは空）
     * @param  ?PreorderTerms  $preorder  予約の商品の決まり（ADR 0073。札に発売日を添える）
     */
    private function card(Listing $listing, ?int $available = null, ?int $price = null, ?string $title = null, array $preview = [], ?PreorderTerms $preorder = null, bool $unsellable = false): ListingCard
    {
        $this->prefetchBuildable([$listing->id]);
        // 自分の在庫を持たない商品（セット商品）は、構成する品から作れる数
        $available ??= $this->buildable[$listing->id] ?? max(0, (int) $listing->getAttribute('stock_quantity') - (int) $listing->getAttribute('stock_reserved'));
        // App が「今は売らない」とした商品（販売の終わりの日時を過ぎた商品など）も売り切れとして出す
        // （カートと注文の確定を断るのは売り方を押さえる口。見せ方と断りで同じ決まりを見る）
        $unsellable = $unsellable || $this->visibility->unsellable([$listing->id]) !== [];
        // 今は売れない App の種類の商品は、在庫の数を使わない種類でも売り切れ
        [$availability, $remaining] = self::availability($unsellable ? 0 : (self::stockless($listing) ? CartService::MAX_QUANTITY : $available));

        $image = $listing->images->first();
        $photo = $image === null ? null : new ListingPhoto($image->id, $image->alt ?? $listing->title, $image->width, $image->height, $this->photoGrant($listing->id, $preview));

        $min = $listing->getAttribute('variant_min_amount');
        $max = $listing->getAttribute('variant_max_amount');
        $varies = $price === null && $min !== null && $max !== null && (int) $min !== (int) $max;
        $price ??= $min !== null ? (int) $min : (int) $listing->unit_amount;

        return new ListingCard($listing->id, $title ?? $listing->title, $price, $listing->currency, $availability, $remaining, $photo,
            ProductDescription::readSummary($listing->summary), $varies, $listing->taxRateBp(), $preorder?->releaseDate);
    }

    /**
     * 札の並び（一覧・新着）。予約の商品（ADR 0073）は予約の残りと発売日で
     *
     * @param  list<Listing>  $listings
     * @return list<ListingCard>
     */
    private function withTerms(array $listings): array
    {
        $ids = array_map(static fn (Listing $l): string => $l->id, $listings);
        $terms = $this->preorders->terms($ids);
        // 「今は売らない商品」は札ごとに聞くので、一覧の分をまとめて先に聞く（要求の中で覚える）
        $this->visibility->unsellable($ids);
        // 自分の在庫を持たない商品（セット商品）の分も、まとめて先に聞く
        $this->prefetchBuildable($ids);

        return array_map(fn (Listing $listing): ListingCard => $this->card($listing, $this->available($listing, $terms[$listing->id] ?? null), preorder: $terms[$listing->id] ?? null), $listings);
    }

    /**
     * 「利用可能な数」: 在庫の数 − 押さえた数。予約の商品（ADR 0073）は在庫によらず予約の残り（上限が無ければ UNLIMITED、受付を終えていれば 0）
     *
     * @param  Listing|ListingVariant  $row  stock_quantity・stock_reserved を読んだ行
     */
    private function available(Listing|ListingVariant $row, ?PreorderTerms $term): int
    {
        if ($term !== null) {
            return $term->acceptable() ?? self::UNLIMITED;
        }

        $listingId = $row instanceof ListingVariant ? $row->listing_id : $row->id;
        $this->prefetchBuildable([$listingId]);

        // 自分の在庫を持たない商品（セット商品）は、構成する品から作れる数
        return $this->buildable[$listingId] ?? max(0, (int) $row->getAttribute('stock_quantity') - (int) $row->getAttribute('stock_reserved'));
    }

    /**
     * 構成する品から作れる数（商品の ID => 数）。構成を持たない商品は入らない
     *
     * @var array<string, int>
     */
    private array $buildable = [];

    /**
     * もう聞いた商品（構成を持たないものも覚える。同じ要求の中で聞き直さない）
     *
     * @var array<string, true>
     */
    private array $askedBuildable = [];

    /**
     * 構成する品から作れる数を、一覧の分までまとめて聞いておく（**商品ごとに App へ聞かないため**）。
     * 「今は売らない商品」を先に聞くのと同じ形
     *
     * @param  list<string>  $listingIds
     */
    private function prefetchBuildable(array $listingIds): void
    {
        $ask = array_values(array_diff(array_unique($listingIds), array_keys($this->askedBuildable)));

        if ($ask === []) {
            return;
        }

        foreach (array_chunk($ask, StockComponentsProvider::MAX_LISTINGS) as $chunk) {
            $this->askedBuildable += array_fill_keys($chunk, true);

            try {
                $components = app(StockComponents::class)->forMany($chunk);
            } catch (Throwable $e) {
                // 構成を読めない時は、商品そのものの在庫で見る（セット商品は自分の在庫を持たないので売り切れになる）。
                // ショップの一覧ごと落とさず、売ってしまわない側に倒す。注文の確定は核が必ず止める（StockComponents）
                Log::error('在庫の構成を読めなかった', ['error' => $e::class]);

                continue;
            }

            $this->buildable += app(ComponentStock::class)->totals($components);
        }
    }

    /**
     * 在庫の数を使わない種類の商品か（予約の品など。売れる数は App が持つので、在庫の具合を見ずに「在庫あり」として出す）
     */
    private static function stockless(Listing $listing): bool
    {
        $kind = $listing->getAttribute('kind');

        return is_string($kind) && app(ListingKinds::class)->stockless($kind);
    }

    /**
     * 今売れる商品の ID（見直し）: App が足した種類は、その App を使っている店で、App の口（sellable）が売れると答えた商品だけ。
     * 核の種類はいつも売れる
     *
     * @param  array<Listing>  $listings
     * @return list<string>
     */
    private static function sellable(array $listings): array
    {
        $kinds = app(ListingKinds::class);
        $states = app(PluginStates::class);
        $byKind = [];

        foreach ($listings as $listing) {
            $byKind[(string) $listing->getAttribute('kind')][] = $listing->id;
        }

        $out = [];

        foreach ($byKind as $kind => $ids) {
            $plugin = $kinds->plugin($kind);

            if ($plugin !== null && ! $states->enabled($plugin)) {
                continue;
            }

            array_push($out, ...$kinds->sellable($kind, $ids));
        }

        return $out;
    }

    /**
     * 送らない品の種類の商品か（ダウンロードなど。在庫を使わず、1 回の注文で 1 点まで）
     */
    private static function digital(Listing $listing): bool
    {
        $kind = $listing->getAttribute('kind');

        return is_string($kind) && app(ListingKinds::class)->digital($kind);
    }

    private static function inPerson(Listing $listing): bool
    {
        $kind = $listing->getAttribute('kind');

        return is_string($kind) && app(ListingKinds::class)->inPerson($kind);
    }

    /**
     * 在庫の具合（数そのものは low_stock のときの残りだけ）
     *
     * @return array{0: string, 1: int|null}
     */
    private static function availability(int $available): array
    {
        $low = (int) config('storefront.low_stock');

        return match (true) {
            $available === 0 => [ListingCard::SOLD_OUT, null],
            $available <= $low => [ListingCard::LOW_STOCK, $available],
            default => [ListingCard::IN_STOCK, null],
        };
    }
}
