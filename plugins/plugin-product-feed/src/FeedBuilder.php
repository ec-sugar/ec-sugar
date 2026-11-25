<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

use App\Core\Listing\Models\ListingVariant;
use App\Core\Plugin\Services\PluginSettings;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Catalog\ListingCard;
use App\Storefront\Catalog\ListingPhoto;
use App\Storefront\Catalog\ProductView;
use EcSugar\PluginProductFeed\Models\FeedListing;

/**
 * フィードの行を集める（店の文脈の中で使う）。出すのはショップに出ている商品だけで、読むのは核のショップの窓口（Catalog）から
 * （下書き・終了・非公開・見積もりで決まる商品・止めた出品者の商品・止めた種類は、ショップの商品の画面と同じ決まりで入らない）。
 * 種類のある商品は種類ごとに 1 行（id は種類の ID、item_group_id は商品の ID）、無い商品は商品の ID で 1 行。
 * 店の人が外した商品・写真の無い商品・0 円の商品は出さず、管理画面に理由と一緒に見せる。行は MAX_ITEMS まで
 */
final class FeedBuilder
{
    /** 1 つのフィードの行の上限（ホストする店でも、作る時間とキャッシュの大きさが膨らまないように） */
    public const int MAX_ITEMS = 5000;

    /** 追加の写真の上限（Google は 10 枚まで） */
    public const int MAX_ADDITIONAL_IMAGES = 10;

    // 文の長さの上限（両方の行き先の小さい方。Google の商品名は 150 文字、説明は 5,000 文字、ブランドと MPN は 70 文字、色とサイズは 100 文字）
    private const int TITLE_MAX = 150;

    private const int DESCRIPTION_MAX = 5000;

    private const int BRAND_MAX = 70;

    private const int MPN_MAX = 70;

    private const int VARIANT_VALUE_MAX = 100;

    /** 色・サイズとして出す軸の名前（小文字にして比べる。ほかの軸は商品名に添えるだけ） */
    private const array COLOR_AXES = ['色', 'カラー', 'いろ', 'color', 'colour'];

    private const array SIZE_AXES = ['サイズ', '大きさ', 'size'];

    /** 出せない理由（管理画面に出す） */
    public const array REASONS = [
        'no_photo' => '写真がありません（Google と Meta は写真が要ります）',
        'free' => '0 円の商品は出せません',
        'no_variant' => '販売中の種類がありません',
    ];

    /** ゲストとして読む窓口（会員だけ・ランクだけの商品を、会員が開いた時もフィードに出さない。ADR 0079） */
    private readonly Catalog $catalog;

    public function __construct(
        Catalog $catalog,
        private readonly PluginSettings $settings,
    ) {
        $this->catalog = $catalog->asGuest();
    }

    /**
     * ショップに出ている商品から、フィードの行を集める
     */
    public function build(): FeedResult
    {
        $defaults = $this->defaults();
        $rows = FeedListing::query()->get()->keyBy('listing_id');
        $items = [];
        $listings = 0;
        $excluded = [];
        $skipped = [];
        $over = 0;
        $page = 1;

        do {
            $result = $this->catalog->browse(null, null, 'newest', $page);

            foreach ($result['items'] as $card) {
                /** @var ?FeedListing $row */
                $row = $rows->get($card->id);

                if ($row?->excluded === true) {
                    $excluded[$card->id] = $card->title;

                    continue;
                }

                if (count($items) >= self::MAX_ITEMS) {
                    $over++;

                    continue;
                }

                $made = $this->itemsOf($card->id, $row, $defaults);

                if ($made['reason'] !== null) {
                    $skipped[$card->id] = ['title' => $card->title, 'reason' => $made['reason']];

                    continue;
                }

                $room = self::MAX_ITEMS - count($items);

                if (count($made['items']) > $room) {
                    $over++;

                    continue;
                }

                $items = [...$items, ...$made['items']];
                $listings++;
            }

            $page++;
        } while ($page <= min($result['lastPage'], Catalog::MAX_PAGE));

        return new FeedResult($items, $listings, $excluded, $skipped, $over);
    }

