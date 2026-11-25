<?php

declare(strict_types=1);

namespace App\Core\Sample;

/**
 * 入れたときのサンプル「雑貨のお店」の中身（既定のセット）。既定のテーマ（角砂糖）に合わせた、白と灰色に合う落ち着いた絵と文。
 * 入れるのは ShopSamples。名前と文でサンプルと分かるようにする（商品名の「（サンプル）」と、説明の先頭の知らせ）。
 *
 * 写真は自作の絵（database/samples/zakka の SVG を WebP にしたもの。作り方は同じ場所の render.sh）。権利の心配が無く、リポジトリに入れて配れる（bundled）。
 * 品番は SAMPLE- で始める（お店の品番と重ならないように）。
 * 印の名前は前からの形（listing:mug など。セットの名前を前に付けない）のまま。入れ終えた店の印と合わせるため
 *
 * @phpstan-import-type ListingSpec from SampleSet
 */
final class ZakkaSamples implements SampleSet
{
    /** 説明の先頭に置く知らせ（どの商品にも。前からの名前） */
    public const string NOTE = SampleSet::NOTE;

    /** カテゴリ（slug => 名前）。同じ slug のカテゴリがお店にもうあれば、それを使う（印は付けない） */
    public const array CATEGORIES = [
        'kitchen' => 'キッチン',
        'cloth' => '布もの',
        'interior' => 'インテリア',
    ];

