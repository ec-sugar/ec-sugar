<?php

declare(strict_types=1);

namespace App\Core\Sample;

/**
 * 入れたときのサンプル「お菓子のお店」。既定のテーマ（角砂糖）に合わせた、焼き菓子とジャムの小さなお店。
 * 中身は開発の見本の店 SUGAR SHOP（SugarShopSeeder）と同じ（二重に持たない）。
 *
 * 写真は配る物に入れない。入れる時に Unsplash から取る（出どころは PHOTOS。写真の ID・撮った人・ページ）。
 * 写真は商品 23 枚・ショップ 3 枚の 26 枚（写真のアップロードの 1 分の上限 30 枚の内）。
 *
 * @phpstan-import-type ListingSpec from SampleSet
 * @phpstan-import-type PhotoSource from SampleSet
 */
final class SweetsSamples implements SampleSet
{
    /** カテゴリ（slug => 名前） */
    public const array CATEGORIES = [
        'baked' => '焼き菓子',
        'cake' => 'ケーキ',
        'sugar' => '砂糖菓子',
        'jam' => 'ジャム',
        'season' => '季節の限定',
    ];

    /**
     * 商品の表（名前 => [商品名, 価格, 在庫, カテゴリ, 写真（[名前, 代わりの文字]）, 短い説明, 説明, 仕様]）。古い順に作る
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: string, 4: list<array{0: string, 1: string}>, 5: string, 6: string, 7: list<array{label: string, value: string}>}>
     */
    private const array PRODUCTS = [
        'jam-apricot' => ['杏のジャム', 1280, 12, 'jam', [['jam-apricot', '杏のジャムの瓶']],
            '国産の杏を、皮ごと砂糖だけで煮ました。', '国産の杏を、皮ごと砂糖だけで煮ました。パンにも、ヨーグルトにも。',
            [['label' => '内容量', 'value' => '140g'], ['label' => '賞味期限', 'value' => '製造から 6 か月']]],
        'jam-strawberry' => ['苺のジャム', 1280, 15, 'jam', [['jam-strawberry', '苺のジャムの瓶'], ['jam-strawberry-2', '木の板にのせた苺のジャム']],
            '朝摘みの苺を、その日のうちに炊いたジャム。', '朝摘みの苺を、その日のうちに炊いたジャムです。',
            [['label' => '内容量', 'value' => '140g'], ['label' => '賞味期限', 'value' => '製造から 6 か月']]],
        'sugar-cube' => ['てんさい糖の角砂糖', 640, 30, 'sugar', [['sugar-cube', '積んだ角砂糖'], ['sugar-cube-2', '角砂糖の山']],
            '北海道のてんさい糖を、ひとつずつ角に固めました。', '北海道のてんさい糖を、ひとつずつ角に固めました。紅茶やコーヒーに。',
            [['label' => '内容量', 'value' => '300g']]],
        'chocochip' => ['チョコチップクッキー 4 枚', 980, 20, 'baked', [['chocochip', '重ねたチョコチップクッキー']],
            '大きめのチョコを混ぜた、しっとりした焼き上がり。', '大きめのチョコを混ぜた、しっとりした焼き上がりのクッキーです。',
            [['label' => '賞味期限', 'value' => '発送から 14 日']]],
        'shortbread' => ['ショートブレッド', 860, 18, 'baked', [['shortbread', 'お皿にのせたショートブレッド']],
            'バターと小麦と砂糖だけ。ほろりと崩れる食感です。', 'バターと小麦と砂糖だけ。ほろりと崩れる食感です。',
            [['label' => '賞味期限', 'value' => '発送から 30 日']]],
        'icing-pound' => ['アイシングのパウンドケーキ', 2200, 6, 'cake', [['icing-pound', 'アイシングをかけたパウンドケーキ']],
            'レモンのアイシングをかけたパウンドケーキ。', 'レモンのアイシングをかけたパウンドケーキ。1 本（約 18cm）。',
            [['label' => '大きさ', 'value' => '約 18cm'], ['label' => '賞味期限', 'value' => '発送から 7 日']]],
        'berry-tart' => ['ベリーのタルト', 720, 0, 'cake', [['berry-tart', 'ブルーベリーと苺のタルト']],
            'ブルーベリーと苺をのせた小さなタルト。', "ブルーベリーと苺をのせた小さなタルトです。\n売り切れの見本として、在庫を 0 にしています。",
            [['label' => '保存', 'value' => '要冷蔵'], ['label' => '賞味期限', 'value' => '発送から 2 日']]],
        'macaron-6' => ['マカロン 6 個入り', 2100, 8, 'sugar', [['macaron-6', '並べたマカロン']],
            '季節の 6 つの味。', '季節の 6 つの味を詰め合わせました。',
            [['label' => '保存', 'value' => '要冷蔵'], ['label' => '賞味期限', 'value' => '発送から 5 日']]],
        'heart-sable' => ['ハートのサブレ 10 枚', 1200, 2, 'baked', [['heart-sable', 'ハートの形のサブレ']],
            '贈り物にも。ひとつずつ型で抜いたサブレです。', '贈り物にも。ひとつずつ型で抜いたサブレです。残りわずかの見本です。',
            [['label' => '賞味期限', 'value' => '発送から 30 日']]],
        'lemon-tart' => ['レモンタルト', 680, 10, 'cake', [['lemon-tart', '切り分けたレモンタルト']],
            '瀬戸内のレモンを使った、酸味のはっきりしたタルト。', '瀬戸内のレモンを使った、酸味のはっきりしたタルトです。',
            [['label' => '保存', 'value' => '要冷蔵'], ['label' => '賞味期限', 'value' => '発送から 2 日']]],
        'cookie-tin' => ['バタークッキー缶', 2980, 9, 'baked', [['cookie-tin', 'お皿に並べたバタークッキー']],
            '5 種のバタークッキーを詰め合わせた缶。', '5 種のバタークッキーを詰め合わせた缶です。',
            [['label' => '賞味期限', 'value' => '発送から 45 日']]],
        'lemon-cake' => ['レモンケーキ', 2400, 7, 'cake', [['lemon-cake', 'レモンのケーキ']],
            'しっとりしたレモンのケーキ。', 'しっとりしたレモンのケーキ。1 本（約 18cm）。',
            [['label' => '大きさ', 'value' => '約 18cm'], ['label' => '賞味期限', 'value' => '発送から 7 日']]],
        'fruit-tart' => ['季節の果物のタルト', 3600, 3, 'season', [['fruit-tart', '果物をのせたタルト']],
            'その週にいちばんおいしい果物をのせたホールのタルト。', 'その週にいちばんおいしい果物をのせたホールのタルト（直径 18cm）です。',
            [['label' => '大きさ', 'value' => '直径 18cm'], ['label' => '保存', 'value' => '要冷蔵'], ['label' => '賞味期限', 'value' => '発送から 2 日']]],
        'pavlova' => ['苺のパブロバ', 780, 2, 'season', [['pavlova', '苺をのせたパブロバ']],
            'さくさくのメレンゲに、生クリームと苺をのせて。', 'さくさくのメレンゲに、生クリームと苺をのせました。',
            [['label' => '保存', 'value' => '要冷蔵'], ['label' => '賞味期限', 'value' => '発送から 1 日']]],
        'macaron-3' => ['マカロン 3 個入り', 1080, 14, 'sugar', [['macaron-3', '3 つのマカロン'], ['macaron-3-2', '積んだマカロン']],
            '苺・ピスタチオ・バニラの 3 つ。', '苺・ピスタチオ・バニラの 3 つの味です。',
            [['label' => '保存', 'value' => '要冷蔵'], ['label' => '賞味期限', 'value' => '発送から 5 日']]],
        'meringue' => ['角砂糖のメレンゲ', 540, 25, 'sugar', [['meringue', '器に盛ったメレンゲ'], ['meringue-2', 'お皿にのせたメレンゲ']],
            '角砂糖をそのまま焼いたような、軽いメレンゲ。', '角砂糖をそのまま焼いたような、軽いメレンゲです。',
            [['label' => '賞味期限', 'value' => '発送から 30 日']]],
        'madeleine' => ['マドレーヌ 6 個入り', 1620, 16, 'baked', [['madeleine', '紙にのせたマドレーヌ'], ['madeleine-2', 'かごに入れた焼き菓子']],
            '焦がしバターのマドレーヌ。', '焦がしバターのマドレーヌです。',
            [['label' => '賞味期限', 'value' => '発送から 14 日']]],
        'financier' => ['フィナンシェ 5 個入り', 1350, 20, 'baked', [['financier', 'お皿にのせたフィナンシェ']],
            'アーモンドと焦がしバターのフィナンシェ。', 'アーモンドと焦がしバターのフィナンシェに、木いちごをひとつ。',
            [['label' => '賞味期限', 'value' => '発送から 14 日']]],
    ];