    /**
     * 1 つの商品がフィードにどう出るか（管理画面の商品の画面の案内）。出るなら行の数、出ないなら理由の文
     *
     * @return array{rows: int, reason: ?string}
     */
    public function describe(string $listingId): array
    {
        if (! $this->catalog->isPublished($listingId)) {
            return ['rows' => 0, 'reason' => 'ショップに公開していない商品は、フィードに出ません。'];
        }

        /** @var ?FeedListing $row */
        $row = FeedListing::query()->where('listing_id', $listingId)->first();

        if ($row?->excluded === true) {
            return ['rows' => 0, 'reason' => '「フィードに出さない」にしているので、フィードに出ません。'];
        }

        $made = $this->itemsOf($listingId, $row, $this->defaults());

        return $made['reason'] !== null ? ['rows' => 0, 'reason' => self::REASONS[$made['reason']].'。フィードに出ません。'] : ['rows' => count($made['items']), 'reason' => null];
    }

    /**
     * 1 つの商品の行（出せなければ理由の印）
     *
     * @param  array{brand: ?string, category: ?string}  $defaults
     * @return array{items: list<FeedItem>, reason: ?string}
     */
    private function itemsOf(string $listingId, ?FeedListing $row, array $defaults): array
    {
        $product = $this->catalog->product($listingId);

        if ($product === null) {
            return ['items' => [], 'reason' => 'no_variant'];
        }

        if ($product->photos === []) {
            return ['items' => [], 'reason' => 'no_photo'];
        }

        $card = $product->card;
        $brand = self::text($row->brand ?? $defaults['brand'] ?? '', self::BRAND_MAX);
        $category = $row->google_product_category ?? $defaults['category'];
        $condition = $row !== null && isset(FeedListing::CONDITIONS[$row->condition]) ? $row->condition : 'new';
        /** @var array<string, ?string> $skus */
        $skus = $row?->mpn_from_sku === true ? ListingVariant::query()->where('listing_id', $listingId)->orderBy('position')->pluck('sku', 'id')->all() : [];
        // 写真の URL はショップのホストからの完全な形（読みに来るサービスが取りに行く）
        $photos = array_map(static fn (ListingPhoto $photo): string => url($photo->url('large')), $product->photos);
        $description = self::description($product);
        $base = [
            'description' => $description,
            'link' => $product->url,
            'currency' => $card->currency,
            'brand' => $brand === '' ? null : $brand,
            'condition' => $condition,
            'category' => $category,
        ];

        // 軸があるのに販売中の種類が無い商品は、カートに入れられない（ショップの商品の画面でも選べない）
        if ($product->options !== [] && $product->variants === []) {
            return ['items' => [], 'reason' => 'no_variant'];
        }

        if ($product->variants === []) {
            if ($card->price <= 0) {
                return ['items' => [], 'reason' => 'free'];
            }

            $mpn = $skus === [] ? null : self::mpn(reset($skus));

            return ['items' => [self::item($listingId, null, $card->title, $photos[0], array_slice($photos, 1), $card->availability !== ListingCard::SOLD_OUT, $card->price, $mpn, null, null, [], $base, $card->preorder)], 'reason' => null];
        }

        $axes = self::axes($product);
        $items = [];

        foreach ($product->variants as $variant) {
            if ($variant->price <= 0) {
                continue;
            }

            [$color, $size, $attributes] = self::variantAttributes($variant->valueIds, $axes);
            $main = $variant->photo === null ? $photos[0] : url($variant->photo->url('large'));
            $others = array_values(array_filter($photos, static fn (string $url): bool => $url !== $main));
            $items[] = self::item($variant->id, $listingId, $card->title.'（'.$variant->label.'）', $main, $others, ! $variant->soldOut(), $variant->price,
                self::mpn($skus[$variant->id] ?? null), $color, $size, $attributes, $base, $card->preorder);
        }

        return $items === [] ? ['items' => [], 'reason' => 'free'] : ['items' => $items, 'reason' => null];
    }

