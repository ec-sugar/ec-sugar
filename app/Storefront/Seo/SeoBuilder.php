<?php

declare(strict_types=1);

namespace App\Storefront\Seo;

use App\Core\Catalog\Models\Category;
use App\Core\Listing\Models\Listing;
use App\Core\Media\Models\ShopMedia;
use App\Core\Plugin\ProductPanel;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Section\Models\ShopPage;
use App\Core\Shop\Services\SeoSettingsService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Storefront\Catalog\ListingCard;
use App\Storefront\Catalog\ProductListView;
use App\Storefront\Catalog\ProductVariant;
use App\Storefront\Catalog\ProductView;

/**
 * 画面ごとの検索と共有の見え方を作る（ADR 0061）。検索に載せるのは決めた画面だけ（トップ・商品・一覧・自由なページ・特商法の表記）で、
 * ほかの画面（書き忘れも）は載せない。準備中のショップはすべて載せない。
 *
 * 題は「ページの題 | 店の名前」（トップは店が入れた題か店の名前だけ）。説明は店が入れたもの、無ければ自動（ADR 0061 の 2・3）
 */
final class SeoBuilder
{
    /** 自動の説明の長さ */
    public const int AUTO_DESCRIPTION = 120;

    /** JSON-LD の Product に載せる写真の数 */
    private const int MAX_IMAGES = 10;

    private const array AVAILABILITY = [
        ListingCard::IN_STOCK => 'https://schema.org/InStock',
        ListingCard::LOW_STOCK => 'https://schema.org/LimitedAvailability',
        ListingCard::SOLD_OUT => 'https://schema.org/OutOfStock',
    ];

    /** @var array{home_title: string|null, home_description: string|null, share_image_id: string|null}|null */
    private ?array $settings = null;

    public function __construct(
        private readonly SeoSettingsService $seo,
        private readonly StorefrontAccessService $access,
    ) {}

    /**
     * テーマの画面（render）の見え方。載せる画面でなければ hidden
     *
     * @param  array<string, mixed>  $data  画面に渡すデータ
     */
    public function forPage(string $page, array $data, int $status, string $shopName): SeoMeta
    {
        if ($status !== 200) {
            return SeoMeta::hidden($shopName);
        }

        $meta = match ($page) {
            'home' => $this->home($shopName),
            'product' => ($data['product'] ?? null) instanceof ProductView ? $this->product($data['product'], $data['productPanels'] ?? [], $shopName) : null,
            'products' => ($data['list'] ?? null) instanceof ProductListView ? $this->list($data['list'], $shopName) : null,
            'legal' => $this->simple('特定商取引法に基づく表記', route('storefront.legal'), $shopName),
            // プライバシーポリシー（ADR 0094）。本文がある時だけ描くので、載せてよい
            'privacy' => $this->simple('プライバシーポリシー', route('storefront.privacy'), $shopName),
            default => null,
        };

        return $this->gate($meta ?? SeoMeta::hidden($shopName));
    }

    /**
     * 公開した自由なページ（ADR 0039 区切り 3）
     */
    public function forFreePage(ShopPage $page, string $shopName): SeoMeta
    {
        $title = $page->seo_title ?? $page->title;

        return $this->gate(new SeoMeta(
            index: true,
            siteName: $shopName,
            title: $title.SeoMeta::SEPARATOR.$shopName,
            shareTitle: $title,
            description: $page->seo_description ?? $this->shopDescription($shopName),
            canonical: route('storefront.page', ['slug' => $page->slug]),
            image: $this->shareImage(),
        ));
    }