    /** ショップの写真（サンプルの中の名前 => [写真の名前, 代わりの文字]） */
    public const array MEDIA = [
        'hero' => ['hero', '白い台に置いた丸い焼き菓子'],
        'intro-1' => ['intro-1', '型で抜いたクッキーの生地'],
        'intro-2' => ['intro-2', '粉砂糖をふるう手'],
    ];

    /** 送料（どのセットも同じ。消しても送料は残るので、別のセットへ替えても表記と合う） */
    public const array SHIPPING = ['fee' => 800, 'free_over' => 5000];

    /**
     * 写真の出どころ（Unsplash の写真の ID・撮った人・ページ）
     *
     * @var array<string, PhotoSource>
     */
    public const array PHOTOS = [
        'hero' => ['id' => 'z8kriatLFdA', 'author' => 'Vyshnavi Bisani', 'page' => 'https://unsplash.com/photos/brown-round-cookie-on-white-surface-z8kriatLFdA'],
        'intro-1' => ['id' => 'HZgyeP-xDBU', 'author' => 'Katerina Smirnova', 'page' => 'https://unsplash.com/photos/brown-roller-pin-HZgyeP-xDBU'],
        'intro-2' => ['id' => 'LO1zRWDCCEo', 'author' => 'Tomeo Sonner', 'page' => 'https://unsplash.com/photos/person-pouring-water-on-white-ceramic-bowl-LO1zRWDCCEo'],
        'jam-apricot' => ['id' => 's5F1ZikJdyA', 'author' => 'Nicolás Flor', 'page' => 'https://unsplash.com/photos/clear-glass-jar-with-orange-liquid-s5F1ZikJdyA'],
        'jam-strawberry' => ['id' => 'DnH84BR_7pw', 'author' => 'Pedro Miguel Aires', 'page' => 'https://unsplash.com/photos/a-glass-jar-filled-with-red-liquid-on-a-white-background-DnH84BR_7pw'],
        'jam-strawberry-2' => ['id' => 'H-k2TvVj3g0', 'author' => 'Barbara Chowaniec', 'page' => 'https://unsplash.com/photos/a-jar-of-jam-sitting-on-top-of-a-wooden-cutting-board-H-k2TvVj3g0'],
        'sugar-cube' => ['id' => 'tP8_HRehSfE', 'author' => 'Zhang liven', 'page' => 'https://unsplash.com/photos/a-pile-of-marshmallows-sitting-on-top-of-a-table-tP8_HRehSfE'],
        'sugar-cube-2' => ['id' => 'TXVntZ190Ao', 'author' => 'Daniel Kraus', 'page' => 'https://unsplash.com/photos/a-pile-of-sugar-cubes-sitting-on-top-of-each-other-TXVntZ190Ao'],
        'chocochip' => ['id' => 'HpAePNFM0RU', 'author' => 'Erwan NONON', 'page' => 'https://unsplash.com/photos/a-stack-of-cookies-sitting-on-top-of-a-wooden-table-HpAePNFM0RU'],
        'shortbread' => ['id' => 'F8GrQoeoAZ8', 'author' => 'Heather Barnes', 'page' => 'https://unsplash.com/photos/cookies-on-plate-F8GrQoeoAZ8'],
        'icing-pound' => ['id' => '-Qe0rpF2ThY', 'author' => 'Alexandra Golovac', 'page' => 'https://unsplash.com/photos/barked-bread-with-lemon-fruit-and-cream--Qe0rpF2ThY'],
        'berry-tart' => ['id' => 'a8X8mWV7Ttg', 'author' => 'Josephina Kolpachnikof', 'page' => 'https://unsplash.com/photos/a-close-up-of-a-pastry-topped-with-blueberries-and-strawberries-a8X8mWV7Ttg'],
        'macaron-6' => ['id' => 'WbZesfqwR-A', 'author' => 'Heather Barnes', 'page' => 'https://unsplash.com/photos/macarons-on-table-WbZesfqwR-A'],
        'heart-sable' => ['id' => 'Agh_2dS9mJQ', 'author' => 'Diane Helentjaris', 'page' => 'https://unsplash.com/photos/brown-and-black-wooden-board-Agh_2dS9mJQ'],
        'lemon-tart' => ['id' => 'n025kIRhIII', 'author' => 'an_vision', 'page' => 'https://unsplash.com/photos/sliced-of-pie-n025kIRhIII'],
        'cookie-tin' => ['id' => '5eFLNCseqNg', 'author' => 'Angie J', 'page' => 'https://unsplash.com/photos/brown-and-white-donuts-on-white-ceramic-plate-5eFLNCseqNg'],
        'lemon-cake' => ['id' => 'Rhos_ejdrcM', 'author' => 'The Design Lady', 'page' => 'https://unsplash.com/photos/a-loaf-of-lemon-bread-sitting-on-top-of-a-piece-of-wax-paper-Rhos_ejdrcM'],
        'fruit-tart' => ['id' => 'LpKI3oH3SLU', 'author' => 'Crystal Jo', 'page' => 'https://unsplash.com/photos/fruit-salad-on-white-ceramic-plate-LpKI3oH3SLU'],
        'pavlova' => ['id' => '5nCTfEru3Do', 'author' => 'Eugene Krasnaok', 'page' => 'https://unsplash.com/photos/two-desserts-on-a-white-plate-with-strawberries-and-blueberries-5nCTfEru3Do'],
        'macaron-3' => ['id' => 'tAIV_oN-_-M', 'author' => 'Heather Barnes', 'page' => 'https://unsplash.com/photos/three-assorted-flavor-macarons-tAIV_oN-_-M'],
        'macaron-3-2' => ['id' => 'i5BY6W2ttts', 'author' => 'Diana Polekhina', 'page' => 'https://unsplash.com/photos/a-stack-of-three-macaroons-sitting-on-top-of-each-other-i5BY6W2ttts'],
        'meringue' => ['id' => '53TOpUe9X_0', 'author' => 'Katerina Lunyova', 'page' => 'https://unsplash.com/photos/a-wooden-bowl-filled-with-whipped-cream-on-top-of-a-table-53TOpUe9X_0'],
        'meringue-2' => ['id' => '6bUdwMGz8L4', 'author' => 'Evangelina Silina', 'page' => 'https://unsplash.com/photos/white-and-pink-ceramic-plate-6bUdwMGz8L4'],
        'madeleine' => ['id' => 'mTS722Spt6E', 'author' => 'Max Griss', 'page' => 'https://unsplash.com/photos/two-brown-round-fruits-on-white-paper-mTS722Spt6E'],
        'madeleine-2' => ['id' => 'TgJHwwTSZ2Y', 'author' => 'Luna Hu', 'page' => 'https://unsplash.com/photos/a-basket-filled-with-pastries-sitting-on-top-of-a-table-TgJHwwTSZ2Y'],
        'financier' => ['id' => 'g6-zftnM7VM', 'author' => 'Luna Hu', 'page' => 'https://unsplash.com/photos/a-close-up-of-a-plate-of-food-on-a-table-g6-zftnM7VM'],
    ];

