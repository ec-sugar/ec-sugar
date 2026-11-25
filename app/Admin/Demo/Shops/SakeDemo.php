<?php

declare(strict_types=1);

namespace App\Admin\Demo\Shops;

/**
 * デモの店「酒蔵」（ADR 0086）。テーマは kinari。
 * 見せる売り方: 年齢確認・のし（名入れ）・限定酒の抽選販売・頒布会（定期便）・新酒の予約販売・会員ランク（会員だけの古酒）・ギフトカード・銀行振込と代金引換
 */
final class SakeDemo extends BaseDemo
{
    public function kind(): string
    {
        return 'sake';
    }

    public function names(): array
    {
        return ['霞堤酒造', '宵待酒造', '雪見坂酒造'];
    }

    public function theme(): string
    {
        return 'kinari';
    }

    public function pitch(): string
    {
        return "小さな酒蔵の課題は、限定酒の発売の日にアクセスが集まって、早い者勝ちで買い占められてしまうことでした。お酒なので、20 歳未満の方に売らない確認も欠かせません。\n"
            .'EC-SUGAR では、限定酒を「抽選販売」にし、受付の間に会員がカードを預けて申し込み、当選した方にだけ請求します。'
            .'年齢は注文のたびに生年月日で確認（生年月日は残しません）、季節の頒布会は定期便で、新酒は発売日の前から予約で受けられます。';
    }

    public function story(): string
    {
        return "山あいの小さな酒蔵です（架空のお店です）。\n"
            .'蔵のまわりの田んぼで育てた米と、裏山の湧き水で、冬のあいだだけ酒を仕込んでいます。'
            .'しぼりたての新酒・季節の頒布会・年に一度の蔵出しの限定酒を、オンラインでもお届けしています。';
    }

    protected function company(): array
    {
        return [
            'seller' => '合同会社 '.$this->names()[0],
            'person' => '霞野 清',
            'address' => '〒000-0000 架空県 霞野市 堤町 4-5-6',
            'phone' => '000-0000-0000',
            'email' => 'demo-sake@example.jp',
        ];
    }