    /**
     * App の検索に載せるページ（ADR 0072。お知らせ・ブログの一覧と記事など）。題・説明・canonical は App が渡し、店の名前・共有の写真の既定・
     * 準備中の扱い・構造化データの形は核が決める。説明が無ければ店の説明、写真が無い（店の写真に無い）なら店の共有の写真。
     * 記事（$article）なら og:type を article にし、Article の構造化データ（見出し・日時・写真・店）を足す。パンくずはトップの後ろに続ける
     *
     * @param  string  $canonical  このページの絶対の URL
     * @param  ?string  $imageId  共有の写真（ショップの写真の ID）
     * @param  list<array{name: string, url: string}>  $breadcrumbs  トップより後ろのパンくず（URL は道筋か絶対の URL）
     */
    public function forAppPage(string $title, ?string $description, string $canonical, ?string $imageId = null, ?SeoArticle $article = null, array $breadcrumbs = []): SeoMeta
    {
        $shopName = (string) tenant()?->getAttribute('name');
        $media = $imageId === null ? null : ShopMedia::query()->whereKey($imageId)->first(['id', 'width', 'height', 'alt']);
        $image = $media === null ? $this->shareImage() : [
            'url' => route('storefront.media.shop', ['mediaId' => $media->id, 'variant' => 'large']),
            'width' => $media->width,
            'height' => $media->height,
            'alt' => $media->alt,
        ];
        $description = self::excerpt($description) ?? $this->shopDescription($shopName);
        $data = [];

        if ($article !== null) {
            $data[] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => mb_substr($title, 0, 110),
                'description' => $description,
                'url' => $canonical,
                'mainEntityOfPage' => $canonical,
                'datePublished' => $article->published->format(DATE_ATOM),
                'dateModified' => $article->modified->format(DATE_ATOM),
                'articleSection' => $article->section,
                'image' => $image === null ? null : [$image['url']],
                'author' => ['@type' => 'Organization', 'name' => $shopName, 'url' => route('storefront.home')],
                'publisher' => ['@type' => 'Organization', 'name' => $shopName, 'url' => route('storefront.home')],
            ], static fn (mixed $v): bool => $v !== null);
        }

        if ($breadcrumbs !== []) {
            $data[] = $this->breadcrumbs($breadcrumbs);
        }

        return $this->gate(new SeoMeta(
            index: true,
            siteName: $shopName,
            title: $title.SeoMeta::SEPARATOR.$shopName,
            shareTitle: $title,
            description: $description,
            canonical: $canonical,
            type: $article === null ? 'website' : 'article',
            image: $image,
            data: $data,
        ));
    }

    private function home(string $shopName): SeoMeta
    {
        $title = $this->settings()['home_title'] ?? $shopName;
        $description = $this->shopDescription($shopName);
        $url = route('storefront.home');
        $image = $this->shareImage();

        return new SeoMeta(
            index: true,
            siteName: $shopName,
            title: $title,
            shareTitle: $title,
            description: $description,
            canonical: $url,
            image: $image,
            data: [array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'OnlineStore',
                'name' => $shopName,
                'url' => $url,
                'description' => $description,
                'image' => $image['url'] ?? null,
            ], static fn (mixed $v): bool => $v !== null)],
        );
    }

    /**
     * @param  mixed  $panels  App の開いて読む並び（平均の星を持つものがあれば AggregateRating に）
     */
    private function product(ProductView $product, mixed $panels, string $shopName): SeoMeta
    {
        $card = $product->card;
        $listing = Listing::query()->whereKey($card->id)->first(['id', 'seo_title', 'seo_description']);
        $title = $listing->seo_title ?? $card->title;
        $description = $listing->seo_description ?? $card->summary ?? self::excerpt($product->description) ?? $this->shopDescription($shopName);
        $photo = $product->photos[0] ?? null;
        $image = $photo === null
            ? $this->shareImage()
            : ['url' => url($photo->url('large')), 'width' => $photo->width, 'height' => $photo->height, 'alt' => $photo->alt];

        $item = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $card->title,
            'description' => $description,
            'url' => $product->url,
            'image' => array_map(static fn ($p): string => url($p->url('large')), array_slice($product->photos, 0, self::MAX_IMAGES)),
            'offers' => $this->offers($product),
        ];

        // 品番は種類が 1 つの商品だけ（ADR 0036。種類ごとの品番は種類を選ぶまで決まらない）
        if (! $product->hasVariants()) {
            $sku = Listing::query()->find($card->id)?->sku;

            if (is_string($sku) && $sku !== '') {
                $item['sku'] = $sku;
            }
        }

        if ($item['image'] === []) {
            unset($item['image']);
        }

        foreach (is_array($panels) ? $panels : [] as $panel) {
            if ($panel instanceof ProductPanel && $panel->rating !== null) {
                $item['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => $panel->rating->average(),
                    'reviewCount' => $panel->rating->count,
                    'bestRating' => 5,
                    'worstRating' => 1,
                ];

                break;
            }
        }

        $trail = [...$product->breadcrumbs, ['name' => $card->title, 'url' => $product->url]];

        return new SeoMeta(
            index: true,
            siteName: $shopName,
            title: $title.SeoMeta::SEPARATOR.$shopName,
            shareTitle: $title,
            description: $description,
            canonical: $product->url,
            type: 'product',
            image: $image,
            data: [$item, $this->breadcrumbs($trail)],
        );
    }

    /**
     * 価格と在庫（Offer）。種類で価格が違えば AggregateOffer（最安と最高・種類の数）
     *
     * @return array<string, mixed>
     */
    private function offers(ProductView $product): array
    {
        $card = $product->card;
        $availability = self::AVAILABILITY[$card->availability] ?? self::AVAILABILITY[ListingCard::SOLD_OUT];
        // 予約を受けている予約の商品（ADR 0073）は PreOrder と、売り始める日（発売日の日本の 0 時）
        $preorder = $card->preorder !== null && $card->availability !== ListingCard::SOLD_OUT;
        $starts = $preorder ? ['availabilityStarts' => $card->preorder.'T00:00:00+09:00'] : [];
        $availability = $preorder ? 'https://schema.org/PreOrder' : $availability;

        if ($product->hasVariants() && $product->pricesVary()) {
            $prices = array_map(static fn (ProductVariant $v): int => $v->price, $product->variants);

            return [
                '@type' => 'AggregateOffer',
                'priceCurrency' => strtoupper($card->currency),
                'lowPrice' => min($prices),
                'highPrice' => max($prices),
                'offerCount' => count($prices),
                'availability' => $availability,
                ...$starts,
                'url' => $product->url,
            ];
        }

        return [
            '@type' => 'Offer',
            'priceCurrency' => strtoupper($card->currency),
            'price' => $card->price,
            'availability' => $availability,
            ...$starts,
            'url' => $product->url,
        ];
    }

    private function list(ProductListView $list, string $shopName): SeoMeta
    {
        $slug = $list->category['slug'] ?? null;
        $category = $slug === null ? null : Category::query()->where('slug', $slug)->first(['id', 'name', 'slug', 'seo_title', 'seo_description']);
        $base = $category === null ? route('storefront.products') : route('storefront.category', ['slug' => $category->slug]);
        // 並び替えと検索を外した URL（2 ページ目からはページの番号を残す。ページごとに中身が違うため）
        $canonical = $list->page > 1 ? $base.'?page='.$list->page : $base;
        $name = $category === null ? 'すべての商品' : $category->name;
        $title = $category->seo_title ?? $name;
        $description = $category->seo_description ?? "{$shopName}の{$name}の一覧です。";
        $data = [];

        if ($category !== null) {
            $data[] = $this->breadcrumbs([...$list->breadcrumbs, ['name' => $category->name, 'url' => $base]]);
        }

        $meta = new SeoMeta(
            index: true,
            siteName: $shopName,
            title: ($list->query === null ? $title : $list->title()).SeoMeta::SEPARATOR.$shopName,
            shareTitle: $title,
            description: $description,
            canonical: $canonical,
            image: $this->shareImage(),
            data: $data,
        );

        // 検索の結果は載せない（中身は検索語で変わり、同じ商品の重なりになる）
        return $list->query === null ? $meta : $meta->withoutIndex();
    }

    private function simple(string $name, string $url, string $shopName): SeoMeta
    {
        return new SeoMeta(
            index: true,
            siteName: $shopName,
            title: $name.SeoMeta::SEPARATOR.$shopName,
            shareTitle: $name,
            description: $this->shopDescription($shopName),
            canonical: $url,
            image: $this->shareImage(),
        );
    }

    /**
     * パンくず（BreadcrumbList）。先頭にトップ
     *
     * @param  list<array{name: string, url: string}>  $trail
     * @return array<string, mixed>
     */
    private function breadcrumbs(array $trail): array
    {
        $items = [['name' => 'ホーム', 'url' => route('storefront.home')], ...$trail];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(static fn (int $i, array $crumb): array => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => url($crumb['url']),
            ], array_keys($items), $items),
        ];
    }

    /**
     * 準備中のショップとデモの店（ADR 0086）は、どの画面も載せない
     */
    private function gate(SeoMeta $meta): SeoMeta
    {
        return $meta->index && ($this->access->status() !== ShopStatus::Open || DemoShop::active()) ? $meta->withoutIndex() : $meta;
    }

    private function shopDescription(string $shopName): string
    {
        return $this->settings()['home_description'] ?? "{$shopName}のオンラインストアです。";
    }

    /**
     * @return array{url: string, width: int, height: int, alt: string|null}|null
     */
    private function shareImage(): ?array
    {
        if ($this->settings()['share_image_id'] === null) {
            return null;
        }

        $media = $this->seo->shareImage();

        return $media === null ? null : [
            'url' => route('storefront.media.shop', ['mediaId' => $media->id, 'variant' => 'large']),
            'width' => $media->width,
            'height' => $media->height,
            'alt' => $media->alt,
        ];
    }

    /**
     * @return array{home_title: string|null, home_description: string|null, share_image_id: string|null}
     */
    private function settings(): array
    {
        return $this->settings ??= $this->seo->get();
    }

    private static function excerpt(?string $text): ?string
    {
        return SeoMeta::excerpt($text, self::AUTO_DESCRIPTION);
    }
}