    /**
     * @param  list<string>  $others
     * @param  array<string, string>  $attributes
     * @param  array{description: string, link: string, currency: string, brand: ?string, condition: string, category: ?string}  $base
     * @param  ?string  $preorder  予約の商品の発売日（ADR 0073。在庫の言葉を preorder にし、発売日を添える）
     */
    private static function item(string $id, ?string $group, string $title, string $image, array $others, bool $inStock, int $price, ?string $mpn,
        ?string $color, ?string $size, array $attributes, array $base, ?string $preorder = null): FeedItem
    {
        return new FeedItem(
            id: $id,
            itemGroupId: $group,
            title: self::text($title, self::TITLE_MAX),
            description: $base['description'],
            link: $base['link'],
            imageLink: $image,
            additionalImageLinks: array_slice($others, 0, self::MAX_ADDITIONAL_IMAGES),
            inStock: $inStock,
            price: $price,
            currency: $base['currency'],
            brand: $base['brand'],
            condition: $base['condition'],
            mpn: $mpn,
            color: $color,
            size: $size,
            googleProductCategory: $base['category'],
            attributes: $attributes,
            availabilityDate: $preorder,
        );
    }

    /**
     * App の設定の既定（ブランドは空なら店の名前）
     *
     * @return array{brand: ?string, category: ?string}
     */
    private function defaults(): array
    {
        $values = $this->settings->publicValues(ProductFeedServiceProvider::NAME) ?? [];
        $brand = is_string($values['brand'] ?? null) ? $values['brand'] : (string) tenant()?->getAttribute('name');
        $category = is_string($values['google_product_category'] ?? null) ? $values['google_product_category'] : null;

        return ['brand' => $brand === '' ? null : $brand, 'category' => $category];
    }

    /**
     * 説明（ブロックの文をつないだもの。無ければ短い説明、それも無ければ商品名）
     */
    private static function description(ProductView $product): string
    {
        foreach ([$product->description, $product->card->summary, $product->card->title] as $text) {
            $clean = self::text(str_replace("\r\n", "\n", (string) $text), self::DESCRIPTION_MAX, keepLines: true);

            if ($clean !== '') {
                return $clean;
            }
        }

        return '';
    }

    /**
     * 軸の値の ID => [軸の名前, 値の名前]
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function axes(ProductView $product): array
    {
        $out = [];

        foreach ($product->options as $option) {
            foreach ($option['values'] as $value) {
                $out[$value['id']] = [$option['name'], $value['label']];
            }
        }

        return $out;
    }

    /**
     * 種類の色・サイズ・そのほかの軸
     *
     * @param  list<string>  $valueIds
     * @param  array<string, array{0: string, 1: string}>  $axes
     * @return array{0: ?string, 1: ?string, 2: array<string, string>}
     */
    private static function variantAttributes(array $valueIds, array $axes): array
    {
        $color = null;
        $size = null;
        $others = [];

        foreach ($valueIds as $id) {
            if (! isset($axes[$id])) {
                continue;
            }

            [$axis, $label] = $axes[$id];
            $name = mb_strtolower(trim($axis));
            $value = self::text($label, self::VARIANT_VALUE_MAX);

            if ($value === '') {
                continue;
            }

            match (true) {
                $color === null && in_array($name, self::COLOR_AXES, true) => $color = $value,
                $size === null && in_array($name, self::SIZE_AXES, true) => $size = $value,
                default => $others[self::text($axis, self::VARIANT_VALUE_MAX)] = $value,
            };
        }

        return [$color, $size, $others];
    }

    private static function mpn(mixed $sku): ?string
    {
        $text = is_string($sku) ? self::text($sku, self::MPN_MAX) : '';

        return $text === '' ? null : $text;
    }

    /**
     * XML に書けない文字（制御文字・U+FFFE など）を外し、前後の空白を落として長さに収める。改行を残さない文は空白 1 つにまとめる
     */
    public static function text(string $text, int $max, bool $keepLines = false): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{20}-\x{7E}\x{A0}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        $clean = $keepLines ? (preg_replace("/[ \t]+/u", ' ', $clean) ?? '') : (preg_replace('/\s+/u', ' ', $clean) ?? '');
        $clean = trim($clean);

        return mb_strlen($clean) > $max ? rtrim(mb_substr($clean, 0, $max - 1)).'…' : $clean;
    }
}