    protected function products(): array
    {
        $food = ['tax_class' => 'reduced'];

        return [
            'cellar-koshu' => ['蔵元会員だけの古酒 十年熟成 500ml', 8800, 6, 'limited', ['cellar', 'tanks'],
                '蔵の奥で十年ねかせた古酒。会員ランク「常連さん」以上の方だけに。', "蔵の奥で十年ねかせた、琥珀色の古酒です。\n\n会員ランク「常連さん」以上の会員の方だけに見える商品です（ほかの方には一覧にも検索にも出ません）。デモでは、デモの会員でログインしてもランクは「蔵の友」からなので、見えるのは管理画面だけです。",
                [['label' => '内容量', 'value' => '500ml'], ['label' => 'アルコール分', 'value' => '18 度']], ['sku' => 'DEMO-SAKE-KOSHU']],
            'tokkuri' => ['徳利とお猪口のセット', 4400, 12, 'tools', ['tokkuri', 'look-cups'],
                '桜色の釉薬の、手ざわりのやさしい酒器。', '桜色の釉薬をかけた徳利と、お猪口 2 つのセットです。のしを付けて贈り物にもできます。',
                [['label' => '内容', 'value' => '徳利 1・お猪口 2'], ['label' => '容量', 'value' => '徳利 300ml']], ['sku' => 'DEMO-SAKE-TOKKURI']],
            'amazake' => ['蔵の甘酒（ノンアルコール）6 本', 2160, 30, 'food', ['amazake'],
                '米麹だけでつくった、お酒ではない甘酒。', '米と米麹だけでつくった、アルコールの入っていない甘酒です。冷やしても、温めても。年齢の確認は不要です。',
                [['label' => '内容量', 'value' => '180ml × 6 本']], ['sku' => 'DEMO-SAKE-AMAZAKE'] + $food],
            'sakekasu' => ['酒粕 500g', 550, 40, 'food', ['kasu-nabe'],
                'しぼりたての酒粕。粕汁や鍋に。', "新酒をしぼったあとの、やわらかい酒粕です。粕汁・鍋・甘酒に。\n\n食品なので消費税は 8%（軽減税率）です。",
                [['label' => '内容量', 'value' => '500g'], ['label' => '保存', 'value' => '要冷蔵']], ['sku' => 'DEMO-SAKE-KASU'] + $food],
            'umeshu' => ['蔵の梅酒 720ml', 1980, 20, 'umeshu', ['umeshu'],
                '自分の蔵の日本酒で漬けた梅酒。', '自分の蔵の日本酒で、地元の青梅を漬けました。甘さ控えめです。',
                [['label' => '内容量', 'value' => '720ml'], ['label' => 'アルコール分', 'value' => '12 度']], ['sku' => 'DEMO-SAKE-UMESHU']],
            'tasting' => ['飲み比べ 300ml × 3 本', 3300, 15, 'junmai', ['bottle-white', 'umeshu'],
                '大吟醸・吟醸・純米を少しずつ。', '純米大吟醸「霞」・純米吟醸「宵待」・特別純米「雪見坂」を 300ml ずつ。のしと包装を選べます。',
                [['label' => '内容量', 'value' => '300ml × 3 本']], ['sku' => 'DEMO-SAKE-TASTING']],
            'hanpukai' => ['季節の頒布会（毎月 720ml × 2 本）', 5280, 50, 'limited', ['rice-green', 'bottle-brown'],
                '蔵の季節の酒を、毎月 2 本ずつ。', "春の生酒・夏の吟醸・秋のひやおろし・冬のしぼりたて。その月に蔵がいちばん飲んでほしい 2 本をお届けします。\n\nお届けは毎月。次の回を飛ばす・お届け先を変える・止めるは、お客さんがマイページから自分でできます。",
                [['label' => '内容量', 'value' => '720ml × 2 本（毎月）']], ['sku' => 'DEMO-SAKE-HANPUKAI']],
            'shinshu' => ['新酒 しぼりたて生原酒 720ml', 2640, 0, 'limited', ['rice-drying'],
                '冬の初しぼり。発売日の前から予約で受けます。', "その冬の最初にしぼった、火入れをしない生原酒です。発売日の前は予約で受け、発売日から順にお届けします。\n\n予約の数に上限があります。",
                [['label' => '内容量', 'value' => '720ml'], ['label' => 'アルコール分', 'value' => '18 度'], ['label' => '保存', 'value' => '要冷蔵']], ['sku' => 'DEMO-SAKE-SHINSHU']],
            'tobindori' => ['蔵出し限定 斗瓶取り 大吟醸 720ml', 11000, 12, 'limited', ['sugidama', 'bottle-clear'],
                '年に一度、12 本だけ。抽選販売でお受けします。', "もろみを袋に入れて吊るし、自然に落ちる雫だけを斗瓶に集めた大吟醸です。年に一度、12 本だけ。\n\n抽選販売です。受付の間に会員の方がカードを預けて申し込み、抽選の日に自動で抽選します（お店は当選を選べません）。当選した方にだけ、預けたカードで請求します。",
                [['label' => '内容量', 'value' => '720ml'], ['label' => '精米歩合', 'value' => '35%'], ['label' => 'アルコール分', 'value' => '17 度']], ['sku' => 'DEMO-SAKE-TOBIN']],
            'nigori' => ['にごり酒 300ml', 880, 24, 'junmai', ['bottle-white'],
                'おりを残した、とろりと甘いにごり酒。', 'おりを残してしぼった、とろりと甘いにごり酒です。よく冷やして。',
                [['label' => '内容量', 'value' => '300ml'], ['label' => 'アルコール分', 'value' => '15 度']], ['sku' => 'DEMO-SAKE-NIGORI']],
            'honjozo' => ['本醸造 辛口 1800ml', 2200, 30, 'junmai', ['tanks'],
                '毎日の晩酌に。すっきり辛口の本醸造。', '毎日の晩酌に。すっきりした辛口で、燗にしても崩れません。',
                [['label' => '内容量', 'value' => '1800ml'], ['label' => 'アルコール分', 'value' => '15 度']], ['sku' => 'DEMO-SAKE-HONJOZO']],
            'yukimizaka' => ['特別純米 雪見坂 1800ml', 2860, 25, 'junmai', ['rice-green'],
                '米のうまみのある、食中酒。', '蔵のまわりの田んぼの米で仕込んだ特別純米です。米のうまみがあり、料理に寄り添います。',
                [['label' => '内容量', 'value' => '1800ml'], ['label' => '精米歩合', 'value' => '60%'], ['label' => 'アルコール分', 'value' => '15 度']], ['sku' => 'DEMO-SAKE-YUKIMIZAKA']],
            'yoimachi' => ['純米吟醸 宵待', 2420, 0, 'ginjo', ['bottle-brown'],
                '夕暮れに飲みたい、やわらかな吟醸香。', "やわらかな吟醸香と、まるい口あたりの純米吟醸です。\n\n720ml と 1800ml から選べます。",
                [['label' => '精米歩合', 'value' => '55%'], ['label' => 'アルコール分', 'value' => '16 度']], [
                    'options' => [['name' => '容量', 'values' => ['720ml', '1800ml']]],
                    'variants' => ['720ml' => ['sku' => 'DEMO-SAKE-YOIMACHI-720', 'stock' => 30], '1800ml' => ['sku' => 'DEMO-SAKE-YOIMACHI-1800', 'stock' => 12, 'price' => 4620]],
                ]],
            'kasumi' => ['純米大吟醸 霞 720ml', 3960, 20, 'ginjo', ['bottle-clear', 'hero'],
                '蔵のいちばんの酒。贈り物に。', "精米歩合 40% まで磨いた米で仕込んだ、蔵のいちばんの酒です。華やかな香りと、すっと消える後味。\n\nのし（表書き・名入れ）と、桐箱の包装を選べます。",
                [['label' => '内容量', 'value' => '720ml'], ['label' => '精米歩合', 'value' => '40%'], ['label' => 'アルコール分', 'value' => '16 度']], ['sku' => 'DEMO-SAKE-KASUMI']],
        ];
    }

