<?php

declare(strict_types=1);

namespace App\Core\Sample;

/**
 * 入れたときのサンプル「植物のお店」。公式テーマ botanica に合わせた、観葉植物・多肉・園芸用品の 8 点。
 * 中身は開発の見本の店 MIDORI 植物店（BotanicaShopSeeder）と同じ（二重に持たない）。
 *
 * 写真は配る物に入れない。入れる時に Unsplash から取る（出どころは PHOTOS。images.unsplash.com の URL。撮った人は控えに無い）。
 * 写真は 5 枚を取り、商品 8 枚・ショップ 4 枚の 12 枚に使う（写真のアップロードの 1 分の上限 30 枚の内）。
 *
 * @phpstan-import-type ListingSpec from SampleSet
 * @phpstan-import-type PhotoSource from SampleSet
 */
final class BotanicaSamples implements SampleSet
{
    /** カテゴリ（slug => 名前） */
    public const array CATEGORIES = ['plants' => '観葉植物', 'succulents' => '多肉・サボテン', 'tools' => '園芸用品'];

    /**
     * 商品の表（名前 => [商品名, 価格, 写真, カテゴリ, 代わりの文字]）。どれも在庫 12。古い順に作る
     *
     * @var array<string, array{0: string, 1: int, 2: string, 3: string, 4: string}>
     */
    private const array PRODUCTS = [
        'mint-pot' => ['多肉植物 ミントポット', 2800, 'pot', 'succulents', 'ミント色の鉢の多肉植物'],
        'cactus-terracotta' => ['サボテン テラコッタ', 2200, 'cactus', 'succulents', 'テラコッタの鉢のサボテン'],
        'green-leaf' => ['グリーンリーフ', 3800, 'plant', 'plants', '大きな葉の観葉植物'],
        'garden-tools' => ['ガーデニングツールセット', 3200, 'garden', 'tools', '庭の手入れの道具'],
        'fern' => ['シダのグリーン', 2600, 'leaves', 'plants', 'シダの葉'],
        'succulent-starter' => ['多肉植物 スターターセット', 4800, 'pot', 'succulents', 'ミント色の鉢の多肉植物'],
        'cactus-gift' => ['サボテン ギフトセット', 3500, 'cactus', 'succulents', 'テラコッタの鉢のサボテン'],
        'leaf-gift' => ['リーフグリーン ギフト', 4500, 'plant', 'plants', '大きな葉の観葉植物'],
    ];

    /** ショップの写真（サンプルの中の名前 => [写真の名前, 代わりの文字]） */
    public const array MEDIA = [
        'hero' => ['pot', 'ミント色の鉢の多肉植物'],
        'look-plant' => ['plant', '大きな葉の観葉植物'],
        'look-cactus' => ['cactus', 'テラコッタの鉢のサボテン'],
        'seasonal' => ['leaves', 'シダの葉'],
    ];

    /** 送料（どのセットも同じ。消しても送料は残るので、別のセットへ替えても表記と合う） */
    public const array SHIPPING = ['fee' => 800, 'free_over' => 5000];

    /**
     * 写真の出どころ（images.unsplash.com の URL。撮った人とページは前の控えに無い）
     *
     * @var array<string, PhotoSource>
     */
    public const array PHOTOS = [
        'pot' => ['url' => 'https://images.unsplash.com/photo-1485955900006-10f4d324d411', 'author' => null, 'page' => null],
        'plant' => ['url' => 'https://images.unsplash.com/photo-1501004318641-b39e6451bec6', 'author' => null, 'page' => null],
        'cactus' => ['url' => 'https://images.unsplash.com/photo-1459411552884-841db9b3cc2a', 'author' => null, 'page' => null],
        'garden' => ['url' => 'https://images.unsplash.com/photo-1416879595882-3373a0480b5b', 'author' => null, 'page' => null],
        'leaves' => ['url' => 'https://images.unsplash.com/photo-1497250681960-ef046c08a56e', 'author' => null, 'page' => null],
    ];

    public function name(): string
    {
        return 'botanica';
    }

    public function label(): string
    {
        return '植物のお店';
    }

    public function theme(): string
    {
        return 'botanica';
    }

    public function bundled(): bool
    {
        return false;
    }

    public function categories(): array
    {
        return self::CATEGORIES;
    }