    /**
     * 商品（サンプルの中の名前 => 中身）。古い順に作る（ショップの新着は新しい順なので、最後の物が先頭に出る）。
     * photos は [写真の名前, 代わりの文字]。種類のある商品は 1 つめの軸（色）の値ごと、無い商品は '' の 1 枚
     *
     * @var array<string, ListingSpec>
     */
    public const array LISTINGS = [
        'coaster' => [
            'title' => '麻のコースター 4 枚組（サンプル）',
            'price' => 1100,
            'category' => 'kitchen',
            'summary' => '洗ってすぐ乾く、麻 100% のコースター。4 色を 1 枚ずつ。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'paragraph', 'text' => "麻の糸をざっくり織った、四角いコースターです。\nグラスの汗をよく吸い、洗ってもすぐに乾きます。"],
            ],
            'specs' => [
                ['label' => '素材', 'value' => '麻 100%'],
                ['label' => '大きさ', 'value' => '約 10 × 10 cm'],
            ],
            'photos' => ['' => ['coaster', '4 色の麻のコースター']],
            'sku' => 'SAMPLE-COASTER-4',
            'stock' => 20,
        ],
        'vase' => [
            'title' => '一輪挿し（サンプル）',
            'price' => 2750,
            'category' => 'interior',
            'summary' => '小さな花を 1 本だけ。手のひらにのる陶器の一輪挿し。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'paragraph', 'text' => "口の細い、ころんとした形の一輪挿しです。\n道ばたの草花を 1 本さすだけで、机の上が明るくなります。"],
            ],
            'specs' => [
                ['label' => '素材', 'value' => '陶器'],
                ['label' => '大きさ', 'value' => '直径 約 7 cm・高さ 約 12 cm'],
            ],
            'photos' => ['' => ['vase', '花を 1 本さした陶器の一輪挿し']],
            'sku' => 'SAMPLE-VASE',
            'stock' => 6,
        ],
        'candle' => [
            'title' => '蜜蝋のキャンドル（サンプル）',
            'price' => 1980,
            'category' => 'interior',
            'summary' => 'ほんのり甘い香りの、蜜蝋 100% のキャンドル。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'paragraph', 'text' => "みつばちの巣からとった蜜蝋だけで作ったキャンドルです。\n売り切れの見本として、在庫を 0 にしています。"],
                ['type' => 'note', 'tone' => 'warning', 'text' => '火をつけたまま、そばを離れないでください。'],
            ],
            'specs' => [
                ['label' => '素材', 'value' => '蜜蝋・綿の芯'],
                ['label' => '燃える時間', 'value' => '約 20 時間'],
            ],
            'photos' => ['' => ['candle', '火のついた蜜蝋のキャンドル']],
            'sku' => 'SAMPLE-CANDLE',
            'stock' => 0,
        ],
        'fukin' => [
            'title' => '綿のふきん 2 枚組（サンプル）',
            'price' => 880,
            'category' => 'cloth',
            'summary' => '水をよく吸う、しま模様の綿のふきん。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'paragraph', 'text' => "目のあらい綿の布を 2 枚重ねて縫った、ふきんです。\n食器をふくのにも、パンを包むのにも。"],
            ],
            'specs' => [
                ['label' => '素材', 'value' => '綿 100%'],
                ['label' => '大きさ', 'value' => '約 40 × 40 cm'],
            ],
            'photos' => ['' => ['fukin', 'しま模様の綿のふきん']],
            'sku' => 'SAMPLE-FUKIN-2',
            'stock' => 30,
        ],
        'spoon' => [
            'title' => '真鍮のティースプーン（サンプル）',
            'price' => 1320,
            'category' => 'kitchen',
            'summary' => '使うほどに色が深まる、真鍮のティースプーン。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'paragraph', 'text' => "職人がひとつずつたたいて形を整えた、真鍮のスプーンです。\n使ううちに色が落ち着き、手になじんでいきます。"],
            ],
            'specs' => [
                ['label' => '素材', 'value' => '真鍮'],
                ['label' => '長さ', 'value' => '約 13 cm'],
                ['label' => 'お手入れ', 'value' => '使った後は水気をふき取る。くもったら、やわらかい布でみがく'],
            ],
            'photos' => ['' => ['spoon', '真鍮のティースプーン']],
            'sku' => 'SAMPLE-SPOON',
            'stock' => 15,
        ],
        'mamezara' => [
            'title' => '豆皿 3 枚セット（サンプル）',
            'price' => 1650,
            'category' => 'kitchen',
            'summary' => '薬味や小さなお菓子に。3 色の小さなお皿。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'paragraph', 'text' => "直径 9 cm ほどの、小さなお皿の 3 枚組です。\n色ちがいで並べると、食卓がにぎやかになります。"],
            ],
            'specs' => [
                ['label' => '素材', 'value' => '磁器'],
                ['label' => '大きさ', 'value' => '直径 約 9 cm'],
                ['label' => '使えるもの', 'value' => '電子レンジ・食器洗い機'],
            ],
            'photos' => ['' => ['mamezara', '3 色の豆皿']],
            'sku' => 'SAMPLE-MAMEZARA-3',
            'stock' => 12,
        ],
        // 種類（色）と、付けるだけのオプション
        'mug' => [
            'title' => 'ストーンウェアのマグ（サンプル）',
            'price' => 2200,
            'category' => 'kitchen',
            'summary' => '厚手でざらりとした手ざわりのマグ。白と青の 2 色。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'paragraph', 'text' => "厚みのあるストーンウェアのマグです。容量は 300ml。\n色を選ぶと、写真と値段が切り替わります（青は少し高い見本）。"],
            ],
            'specs' => [
                ['label' => '容量', 'value' => '300ml'],
                ['label' => '使えるもの', 'value' => '電子レンジ・食器洗い機'],
            ],
            'photos' => ['白' => ['mug-white', '白いストーンウェアのマグ'], '青' => ['mug-blue', '青いストーンウェアのマグ']],
            'options' => [['name' => '色', 'values' => ['白', '青']]],
            'variants' => [
                '白' => ['sku' => 'SAMPLE-MUG-WHITE', 'stock' => 8],
                '青' => ['sku' => 'SAMPLE-MUG-BLUE', 'stock' => 5, 'price' => 2400],
            ],
            'addons' => [
                ['kind' => 'toggle', 'name' => 'ギフト包装', 'amount' => 220],
            ],
        ],
        // 機能をひととおり使った見本: 軸 2 つ（6 種類。サイズで値段が違う・売り切れの種類）、色ごとの写真、オプション 2 つ（選ぶ・入れる文字）、説明のブロック、仕様の表
        'canvas-tote' => [
            'title' => '帆布のトートバッグ（サンプル）',
            'price' => 5800,
            'category' => 'cloth',
            'summary' => '目の詰まった帆布のトート。3 色・2 サイズから選べて、名入れとギフト包装もできます。',
            'blocks' => [
                ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                ['type' => 'heading', 'level' => 2, 'text' => '毎日つかえる、しっかりした帆布'],
                ['type' => 'paragraph', 'text' => "目の詰まった帆布を、1 枚仕立てで縫いました。\n色とサイズを選ぶと、写真・値段・在庫が切り替わります。"],
                ['type' => 'heading', 'level' => 3, 'text' => 'サイズの選び方'],
                ['type' => 'table', 'header' => true, 'caption' => 'サイズの目安（cm）', 'rows' => [
                    ['サイズ', '幅', '高さ', 'マチ'],
                    ['M', '36', '32', '10'],
                    ['L', '44', '38', '14'],
                ]],
                ['type' => 'heading', 'level' => 3, 'text' => 'お手入れ'],
                ['type' => 'list', 'style' => 'number', 'items' => ['ぬるま湯に中性洗剤をとかす', '押し洗いし、よくすすぐ', '形を整えて陰干しする']],
            ],
            'specs' => [
                ['label' => '素材', 'value' => '綿 100%（帆布）'],
                ['label' => 'サイズ', 'value' => "M: 幅 36 × 高さ 32 × マチ 10 cm\nL: 幅 44 × 高さ 38 × マチ 14 cm"],
                ['label' => '生産国', 'value' => '日本'],
            ],
            'photos' => [
                '生成り' => ['tote-kinari', '生成りの帆布のトートバッグ'],
                '墨' => ['tote-sumi', '墨色の帆布のトートバッグ'],
                'からし' => ['tote-karashi', 'からし色の帆布のトートバッグ'],
            ],
            'options' => [['name' => '色', 'values' => ['生成り', '墨', 'からし']], ['name' => 'サイズ', 'values' => ['M', 'L']]],
            'variants' => [
                '生成り / M' => ['sku' => 'SAMPLE-TOTE-KINARI-M', 'stock' => 6],
                '生成り / L' => ['sku' => 'SAMPLE-TOTE-KINARI-L', 'stock' => 4, 'price' => 6800],
                '墨 / M' => ['sku' => 'SAMPLE-TOTE-SUMI-M', 'stock' => 5],
                '墨 / L' => ['sku' => 'SAMPLE-TOTE-SUMI-L', 'stock' => 0, 'price' => 6800],
                'からし / M' => ['sku' => 'SAMPLE-TOTE-KARASHI-M', 'stock' => 3],
                'からし / L' => ['sku' => 'SAMPLE-TOTE-KARASHI-L', 'stock' => 2, 'price' => 6800],
            ],
            'addons' => [
                ['kind' => 'choice', 'name' => 'ギフト包装', 'required' => true, 'choices' => [
                    ['label' => 'なし', 'amount' => 0],
                    ['label' => '簡易包装', 'amount' => 220],
                    ['label' => '箱入り', 'amount' => 550],
                ]],
                ['kind' => 'text', 'name' => '名入れ（ローマ字）', 'max_length' => 10, 'amount' => 770],
            ],
        ],
    ];

    /** ショップの写真（サンプルの中の名前 => [写真の名前, 代わりの文字]）。テーマの中身が使う */
    public const array MEDIA = [
        'hero' => ['hero', '雑貨を並べた棚の絵'],
        'intro-1' => ['intro-1', 'マグと豆皿を並べた机の絵'],
        'intro-2' => ['intro-2', '一輪挿しと花の絵'],
    ];

    /**
     * 既定のテーマ（角砂糖）の区画の値。写真は MEDIA の名前、カテゴリは CATEGORIES の slug で書き、入れるときに ID へ替える
     *
     * @return array<string, array<string, mixed>>
     */
    public function themeSections(): array
    {
        return [
            'announcement' => ['values' => ['text' => 'サンプルのショップです。商品はすべて見本で、実際には販売していません']],
            'hero' => ['values' => [
                'image' => 'media:hero',
                'eyebrow' => 'SAMPLE ZAKKA SHOP',
                'heading' => "毎日の道具を、\nすこしずつ。",
                'body' => "雑貨のお店のサンプルです。商品・写真・文はすべて見本です。\n管理画面で自分の商品に置き換えたら、ホームの「サンプルをまとめて消す」で片付けられます。",
                'photo_a' => 'media:intro-1',
                'photo_b' => 'media:intro-2',
            ]],
            'categories' => ['values' => ['list' => array_map(static fn (string $slug): string => "category:{$slug}", array_keys(self::CATEGORIES))]],
            'promises' => ['items' => [
                ['icon' => 'shipping', 'title' => '¥5,000 以上で送料無料', 'body' => 'サンプルの文です。送料の決まりを書きます'],
                ['icon' => 'returns', 'title' => '届いて 7 日まで交換', 'body' => 'サンプルの文です。返品と交換の決まりを書きます'],
                ['icon' => 'secure', 'title' => 'カード情報は店に残りません', 'body' => '決済代行の画面でお支払い'],
                ['icon' => 'support', 'title' => 'メールでお問い合わせ', 'body' => 'サンプルの文です。返事の目安を書きます'],
            ]],
        ];
    }

    /** 送料（お店がまだ決めていないときだけ入れる。消さずに残す） */
    public const array SHIPPING = ['fee' => 800, 'free_over' => 5000];

    /** 特定商取引法に基づく表記の見本（お店がまだ書いていないときだけ入れる） */
    public const array LEGAL = [
        ['label' => '販売業者', 'value' => '（サンプル）お店の名前か、会社の名前を書きます'],
        ['label' => '運営責任者', 'value' => '（サンプル）責任者の名前を書きます'],
        ['label' => '所在地', 'value' => '（サンプル）お店の住所を書きます'],
        ['label' => '電話番号', 'value' => '（サンプル）お問い合わせを受ける電話番号を書きます'],
        ['label' => 'メールアドレス', 'value' => '（サンプル）お問い合わせを受けるメールアドレスを書きます'],
        ['label' => '販売価格', 'value' => '各商品のページに表示しています（税込み）'],
        ['label' => '商品代金以外の必要料金', 'value' => "送料 ¥800\n¥5,000 以上のご注文で送料無料"],
        ['label' => 'お支払いの方法と時期', 'value' => 'クレジットカード。ご注文の確定のとき'],
        ['label' => '引き渡しの時期', 'value' => 'ご注文から 3〜5 日で発送します'],
        ['label' => '返品・交換', 'value' => "（サンプル）返品と交換の条件を書きます。\n例: 届いてから 7 日以内に、使っていない物に限りお受けします。"],
    ];

    public function name(): string
    {
        return 'zakka';
    }

    public function label(): string
    {
        return '雑貨のお店';
    }

    public function theme(): string
    {
        return 'default';
    }

    public function bundled(): bool
    {
        return true;
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

    public function shipping(): array
    {
        return self::SHIPPING;
    }

    public function legal(): array
    {
        return self::LEGAL;
    }

    public function photos(): array
    {
        return [];
    }
}