    protected function alts(): array
    {
        return [
            'cellar' => '暗い蔵の中の木の階段', 'tanks' => '酒蔵の仕込みのタンク', 'tokkuri' => '桜色の徳利とお猪口', 'look-cups' => '並んだ素焼きの器',
            'amazake' => '素朴な陶器の器', 'kasu-nabe' => '湯気の立つ鍋', 'umeshu' => '木の台に並んだ小さな器', 'bottle-white' => '白い瓶と 2 つのお猪口',
            'rice-green' => '実った稲', 'bottle-brown' => '茶色の瓶', 'rice-drying' => '田んぼで干している稲', 'sugidama' => '軒先に吊るした杉玉',
            'bottle-clear' => '透明なガラスの瓶', 'hero' => '山あいの田んぼ',
        ];
    }

    public function categories(): array
    {
        return ['ginjo' => '吟醸酒', 'junmai' => '純米酒・本醸造', 'limited' => '限定・季節の酒', 'umeshu' => '梅酒', 'food' => '酒粕・甘酒', 'tools' => '酒器'];
    }

    public function media(): array
    {
        return [
            'hero' => ['hero', '山あいの田んぼ'],
            'intro-1' => ['intro-1', '軒先に吊るした杉玉'],
            'intro-2' => ['intro-2', '酒蔵の仕込みのタンク'],
            'seasonal' => ['seasonal', '実った稲の田んぼ'],
            'look-rice' => ['look-rice', '田んぼで干している稲'],
            'look-cups' => ['look-cups', '並んだ素焼きの器'],
        ];
    }