    public function name(): string
    {
        return 'sweets';
    }

    public function label(): string
    {
        return 'お菓子のお店';
    }

    public function theme(): string
    {
        return 'default';
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

        foreach (self::PRODUCTS as $key => [$title, $price, $stock, $category, $photos, $summary, $body, $specs]) {
            $listings[$key] = [
                'title' => "{$title}（サンプル）",
                'price' => $price,
                'category' => $category,
                'summary' => $summary,
                'blocks' => [
                    ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                    ['type' => 'paragraph', 'text' => $body],
                ],
                'specs' => $specs,
                'photos' => ['' => $photos[0]],
                'gallery' => array_slice($photos, 1),
                'sku' => 'SAMPLE-'.strtoupper($key),
                'stock' => $stock,
                // お菓子とジャムは飲食料品なので軽減税率 8%（ADR 0063）
                'tax_class' => 'reduced',
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
            'announcement' => ['values' => ['text' => 'サンプルのショップです。¥5,000 以上のご注文で送料無料（見本の文）']],
            'hero' => ['values' => [
                'image' => 'media:hero',
                'eyebrow' => 'SAMPLE SWEETS SHOP',
                'heading' => "ひとつずつ、\nていねいに。",
                'body' => "焼き菓子とジャムのお店のサンプルです。商品・写真・文はすべて見本です。\n管理画面で自分の商品に置き換えたら、ホームの「サンプルをまとめて消す」で片付けられます。",
                'photo_a' => 'media:intro-1',
                'photo_b' => 'media:intro-2',
            ]],
            'categories' => ['values' => ['list' => array_map(static fn (string $slug): string => "category:{$slug}", array_keys(self::CATEGORIES))]],
            'promises' => ['items' => [
                ['icon' => 'shipping', 'title' => '¥5,000 以上で送料無料', 'body' => 'サンプルの文です。送料の決まりを書きます'],
                ['icon' => 'returns', 'title' => '傷みがあったときは交換', 'body' => 'サンプルの文です。返品と交換の決まりを書きます'],
                ['icon' => 'secure', 'title' => 'カード情報は店に残りません', 'body' => '決済代行の画面でお支払い'],
                ['icon' => 'support', 'title' => 'メールでお問い合わせ', 'body' => 'サンプルの文です。返事の目安を書きます'],
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
            ['label' => '返品・交換', 'value' => self::LEGAL_TODO."返品と交換の条件を書きます。\n例: 食品のため、お客さまのご都合での返品はお受けしていません。傷みがあったときは、到着から 3 日以内にご連絡ください。"],
        ];
    }

    public function photos(): array
    {
        return self::PHOTOS;
    }
}