    public function listings(): array
    {
        $listings = [];

        foreach (self::PRODUCTS as $key => [$title, $price, $photo, $category, $alt]) {
            $listings[$key] = [
                'title' => "{$title}（サンプル）",
                'price' => $price,
                'category' => $category,
                'summary' => '暮らしの中に、小さな緑を。',
                'blocks' => [
                    ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                    ['type' => 'paragraph', 'text' => '暮らしの中に、小さな緑を。明るいお部屋に合わせて楽しむ植物と道具のセレクションです。'],
                ],
                'photos' => ['' => [$photo, $alt]],
                'sku' => 'SAMPLE-'.strtoupper($key),
                'stock' => 12,
            ];
        }

        return $listings;
    }

    public function media(): array
    {
        return self::MEDIA;
    }

    public function themeSections(): array
    {
        return [
            'announcement' => ['values' => ['text' => 'サンプルのショップです。商品はすべて見本で、実際には販売していません']],
            'hero' => ['values' => ['heading' => "暮らしに、\n小さな緑を。", 'body' => '植物のお店のサンプルです。商品・写真・文はすべて見本です。', 'image' => 'media:hero']],
            'looks' => ['items' => [
                ['title' => '葉のかたちを楽しむ', 'image' => 'media:look-plant', 'link' => '/c/plants'],
                ['title' => '小さなサボテン', 'image' => 'media:look-cactus', 'link' => '/c/succulents'],
            ]],
            'categories' => ['values' => ['list' => array_map(static fn (string $slug): string => "category:{$slug}", array_keys(self::CATEGORIES))]],
            // 新着の商品は見出しも既定のまま（並びのあるテーマでは、送らない種類の区画は並びから外れるので空でも送る）
            'new_arrivals' => [],
            'favorites' => ['values' => ['products' => ['source' => 'pick', 'ids' => ['listing:mint-pot', 'listing:cactus-terracotta', 'listing:green-leaf', 'listing:garden-tools']]]],
            'seasonal' => ['values' => [
                'label' => 'GREEN LIVING', 'heading' => "緑と暮らす、\n心地よい毎日。",
                'body' => '窓辺やデスクに、ひと鉢のグリーン。サンプルの文です。季節のおすすめを書きます。',
                'note' => '写真・商品はサンプルです。', 'image' => 'media:seasonal',
            ]],
            'promises' => ['items' => [
                ['icon' => 'shipping', 'title' => '植物を大切にお届け', 'body' => 'サンプルの文です。配送の決まりを書きます'],
                ['icon' => 'support', 'title' => '育て方のご相談', 'body' => 'サンプルの文です。お問い合わせの受け方を書きます'],
                ['icon' => 'returns', 'title' => '傷みがあったときは交換', 'body' => 'サンプルの文です。返品と交換の決まりを書きます'],
                ['icon' => 'secure', 'title' => 'カード情報は店に残りません', 'body' => '決済代行の画面でお支払い'],
            ]],
        ];
    }

    public function shipping(): array
    {
        return self::SHIPPING;
    }

    public function legal(): array
    {
        return [
            ['label' => '販売業者', 'value' => self::LEGAL_TODO.'お店の名前か、会社の名前を書きます'],
            ['label' => '運営責任者', 'value' => self::LEGAL_TODO.'責任者の名前を書きます'],
            ['label' => '所在地', 'value' => self::LEGAL_TODO.'お店の住所を書きます'],
            ['label' => '電話番号', 'value' => self::LEGAL_TODO.'お問い合わせを受ける電話番号を書きます'],
            ['label' => 'メールアドレス', 'value' => self::LEGAL_TODO.'お問い合わせを受けるメールアドレスを書きます'],
            ['label' => '販売価格', 'value' => '各商品のページに表示しています（税込み）'],
            ['label' => '商品代金以外の必要料金', 'value' => "送料 ¥800\n¥5,000 以上のご注文で送料無料"],
            ['label' => 'お支払いの方法と時期', 'value' => 'クレジットカード。ご注文の確定のとき'],
            ['label' => '引き渡しの時期', 'value' => 'ご注文から 3〜5 日で発送します'],
            ['label' => '返品・交換', 'value' => self::LEGAL_TODO."返品と交換の条件を書きます。\n例: 生き物のため、お客さまのご都合での返品はお受けしていません。傷みがあったときは、到着から 3 日以内にご連絡ください。"],
        ];
    }

    public function photos(): array
    {
        return self::PHOTOS;
    }
}