    public function themeSections(): array
    {
        return [
            'announcement' => ['values' => ['text' => 'デモのお店です（架空の酒蔵）。20 歳未満の方へのお酒の販売はいたしません']],
            'hero' => ['values' => [
                'heading' => "田んぼと湧き水と、\n冬の仕込み。",
                'body' => '山あいの小さな酒蔵です。しぼりたての新酒、季節の頒布会、年に一度の蔵出しの限定酒をお届けします。',
                'image' => 'media:hero', 'photo_a' => 'media:intro-1', 'photo_b' => 'media:intro-2',
            ]],
            'categories' => ['values' => ['list' => ['category:ginjo', 'category:junmai', 'category:limited', 'category:umeshu', 'category:food', 'category:tools']]],
            'looks' => [
                'values' => ['heading' => "蔵の\nいちねん", 'body' => '春に田植え、秋に稲刈り、冬に仕込み。蔵のまわりの一年です。'],
                'items' => [
                    ['title' => '秋の稲刈り', 'image' => 'media:look-rice'],
                    ['title' => '蔵の器', 'image' => 'media:look-cups'],
                ],
            ],
            'new_arrivals' => ['values' => ['heading' => '新しいお酒', 'body' => '季節ごとに、蔵からの新しい一本。']],
            'favorites' => ['values' => ['products' => ['source' => 'pick', 'ids' => ['listing:kasumi', 'listing:tobindori', 'listing:hanpukai', 'listing:tasting']]]],
            'seasonal' => ['values' => [
                'label' => 'LIMITED', 'heading' => "年に一度、\n12 本だけの雫。",
                'body' => '蔵出し限定の斗瓶取り大吟醸は、抽選販売でお受けします。受付の間に申し込み、当選した方にだけ請求します。',
                'highlight' => '抽選の受付中', 'note' => 'デモのお店です。代金は動きません。', 'image' => 'media:seasonal',
            ]],
            'promises' => ['items' => [
                ['icon' => 'shipping', 'title' => '¥5,000 以上で送料無料', 'body' => '冷蔵の品はクール便で'],
                ['icon' => 'support', 'title' => 'のし・名入れ承ります', 'body' => '注文の時に選べます'],
                ['icon' => 'secure', 'title' => '20 歳未満の方には販売しません', 'body' => '注文のたびに年齢を確認します'],
            ]],
            'footer' => ['values' => ['message' => '20 歳未満の方の飲酒は法律で禁止されています。']],
        ];
    }

