<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Catalog\Models\Category;
use App\Core\Discount\Services\AutomaticDiscounts;
use App\Core\Order\AgeChecks;
use App\Core\Plugin\CatalogBrowse;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Storefront\Access\ListingPreviewLink;
use App\Storefront\Cart\AddonInputFlash;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Catalog\CatalogFilters;
use App\Storefront\Catalog\ProductListView;
use App\Storefront\Catalog\ProductPanels;
use App\Storefront\Catalog\PurchaseOptions;
use App\Storefront\Members\Member;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 店頭の商品の一覧と詳細。すべての商品・カテゴリごと・1 つの商品。公開中の商品だけ。
 * 入力はゆるく受ける（知らない並べ替えは新着順、数字でないページは 1）。ページは 500 まで（それより先は「見つからない」）。
 */
final class ProductController
{
    private const int MAX_QUERY = 100;

    public function __construct(
        private readonly StorefrontView $view,
        private readonly Catalog $catalog,
    ) {}

    public function index(Request $request): Response
    {
        return $this->list($request, null, route('storefront.products', [], false));
    }

    public function category(Request $request, string $slug): Response
    {
        $category = Category::query()->where('slug', $slug)->first();

        if ($category === null) {
            return $this->view->render('error', ['status' => 404], 404);
        }

        return $this->list($request, $category, route('storefront.category', ['slug' => $category->slug], false));
    }

    public function show(Request $request, string $listingId): Response|RedirectResponse
    {
        $product = $this->catalog->product($listingId);

        if ($product === null) {
            return $this->view->render('error', ['status' => 404], 404);
        }

        // カートに入れるのを断られて戻ったときだけ、入れていたオプションの値を欄に戻す（次の 1 回だけ。暗号化して flash で来る）
        if ($product->hasAddons()) {
            $product = $product->withAddonValues(AddonInputFlash::unwrap($request->session()->get(AddonInputFlash::KEY), $listingId));
        }

        // App の買い方（定期便など。ADR 0050）。テーマは核の部品 <x-storefront::purchase-options :options="$purchaseOptions" /> で置く。
        // App の開いて読む並び（よくある質問など）。テーマは説明の下に <x-storefront::product-panel :panel="…" /> で置く。
        // この商品に今使える自動の割引の名前（ADR 0065。会員だけの割引はログインした会員にだけ。会員がもう使い切った割引は出さない）。テーマは <x-storefront::product-discounts :names="$productDiscounts" /> で置く
        // 付くポイント（ADR 0068。今の値段 × 店の率の切り捨て。会員には数、ゲストには「会員なら」）。テーマは <x-storefront::product-points :points="$productPoints" /> で置く
        // 年齢の確かめが要る商品（ADR 0077）: 「20 歳未満の方はお求めいただけません」。テーマは <x-storefront::age-notice :age="$productAge" /> で置く。
        // 店が年齢の門を使うなら、まだ答えていない人を最初の表示で門の画面へ（答えは Cookie だけ。記録は残さない）
        $ages = app(AgeChecks::class);
        $productAge = $ages->forListing($product->card->id);
        $gate = $productAge === null ? null : $ages->gate($productAge, route('storefront.product', ['listingId' => $product->card->id], false), $request);

        if ($gate !== null) {
            return redirect($gate);
        }

        $member = $request->user('customer');
        $isMember = $member instanceof Member && $member->belongsHere() && PointService::isMember($member->account);
        // 会員には特典の上乗せ（ADR 0079）を足した率で、ゲストの「会員なら」は店の率で
        $rate = $isMember ? app(PointSettings::class)->rateBpFor($member->account->id) : app(PointSettings::class)->activeRateBp();

        return $this->view->render('product', [
            'productPoints' => $rate > 0 ? ['member' => $isMember, 'points' => PointService::earnFor($product->card->price, $rate)] : null,
            'productAge' => $productAge,
            'product' => $product,
            'purchaseOptions' => app(PurchaseOptions::class)->forListing($listingId),
            'productPanels' => app(ProductPanels::class)->forListing($listingId),
            'productDiscounts' => app(AutomaticDiscounts::class)->forListing($product->card->id, $member instanceof Member && $member->belongsHere() ? $member->account->id : null),
        ]);
    }

    /**
     * 商品のプレビュー。管理画面が作った署名つきの期限つきのリンク（ListingPreviewLink）で、その商品だけを状態に関係なく描く。
     * 準備中のショップでも関所の外（RequireShopAccess の一覧）。署名が違う・期限切れ・ほかの店・ほかの商品は、開いているショップでは
     * 「見つかりません」、準備中のショップでは関所と同じく「準備中です」へ（どちらも中身を出さない。どれで断ったかも見せない）
     */
    public function preview(Request $request, StorefrontAccessService $access, string $listingId): Response|RedirectResponse
    {
        $tenantId = (string) tenant()?->getTenantKey();
        $expires = $request->query('expires');
        $token = $request->query('token');
        $product = ListingPreviewLink::grants($tenantId, $listingId, $expires, $token) && is_string($expires) && is_string($token)
            ? $this->catalog->preview($listingId, ['expires' => $expires, 'token' => $token])
            : null;

        if ($product === null) {
            $refused = $access->status() === ShopStatus::Open
                ? $this->view->render('error', ['status' => 404], 404)
                : redirect()->route('storefront.password');
            $refused->headers->set('X-Robots-Tag', StorefrontView::NO_INDEX);

            return $refused;
        }

        // App の開いて読む並び（よくある質問など）も、公開した時と同じに見せる
        return $this->view->renderPreview('product', ['product' => $product, 'productPanels' => app(ProductPanels::class)->forListing($listingId)]);
    }

    private function list(Request $request, ?Category $category, string $baseUrl): Response
    {
        // 並べ替えの選択肢と絞り込み（App「並び順と絞り込み」を使う店だけ増える）。知らない値はここで落ちる
        $filters = CatalogFilters::from($request, app(CatalogBrowse::class));
        $sort = $request->query('sort');
        $sort = is_string($sort) && array_key_exists($sort, $filters->sorts()) ? $sort : $filters->defaultSort();
        $page = $request->query('page');
        $page = is_string($page) && ctype_digit($page) && (int) $page >= 1 ? (int) $page : 1;
        $query = $request->query('q');
        $query = is_string($query) && trim($query) !== '' ? mb_substr(trim($query), 0, self::MAX_QUERY) : null;

        if ($page > Catalog::MAX_PAGE) {
            return $this->view->render('error', ['status' => 404], 404);
        }

        ['items' => $items, 'total' => $total, 'lastPage' => $lastPage] = $this->catalog->browse($category, $query, $sort, $page, $filters);
        $breadcrumbs = [];
        $children = [];

        if ($category !== null) {
            [$ancestors, $below] = $this->catalog->categoryPath($category);
            $link = static fn (Category $c): array => ['name' => $c->name, 'url' => route('storefront.category', ['slug' => $c->slug], false)];
            $breadcrumbs = array_map($link, $ancestors);
            $children = array_map($link, $below);
        }

        return $this->view->render('products', ['list' => new ProductListView(
            $items, $total, $page, $lastPage, $sort, $query,
            $category === null ? null : ['name' => $category->name, 'slug' => $category->slug],
            $breadcrumbs, $children, $baseUrl, $filters,
        )]);
    }
}
