<?php

declare(strict_types=1);

namespace App\Core\Sample;

/**
 * 入れたときのサンプル「布ものと道具のお店」。公式テーマ fashion に合わせた、エプロン・マグ・帆布のトートの 3 点。
 * 中身は開発のデモの店（DemoTenantSeeder）と同じ（二重に持たない）。種類（色・サイズ）とオプションの見本を兼ねる（ADR 0036）。
 *
 * 写真は配る物に入れない。入れる時に Unsplash から取る（出どころは PHOTOS）。
 * 写真は商品 11 枚・説明の写真 1 枚・ショップ 5 枚の 17 枚（写真のアップロードの 1 分の上限 30 枚の内）。
 *
 * @phpstan-import-type ListingSpec from SampleSet
 * @phpstan-import-type PhotoSource from SampleSet
 */
final class FashionSamples implements SampleSet
{
    /** カテゴリ（slug => 名前） */
    public const array CATEGORIES = ['kitchen' => 'キッチン', 'bags' => 'バッグ'];

    /**
     * 商品。軸の値の順に種類ができる（最初の軸が外側）。在庫が 0 の種類は売り切れ、stopped の種類は販売しない見本。
     * 写真は 1 つめの軸（色）の値ごと（photos）と、色に紐づけない商品の写真（gallery）
     *
     * @var array<string, ListingSpec>
     */
    public const array LISTINGS = [
        'apron' => [
            'title' => 'リネンのエプロン（サンプル）',
            'price' => 4800,
            'category' => 'kitchen',
            'summary' => '洗うほどやわらかくなる、リネン 100% の胸当てエプロン。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'paragraph', 'text' => "洗うほどやわらかくなる、リネン 100% の胸当てエプロン。ポケットは 2 つ。\n色とサイズを選ぶと、写真と在庫が切り替わります（からしの L は売り切れの見本）。"],
            ],
            'specs' => [
                ['label' => '素材', 'value' => '麻 100%（リネン）'],
            ],
            'photos' => ['墨' => ['apron-sumi', '墨色のリネンのエプロン'], '生成り' => ['apron-kinari', '生成りのリネンのエプロン'], 'からし' => ['apron-karashi', 'からし色のリネンのエプロン']],
            'options' => [['name' => '色', 'values' => ['墨', '生成り', 'からし']], ['name' => 'サイズ', 'values' => ['M', 'L']]],
            'variants' => [
                '墨 / M' => ['sku' => 'SAMPLE-LINEN-APRON-SUMI-M', 'stock' => 6],
                '墨 / L' => ['sku' => 'SAMPLE-LINEN-APRON-SUMI-L', 'stock' => 4],
                '生成り / M' => ['sku' => 'SAMPLE-LINEN-APRON-KINARI-M', 'stock' => 6],
                '生成り / L' => ['sku' => 'SAMPLE-LINEN-APRON-KINARI-L', 'stock' => 2],
                'からし / M' => ['sku' => 'SAMPLE-LINEN-APRON-KARASHI-M', 'stock' => 5],
                'からし / L' => ['sku' => 'SAMPLE-LINEN-APRON-KARASHI-L', 'stock' => 0],
            ],
        ],
        'mug' => [
            'title' => 'ストーンウェアのマグ（サンプル）',
            'price' => 2200,
            'category' => 'kitchen',
            'summary' => 'ざらりとした手ざわりの、厚手のストーンウェアのマグ。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'paragraph', 'text' => "ざらりとした手ざわりの、厚手のストーンウェアのマグ。容量 300ml。\n色を選ぶと、写真と値段が切り替わります（青は少し高い見本）。"],
            ],
            'specs' => [
                ['label' => '容量', 'value' => '300ml'],
                ['label' => '使えるもの', 'value' => '電子レンジ・食器洗い機'],
            ],
            'photos' => ['白' => ['mug-white', '白いストーンウェアのマグ'], '青' => ['mug-blue', '青いストーンウェアのマグ']],
            'options' => [['name' => '色', 'values' => ['白', '青']]],
            'variants' => [
                '白' => ['sku' => 'SAMPLE-STONEWARE-MUG-WHITE', 'stock' => 8],
                '青' => ['sku' => 'SAMPLE-STONEWARE-MUG-BLUE', 'stock' => 8, 'price' => 2600],
            ],
        ],
        // 機能を全部使った見本: 軸 3 つ（12 種類。サイズで値段が違う・売り切れ・止めた種類）、色ごとの写真と商品の写真、
        // オプション 3 つ（選ぶ・入れる文字・付ける）、説明のブロックの 6 種類、仕様の表、短い説明、カテゴリ
        'canvas-tote' => [
            'title' => '帆布のトートバッグ（サンプル）',
            'price' => 5800,
            'category' => 'bags',
            'summary' => '目の詰まった 10 号帆布のトート。3 色・2 サイズ・持ち手 2 種類から選べて、名入れとギフト包装もできます。',
            'specs' => [
                ['label' => '素材', 'value' => '綿 100%（10 号帆布）'],
                ['label' => 'サイズ', 'value' => "M: 幅 36 × 高さ 32 × マチ 10 cm\nL: 幅 44 × 高さ 38 × マチ 14 cm"],
                ['label' => '持ち手', 'value' => "短め: 約 50 cm（手で持つ）\n長め: 約 65 cm（肩に掛ける）"],
                ['label' => '重さ', 'value' => 'M: 約 380 g ／ L: 約 480 g'],
                ['label' => '生産国', 'value' => '日本'],
                ['label' => 'お手入れ', 'value' => 'ぬるま湯で手洗い。陰干し'],
            ],
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'heading', 'level' => 2, 'text' => '毎日つかえる、しっかりした帆布'],
                ['type' => 'paragraph', 'text' => "目の詰まった 10 号帆布を、1 枚仕立てで縫いました。\n買い物の荷物やノートパソコンを入れても形がくずれにくく、使うほどに手になじみます。"],
                ['type' => 'image', 'photo' => 'tote-canvas', 'caption' => '目の詰まった 10 号帆布。使うほどにやわらかくなります'],
                ['type' => 'heading', 'level' => 3, 'text' => 'こんなときに'],
                ['type' => 'list', 'style' => 'bullet', 'items' => ['毎日の買い物（2L のペットボトルが 2 本入ります）', '通勤と通学（L は A4 のファイルが縦に入ります）', '旅行のサブバッグ']],
                ['type' => 'heading', 'level' => 3, 'text' => 'サイズの選び方'],
                ['type' => 'table', 'header' => true, 'caption' => 'サイズの目安（cm）', 'rows' => [
                    ['サイズ', '幅', '高さ', 'マチ', 'A4 のファイル'],
                    ['M', '36', '32', '10', '横に入る'],
                    ['L', '44', '38', '14', '縦にも横にも入る'],
                ]],
                ['type' => 'heading', 'level' => 3, 'text' => 'お手入れ'],
                ['type' => 'list', 'style' => 'number', 'items' => ['ぬるま湯に中性洗剤をとかす', '押し洗いし、よくすすぐ', '形を整えて陰干しする']],
                ['type' => 'note', 'tone' => 'warning', 'text' => '墨とキャメルは、はじめのうち色が移ることがあります。白い服と合わせるときや雨の日はご注意ください。'],
            ],
            'options' => [
                ['name' => '色', 'values' => ['生成り', '墨', 'キャメル']],
                ['name' => 'サイズ', 'values' => ['M', 'L']],
                ['name' => '持ち手', 'values' => ['短め', '長め']],
            ],
            'photos' => ['生成り' => ['tote-kinari', '生成りの帆布のトートバッグ'], '墨' => ['tote-sumi', '墨色の帆布のトートバッグ'], 'キャメル' => ['tote-camel', 'キャメル色の帆布のトートバッグ']],
            'gallery' => [['tote-shoulder', '帆布のトートバッグ（肩に掛けたところ）'], ['tote-detail', '帆布のトートバッグ（持ち手と縫い目）']],
            'variants' => [
                '生成り / M / 短め' => ['sku' => 'SAMPLE-TOTE-KINARI-M-SHORT', 'stock' => 8],
                '生成り / M / 長め' => ['sku' => 'SAMPLE-TOTE-KINARI-M-LONG', 'stock' => 5],
                '生成り / L / 短め' => ['sku' => 'SAMPLE-TOTE-KINARI-L-SHORT', 'stock' => 4, 'price' => 6800],
                '生成り / L / 長め' => ['sku' => 'SAMPLE-TOTE-KINARI-L-LONG', 'stock' => 6, 'price' => 6800],
                '墨 / M / 短め' => ['sku' => 'SAMPLE-TOTE-SUMI-M-SHORT', 'stock' => 7],
                '墨 / M / 長め' => ['sku' => 'SAMPLE-TOTE-SUMI-M-LONG', 'stock' => 3],
                '墨 / L / 短め' => ['sku' => 'SAMPLE-TOTE-SUMI-L-SHORT', 'stock' => 0, 'price' => 6800],
                '墨 / L / 長め' => ['sku' => 'SAMPLE-TOTE-SUMI-L-LONG', 'stock' => 2, 'price' => 6800],
                'キャメル / M / 短め' => ['sku' => 'SAMPLE-TOTE-CAMEL-M-SHORT', 'stock' => 5],
                'キャメル / M / 長め' => ['sku' => 'SAMPLE-TOTE-CAMEL-M-LONG', 'stock' => 1],
                'キャメル / L / 短め' => ['sku' => 'SAMPLE-TOTE-CAMEL-L-SHORT', 'stock' => 3, 'price' => 6800],
                // 在庫はあるが販売しない種類（売り切れとの違いの見本）
                'キャメル / L / 長め' => ['sku' => 'SAMPLE-TOTE-CAMEL-L-LONG', 'stock' => 2, 'price' => 6800, 'stopped' => true],
            ],
            'addons' => [
                ['kind' => 'choice', 'name' => 'ギフト包装', 'required' => true, 'choices' => [
                    ['label' => 'なし', 'amount' => 0],
                    ['label' => '簡易包装', 'amount' => 220],
                    ['label' => '箱入り', 'amount' => 550],
                ]],
                ['kind' => 'text', 'name' => '名入れ（ローマ字）', 'max_length' => 10, 'amount' => 770],
                ['kind' => 'toggle', 'name' => 'メッセージカード', 'amount' => 110],
            ],
        ],
    ];

    /** ショップの写真（サンプルの中の名前 => [写真の名前, 代わりの文字]） */
    public const array MEDIA = [
        'hero' => ['tote-shoulder', '帆布のトートバッグを肩に掛けて歩く人'],
        'look-apron' => ['apron-kinari', '生成りのエプロンを着けた人'],
        'look-tote' => ['tote-kinari', '生成りの帆布のトートバッグを持つ手'],
        'look-mug' => ['mug-blue', '机に置いた青いマグ'],
        'seasonal' => ['tote-detail', '帆布のトートバッグの持ち手と縫い目'],
    ];

    /** 送料（どのセットも同じ。消しても送料は残るので、別のセットへ替えても表記と合う） */
    public const array SHIPPING = ['fee' => 800, 'free_over' => 5000];

    /**
     * 写真の出どころ（Unsplash の写真の ID・撮った人・ページ）
     *
     * @var array<string, PhotoSource>
     */
    public const array PHOTOS = [
        'apron-sumi' => ['id' => 'POJyN0CeN8o', 'author' => 'Or Hakim', 'page' => 'https://unsplash.com/photos/a-man-with-a-black-apron-on-preparing-food-POJyN0CeN8o'],
        'apron-kinari' => ['id' => 'x8w2gymn_AE', 'author' => 'Herry Sutanto', 'page' => 'https://unsplash.com/photos/man-wearing-apron-x8w2gymn_AE'],
        'apron-karashi' => ['id' => 'rWdP7wvOnuA', 'author' => 'Florian Peeters', 'page' => 'https://unsplash.com/photos/person-in-yellow-apron-stands-outside-a-korean-seafood-restaurant-rWdP7wvOnuA'],
        'mug-white' => ['id' => 'n42ogaQn32o', 'author' => 'Annie Spratt', 'page' => 'https://unsplash.com/photos/white-ceramic-mug-on-wooden-table-top-n42ogaQn32o'],
        'mug-blue' => ['id' => 'ZtYNSUxFPEE', 'author' => 'Amr Serag', 'page' => 'https://unsplash.com/photos/a-couple-of-blue-mugs-sitting-on-top-of-a-table-ZtYNSUxFPEE'],
        'tote-kinari' => ['id' => 'PENodSVsL1s', 'author' => 'Mediamodifier', 'page' => 'https://unsplash.com/photos/person-in-blue-denim-jeans-holding-white-tote-bag-PENodSVsL1s'],
        'tote-sumi' => ['id' => '9b6UhV4nLZs', 'author' => "He's Her Lobster", 'page' => 'https://unsplash.com/photos/black-tote-bag-9b6UhV4nLZs'],
        'tote-camel' => ['id' => '1Pgq9ZpIatI', 'author' => 'Kelly Sikkema', 'page' => 'https://unsplash.com/photos/beige-eco-bag-1Pgq9ZpIatI'],
        'tote-detail' => ['id' => '-zBSu3rYjNs', 'author' => 'Mel Poole', 'page' => 'https://unsplash.com/photos/brown-tote-bag-on-white-surface--zBSu3rYjNs'],
        'tote-shoulder' => ['id' => 'z056T3eN-4E', 'author' => 'Rosalie Gdy', 'page' => 'https://unsplash.com/photos/person-walking-with-white-canvas-bag-z056T3eN-4E'],
        'tote-canvas' => ['id' => 'xz485Eku8O4', 'author' => 'Annie Spratt', 'page' => 'https://unsplash.com/photos/white-canvas-with-woven-texture-xz485Eku8O4'],
    ];

    public function name(): string
    {
        return 'fashion';
    }

    public function label(): string
    {
        return '布ものと道具のお店';
    }

    public function theme(): string
    {
        return 'fashion';
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
        return self::LISTINGS;
    }

    public function media(): array
    {
        return self::MEDIA;
    }

    public function themeSections(): array
    {
        return [
            'announcement' => ['values' => ['text' => 'サンプルのショップです。商品はすべて見本で、実際には販売していません']],
            'hero' => ['values' => [
                'heading' => "毎日に、\nなじむ布もの。",
                'body' => 'エプロンとトートのお店のサンプルです。商品・写真・文はすべて見本です。自分の商品に置き換えたら、管理画面のホームの「サンプルをまとめて消す」で片付けられます。',
                'image' => 'media:hero',
            ]],
            'categories' => ['values' => ['list' => array_map(static fn (string $slug): string => "category:{$slug}", array_keys(self::CATEGORIES))]],
            'new_arrivals' => [],
            'looks' => [
                'values' => ['heading' => "今季の\nコーディネート", 'body' => 'サンプルの文です。特集の説明を書きます。'],
                'items' => [
                    ['title' => 'リネンのエプロン', 'image' => 'media:look-apron'],
                    ['title' => '帆布のトート', 'image' => 'media:look-tote'],
                    ['title' => 'ストーンウェアのマグ', 'image' => 'media:look-mug'],
                ],
            ],
            'seasonal' => ['values' => [
                'label' => 'SAMPLE',
                'heading' => "使うほどに、\n手になじむ。",
                'body' => 'サンプルの文です。季節のおすすめや、お店のこだわりを書きます。',
                'note' => '見本の文です。期間などを書きます。',
                'image' => 'media:seasonal',
            ]],
            'favorites' => ['values' => ['products' => ['source' => 'pick', 'ids' => ['listing:canvas-tote', 'listing:apron', 'listing:mug']]]],
            'promises' => ['items' => [
                ['icon' => 'shipping', 'title' => '¥5,000 以上で送料無料', 'body' => 'サンプルの文です。送料の決まりを書きます'],
                ['icon' => 'returns', 'title' => '届いて 7 日まで交換', 'body' => 'サンプルの文です。返品と交換の決まりを書きます'],
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
            ['label' => '返品・交換', 'value' => self::LEGAL_TODO."返品と交換の条件を書きます。\n例: 届いてから 7 日以内に、使っていない物に限りお受けします。"],
        ];
    }

    public function photos(): array
    {
        return self::PHOTOS;
    }
}