    public function photos(): array
    {
        return [
            'hero' => ['id' => 'C7HGPzPFPhE', 'author' => 'Dennis Peterson', 'page' => 'https://unsplash.com/photos/a-field-of-green-grass-C7HGPzPFPhE'],
            'intro-1' => ['id' => '0XY1WeLEAkc', 'author' => 'Cindy Bissig', 'page' => 'https://unsplash.com/photos/a-traditional-japanese-sugidama-hanging-from-a-wooden-building-0XY1WeLEAkc'],
            'intro-2' => ['id' => 'ILE36sjLrEQ', 'author' => 'Beaumont Yun', 'page' => 'https://unsplash.com/photos/a-room-with-a-bunch-of-pipes-in-it-ILE36sjLrEQ'],
            'seasonal' => ['id' => '2vi9sNl92cU', 'author' => 'Dennis Peterson', 'page' => 'https://unsplash.com/photos/a-large-field-of-grass-2vi9sNl92cU'],
            'look-rice' => ['id' => 'N6dAPHkW4j4', 'author' => 'PJH', 'page' => 'https://unsplash.com/photos/rice-drying-in-an-open-field-N6dAPHkW4j4'],
            'look-cups' => ['id' => 'OjIXCOpbDAs', 'author' => 'jason hu', 'page' => 'https://unsplash.com/photos/rows-of-unglazed-ceramic-cups-on-a-wooden-shelf-OjIXCOpbDAs'],
            'bottle-clear' => ['id' => 'Hc6MQLgCVtc', 'author' => 'Souro Souvik', 'page' => 'https://unsplash.com/photos/clear-glass-bottle-Hc6MQLgCVtc'],
            'bottle-brown' => ['id' => 'f5ORYmK-PAQ', 'author' => 'Kedibone Isaac Makhumisane', 'page' => 'https://unsplash.com/photos/a-brown-bottle-is-shown-on-a-gray-background-f5ORYmK-PAQ'],
            'bottle-white' => ['id' => 'gUa30D-mL_M', 'author' => 'Nichika Sakurai', 'page' => 'https://unsplash.com/photos/a-couple-of-cups-on-a-table-gUa30D-mL_M'],
            'tanks' => ['id' => 'ILE36sjLrEQ', 'author' => 'Beaumont Yun', 'page' => 'https://unsplash.com/photos/a-room-with-a-bunch-of-pipes-in-it-ILE36sjLrEQ'],
            'sugidama' => ['id' => '0XY1WeLEAkc', 'author' => 'Cindy Bissig', 'page' => 'https://unsplash.com/photos/a-traditional-japanese-sugidama-hanging-from-a-wooden-building-0XY1WeLEAkc'],
            'rice-drying' => ['id' => 'N6dAPHkW4j4', 'author' => 'PJH', 'page' => 'https://unsplash.com/photos/rice-drying-in-an-open-field-N6dAPHkW4j4'],
            'rice-green' => ['id' => 'mroWYEXBl2I', 'author' => 'Se. Tsuchiya', 'page' => 'https://unsplash.com/photos/a-field-of-green-grass-mroWYEXBl2I'],
            'umeshu' => ['id' => 'I5-j8AflLGA', 'author' => 'tommao wang', 'page' => 'https://unsplash.com/photos/several-ceramic-cups-arranged-on-a-wooden-surface-I5-j8AflLGA'],
            'kasu-nabe' => ['id' => 'mlxS_L5H1TE', 'author' => 'Tadahiro Higuchi', 'page' => 'https://unsplash.com/photos/steaming-hot-pot-with-vegetables-mlxS_L5H1TE'],
            'amazake' => ['id' => 'n9I_Xp7nyCk', 'author' => 'Adam Rasheed', 'page' => 'https://unsplash.com/photos/a-rustic-handmade-ceramic-cup-on-a-wooden-table-n9I_Xp7nyCk'],
            'tokkuri' => ['id' => 'yPYNhYr7Xrs', 'author' => 'Clayton Robbins', 'page' => 'https://unsplash.com/photos/pink-and-white-ceramic-pitcher-yPYNhYr7Xrs'],
            'cellar' => ['id' => '33ssRCZI37Q', 'author' => 'TOMOKO UJI', 'page' => 'https://unsplash.com/photos/brown-wooden-stairs-in-grayscale-photography-33ssRCZI37Q'],
        ];
    }

    public function apps(): array
    {
        return [
            'age-check' => ['gate' => true],
            'wrapping' => ['noshi' => true, 'noshi_titles' => '御祝、内祝、御礼、御歳暮、御年賀、寿、志、無地', 'noshi_fee' => '0', 'noshi_name' => true, 'noshi_name_max' => '12'],
            'lottery' => [],
            'subscription' => [],
            'preorder' => [],
            'member-ranks' => [
                'ranks' => 'three',
                'rank1_name' => '蔵の友', 'rank1_threshold' => '0', 'rank1_discount' => '0', 'rank1_points' => '0',
                'rank2_name' => '常連さん', 'rank2_threshold' => '30000', 'rank2_discount' => '3', 'rank2_points' => '1',
                'rank3_name' => '蔵元の友', 'rank3_threshold' => '80000', 'rank3_discount' => '5', 'rank3_points' => '2',
            ],
            'gift-cards' => ['denominations' => '3000,5000,10000,20000'],
            'blog' => [],
        ];
    }

    public function panels(): array
    {
        $age = ['age-check' => ['required' => true, 'age' => 'age20']];
        $gift = ['wrapping' => ['wrappable' => true, 'noshi' => true]];

        return [
            'kasumi' => $age + $gift,
            'yoimachi' => $age + $gift,
            'yukimizaka' => $age + $gift,
            'honjozo' => $age,
            'nigori' => $age,
            'tobindori' => $age + $gift + ['lottery' => ['enabled' => true, 'opens_at' => 'now-1h', 'closes_at' => '+14d 23:00', 'draws_at' => '+15d 10:00', 'winners' => '12', 'per_member' => '1', 'grace_days' => '3']],
            'shinshu' => $age + ['preorder' => ['enabled' => true, 'release_date' => '+30d', 'capacity' => '200']],
            'hanpukai' => $age + ['subscription' => ['enabled' => true, 'interval_monthly' => true, 'discount' => 'off']],
            'tasting' => $age + $gift,
            'umeshu' => $age + $gift,
            'tokkuri' => $gift,
            'cellar-koshu' => $age + ['member-ranks' => ['visibility' => 'rank2']],
        ];
    }

    public function forms(): array
    {
        return [
            'wrapping' => [
                ['name' => '蔵の包装紙', 'price' => '220', 'active' => true],
                ['name' => '桐箱（720ml 1 本用）', 'price' => '1100', 'active' => true],
            ],
        ];
    }

    public function coupons(): array
    {
        return [
            ['code' => 'KURABIRAKI', 'name' => '蔵開き 500 円引き', 'kind' => 'fixed', 'value' => 500, 'min_subtotal' => 5000, 'target' => 'all', 'per_customer_limit' => 1, 'members_only' => false],
            ['method' => 'automatic', 'label' => '一升瓶を 2 本以上で送料無料', 'name' => '一升瓶のまとめ買い', 'kind' => 'free_shipping', 'target' => 'listings', 'target_ids' => ['listing:yukimizaka', 'listing:honjozo'], 'min_quantity' => 2, 'members_only' => false],
        ];
    }

    public function posts(): array
    {
        return [
            ['title' => '新酒の予約を始めました', 'slug' => 'shinshu-preorder', 'excerpt' => 'しぼりたて生原酒を、発売日の前から予約で。',
                'body' => [['type' => 'paragraph', 'text' => 'その冬の最初にしぼる生原酒の予約を始めました（デモのお知らせです）。発売日から順にお届けします。'], ['type' => 'paragraph', 'text' => '予約の数には上限があります。']]],
            ['title' => '蔵出し限定の抽選について', 'slug' => 'lottery', 'app' => 'lottery', 'excerpt' => '年に一度の斗瓶取り。抽選の決まり。',
                'body' => [['type' => 'paragraph', 'text' => '蔵出し限定の斗瓶取り大吟醸は、抽選販売でお受けします。申し込みは会員の方だけ、お一人 1 本まで。抽選は自動で行い、当選した方にだけ請求します（デモのお知らせです）。']]],
            ['title' => '頒布会のお届け先は、マイページで変えられます', 'slug' => 'hanpukai', 'excerpt' => '次の回を飛ばす・お届け先を変えるは、ご自身で。',
                'body' => [['type' => 'paragraph', 'text' => '季節の頒布会は、次の回を飛ばす・お届け先を変える・止めるを、マイページの「定期便」からいつでもできます。お電話やメールでのご連絡は不要です。']]],
        ];
    }

    public function settings(): array
    {
        return [
            'direct' => [
                'bank_transfer' => true, 'bank_days' => 7,
                'bank_account' => ['bank' => '見本銀行（架空）', 'branch' => '霞野支店', 'type' => 'ordinary', 'number' => '0000000', 'holder' => 'カ）カスミツツミシュゾウ'],
                'cod' => true, 'cod_fees' => [['below' => 10000, 'fee' => 330], ['below' => 30000, 'fee' => 440], ['below' => null, 'fee' => 660]], 'cod_limit' => 300000,
            ],
        ];
    }
}
