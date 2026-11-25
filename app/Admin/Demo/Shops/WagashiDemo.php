<?php

declare(strict_types=1);

namespace App\Admin\Demo\Shops;

/**
 * デモの店「和菓子店」（ADR 0086）。テーマは default（角砂糖）。
 * 見せる売り方: 季節の予約販売・のしとラッピング・贈り物（お届け先を受け取る人が入れる）・お届け日時・店頭受け取り
 */
final class WagashiDemo extends BaseDemo
{
    public function kind(): string
    {
        return 'wagashi';
    }

    public function names(): array
    {
        return ['和菓子 こよみ堂', '菓匠 しろつめ', '甘味処 ひなた坂'];
    }

    public function theme(): string
    {
        return 'default';
    }

    public function pitch(): string
    {
        return "和菓子店の課題は、贈り物の注文のたびに、相手の住所を聞き直したり、のしの表書きや届く日を電話で確認したりすることでした。日持ちしない生菓子は、送れずに店頭で渡すしかありません。\n"
            .'EC-SUGAR では、贈り物は相手のメールアドレスだけで注文でき、お届け先は受け取る方がご自身で入力します。'
            .'のしの表書き・名入れ・包装はご注文の時に選べ、お届けの日と時間帯も指定できます。生菓子は「店頭でお受け取り」の商品にして、季節の菓子は予約で受けます。';
    }

    public function story(): string
    {
        return "暦に合わせて菓子をこしらえる、小さな和菓子店です（架空のお店です）。\n"
            .'上生菓子は毎朝、その日の分だけ。焼き菓子と羊羹は日持ちがするので、全国へお届けしています。'
            .'お正月の花びら餅や夏の水まんじゅうなど、季節の菓子はご予約で承ります。';
    }

    protected function company(): array
    {
        return [
            'seller' => $this->names()[0],
            'person' => '暦野 小春',
            'address' => '〒000-0000 架空県 日向市 坂下町 7-8-9',
            'phone' => '000-0000-0000',
            'email' => 'demo-wagashi@example.jp',
        ];
    }

    protected function products(): array
    {
        $food = ['tax_class' => 'reduced'];

        return [
            'green-tea' => ['煎茶 ひなた 80g', 1080, 30, 'tea', ['green-tea'],
                '和菓子に合う、やわらかな煎茶。', '和菓子に合わせて選んだ、やわらかな甘みの煎茶です。',
                [['label' => '内容量', 'value' => '80g']], ['sku' => 'DEMO-WAGASHI-SENCHA'] + $food],
            'matcha' => ['お抹茶 30g 缶', 1620, 25, 'tea', ['matcha-powder', 'matcha-whisk'],
                'お薄に。菓子とあわせてどうぞ。', 'お薄に向く、まろやかなお抹茶です。缶入り。',
                [['label' => '内容量', 'value' => '30g']], ['sku' => 'DEMO-WAGASHI-MATCHA'] + $food],
            'anmitsu' => ['あんみつ詰め合わせ 4 個', 2400, 12, 'yakigashi', ['anmitsu'],
                '寒天と季節の果物、粒あんを添えて。', "寒天と季節の果物に、粒あんと黒蜜を添えたあんみつです。\n\nお届けの日と時間帯を選べます。",
                [['label' => '内容量', 'value' => '4 個'], ['label' => '保存', 'value' => '要冷蔵'], ['label' => '賞味期限', 'value' => '発送から 3 日']], ['sku' => 'DEMO-WAGASHI-ANMITSU'] + $food],
            'warabi' => ['抹茶わらび餅', 1404, 20, 'yakigashi', ['warabi'],
                'ぷるんとしたわらび餅に、濃いお抹茶を。', 'ぷるんとしたわらび餅に、濃いお抹茶と黒蜜を。',
                [['label' => '内容量', 'value' => '2 人前'], ['label' => '保存', 'value' => '要冷蔵']], ['sku' => 'DEMO-WAGASHI-WARABI'] + $food],
            'mizu-manju' => ['夏の水まんじゅう 6 個（予約）', 1890, 0, 'season', ['mizu'],
                '夏だけの、涼やかな水まんじゅう。発売日の前から予約で。', "くず粉で包んだ、夏だけの水まんじゅうです。発売日の前はご予約で承り、発売日から順にお届けします。\n\n予約の数に上限があります。",
                [['label' => '内容量', 'value' => '6 個'], ['label' => '保存', 'value' => '要冷蔵']], ['sku' => 'DEMO-WAGASHI-MIZU'] + $food],
            'hanabira' => ['花びら餅 5 個（お正月の予約）', 1620, 0, 'season', ['wagashi-tea'],
                'お正月だけの菓子。年末にまとめてお届けします。', "白い求肥に、味噌あんとごぼうを包んだお正月の菓子です。発売日の前はご予約で承ります。\n\n贈り物にするときは、のしの表書き「御年賀」と、お名前を入れられます。",
                [['label' => '内容量', 'value' => '5 個'], ['label' => '賞味期限', 'value' => '発送から 3 日']], ['sku' => 'DEMO-WAGASHI-HANABIRA'] + $food],
            'gift-box' => ['和菓子の詰め合わせ（贈答箱）', 4320, 15, 'gift', ['tray', 'hero'],
                '羊羹・焼き菓子・最中の詰め合わせ。のしと包装を選べます。', "日持ちのする羊羹・焼き菓子・最中を詰め合わせた贈答箱です。\n\nのし（表書き・名入れ）と包装をご注文の時に選べます。相手の住所が分からなくても、メールアドレスだけで贈れます（受け取る方がお届け先を入れます）。",
                [['label' => '内容', 'value' => '羊羹 2・焼き菓子 6・最中 4'], ['label' => '賞味期限', 'value' => '発送から 20 日']], ['sku' => 'DEMO-WAGASHI-GIFTBOX'] + $food],
            'manju' => ['焼きまんじゅう 10 個入り', 1800, 30, 'yakigashi', ['manju'],
                'こんがり焼いた皮に、こしあん。', 'こんがり焼いた皮に、なめらかなこしあんを包みました。日持ちがするので贈り物にも。',
                [['label' => '内容量', 'value' => '10 個'], ['label' => '賞味期限', 'value' => '発送から 14 日']], ['sku' => 'DEMO-WAGASHI-MANJU'] + $food],
            'mame-daifuku' => ['豆大福 6 個入り（店頭でお受け取り）', 1320, 20, 'namagashi', ['mochi'],
                'その日の朝についた餅で。店頭でのお渡しです。', "その日の朝についた餅で包む豆大福です。日持ちがしないので、お店でのお渡しだけにしています（送料はかかりません）。\n\nお受け取りの日と時間帯を、ご注文の時に選べます。",
                [['label' => '内容量', 'value' => '6 個'], ['label' => '賞味期限', 'value' => '当日']], ['sku' => 'DEMO-WAGASHI-DAIFUKU'] + $food],
            'mitarashi' => ['みたらしだんご 5 本（店頭でお受け取り）', 650, 30, 'namagashi', ['mitarashi'],
                '焼きたてに、甘辛いたれをたっぷり。', '焼きたてのだんごに、甘辛いたれをたっぷりかけます。店頭でのお渡しです。',
                [['label' => '内容量', 'value' => '5 本'], ['label' => '賞味期限', 'value' => '当日']], ['sku' => 'DEMO-WAGASHI-MITARASHI'] + $food],
            'dango' => ['三色だんご 6 本（店頭でお受け取り）', 1080, 25, 'namagashi', ['dango'],
                '桜・白・よもぎの三色。', '桜・白・よもぎの三色だんごです。店頭でのお渡しです。',
                [['label' => '内容量', 'value' => '6 本'], ['label' => '賞味期限', 'value' => '当日']], ['sku' => 'DEMO-WAGASHI-DANGO'] + $food],
            'nerikiri' => ['季節の上生菓子 5 個入り（店頭でお受け取り）', 2700, 10, 'namagashi', ['nerikiri', 'hero'],
                'その月の花をかたどった練り切り。', "その月の花をかたどった練り切りを 5 つ。毎朝こしらえるので、店頭でのお渡しだけです。\n\nのしを付けて、お持たせにもどうぞ。",
                [['label' => '内容量', 'value' => '5 個'], ['label' => '賞味期限', 'value' => '当日']], ['sku' => 'DEMO-WAGASHI-NERIKIRI', 'addons' => [['kind' => 'choice', 'name' => 'お箱', 'required' => true, 'choices' => [['label' => 'なし（手提げの袋）', 'amount' => 0], ['label' => '紙箱', 'amount' => 110], ['label' => '木箱', 'amount' => 550]]]]] + $food],
        ];
    }

    protected function alts(): array
    {
        return [
            'green-tea' => '急須と煎茶', 'matcha-powder' => '器に入ったお抹茶の粉', 'matcha-whisk' => '茶筅で点てるお抹茶', 'anmitsu' => '器に盛ったあんみつ',
            'warabi' => '抹茶のわらび餅', 'mizu' => '透きとおった水菓子', 'wagashi-tea' => '器にのせた上生菓子と紅茶', 'tray' => '黒い盆にのせた和菓子',
            'hero' => '木の器にのせた花の形の練り切り', 'manju' => '焼き色のついたまんじゅう', 'mochi' => '青い皿にのせた大福', 'mitarashi' => '手に持ったみたらしだんご',
            'dango' => '串に刺した三色だんご', 'nerikiri' => '手にのせた練り切り',
        ];
    }

    public function categories(): array
    {
        return ['namagashi' => '生菓子（店頭でお受け取り）', 'yakigashi' => '焼き菓子・甘味', 'season' => '季節のご予約', 'gift' => '贈り物', 'tea' => 'お茶'];
    }

    public function media(): array
    {
        return [
            'hero' => ['hero', '木の器にのせた花の形の練り切り'],
            'intro-1' => ['intro-1', '串に刺しただんご'],
            'intro-2' => ['intro-2', '器に点てたお抹茶'],
        ];
    }

    public function themeSections(): array
    {
        return [
            'announcement' => ['values' => ['text' => 'デモのお店です（架空の和菓子店）。贈り物は相手のメールアドレスだけで贈れます']],
            'hero' => ['values' => [
                'image' => 'media:hero',
                'eyebrow' => 'WAGASHI KOYOMI',
                'heading' => "暦をめくるように、\n季節の菓子を。",
                'body' => "上生菓子は毎朝その日の分だけ、店頭でお渡しします。\n焼き菓子と羊羹は、のしを付けて全国へ。",
                'photo_a' => 'media:intro-1',
                'photo_b' => 'media:intro-2',
            ]],
            'products' => ['values' => ['eyebrow' => 'SEASONAL', 'title' => '季節のおすすめ', 'products' => ['source' => 'pick', 'ids' => ['listing:nerikiri', 'listing:gift-box', 'listing:hanabira', 'listing:mizu-manju']]]],
            'categories' => ['values' => ['list' => ['category:namagashi', 'category:yakigashi', 'category:season', 'category:gift', 'category:tea']]],
            'promises' => ['items' => [
                ['icon' => 'shipping', 'title' => 'お届けの日と時間帯を選べます', 'body' => '¥5,000 以上で送料無料'],
                ['icon' => 'support', 'title' => 'のし・名入れ・包装', 'body' => 'ご注文の時に選べます'],
                ['icon' => 'returns', 'title' => '相手の住所を知らなくても', 'body' => 'メールアドレスだけで贈れます'],
                ['icon' => 'secure', 'title' => 'カード情報は店に残りません', 'body' => '決済代行の画面でお支払い'],
            ]],
        ];
    }

    public function photos(): array
    {
        return [
            'hero' => ['id' => 'Ayy_K-y_dLA', 'author' => 'Vicky Ng', 'page' => 'https://unsplash.com/photos/purple-and-white-flower-bouquet-on-brown-round-table-Ayy_K-y_dLA'],
            'intro-1' => ['id' => 'hcnj4JtNkd0', 'author' => 'Madeline Liu', 'page' => 'https://unsplash.com/photos/sweet-dumplings-on-wooden-skewers-arranged-in-rows-hcnj4JtNkd0'],
            'intro-2' => ['id' => 'vXivoEBxWIY', 'author' => 'Zed Can', 'page' => 'https://unsplash.com/photos/matcha-tea-in-a-green-ceramic-bowl-vXivoEBxWIY'],
            'nerikiri' => ['id' => 'NO6rghk3yyc', 'author' => 'Haley Truong', 'page' => 'https://unsplash.com/photos/person-holding-orange-round-fruit-NO6rghk3yyc'],
            'dango' => ['id' => 'KMnVNWxbCVc', 'author' => 'Diana Bondarenko', 'page' => 'https://unsplash.com/photos/sweet-glazed-and-tri-color-dango-on-skewers-KMnVNWxbCVc'],
            'mochi' => ['id' => 'OTmHU9HdkHo', 'author' => 'Andreas Haubold', 'page' => 'https://unsplash.com/photos/a-blue-plate-topped-with-three-different-types-of-food-OTmHU9HdkHo'],
            'manju' => ['id' => '-PprAW06AVE', 'author' => 'Yosuke Ota', 'page' => 'https://unsplash.com/photos/two-golden-fried-dumplings-on-a-plate--PprAW06AVE'],
            'warabi' => ['id' => 'VZu-9iRrkRc', 'author' => 'note thanun', 'page' => 'https://unsplash.com/photos/green-dessert-with-white-mochi-balls-and-nuts-VZu-9iRrkRc'],
            'anmitsu' => ['id' => 'g3GmbZw10Cw', 'author' => 'Yosuke Ota', 'page' => 'https://unsplash.com/photos/dessert-bowl-with-fruit-jelly-and-red-bean-paste-g3GmbZw10Cw'],
            'mizu' => ['id' => 'iJ7a6JLmAUI', 'author' => 'Igal Ness', 'page' => 'https://unsplash.com/photos/raindrop-cake-with-edible-flowers-iJ7a6JLmAUI'],
            'wagashi-tea' => ['id' => 'bcPzSVSpN7g', 'author' => 'Vicky Ng', 'page' => 'https://unsplash.com/photos/white-and-pink-floral-ceramic-teacup-on-white-floral-ceramic-saucer-bcPzSVSpN7g'],
            'tray' => ['id' => 'PbMdA9CegYo', 'author' => 'Stephen Pontes', 'page' => 'https://unsplash.com/photos/three-japanese-sweets-and-a-stick-on-a-black-tray-PbMdA9CegYo'],
            'matcha-powder' => ['id' => 'v1OW17UcR-Q', 'author' => 'Alice Pasqual', 'page' => 'https://unsplash.com/photos/green-powder-in-white-bowl-v1OW17UcR-Q'],
            'matcha-whisk' => ['id' => 'A1FPTdsSFM4', 'author' => 'Payoon Gerinto', 'page' => 'https://unsplash.com/photos/matcha-tea-whisking-in-bowl-A1FPTdsSFM4'],
            'mitarashi' => ['id' => 'pqCWDq5XZNI', 'author' => 'Yosuke Ota', 'page' => 'https://unsplash.com/photos/person-holding-a-skewer-of-sweet-dango-with-glaze-pqCWDq5XZNI'],
            'green-tea' => ['id' => '2T6D_DTitYE', 'author' => 'Perry Merrity II', 'page' => 'https://unsplash.com/photos/green-tea-in-glass-pot-and-cup-on-wooden-tray-2T6D_DTitYE'],
        ];
    }

    public function apps(): array
    {
        return [
            'preorder' => [],
            'wrapping' => ['noshi' => true, 'noshi_titles' => '御祝、内祝、御礼、御中元、御歳暮、御年賀、粗品、志、無地', 'noshi_fee' => '0', 'noshi_name' => true],
            'gift' => ['expiry_days' => '14'],
            'blog' => [],
            'favorites' => [],
        ];
    }

    public function panels(): array
    {
        $gift = ['wrapping' => ['wrappable' => true, 'noshi' => true]];

        return [
            'nerikiri' => ['wrapping' => ['noshi' => true]],
            'gift-box' => $gift,
            'manju' => $gift,
            'anmitsu' => $gift,
            'matcha' => $gift,
            'hanabira' => $gift + ['preorder' => ['enabled' => true, 'release_date' => '+40d', 'capacity' => '150']],
            'mizu-manju' => ['preorder' => ['enabled' => true, 'release_date' => '+21d', 'ends_on' => '+18d', 'capacity' => '120']],
        ];
    }

    public function forms(): array
    {
        return [
            'wrapping' => [
                ['name' => '季節の掛け紙', 'price' => '0', 'active' => true],
                ['name' => '風呂敷包み', 'price' => '550', 'active' => true],
            ],
        ];
    }

    public function coupons(): array
    {
        return [
            ['code' => 'KOYOMI', 'name' => '季節の便り 300 円引き', 'kind' => 'fixed', 'value' => 300, 'min_subtotal' => 3000, 'target' => 'all', 'per_customer_limit' => 1, 'members_only' => false],
            ['method' => 'automatic', 'label' => '贈答箱を 3 箱以上で 5% 引き', 'name' => '贈答箱のまとめ買い', 'kind' => 'percent', 'value' => 5, 'target' => 'listings', 'target_ids' => ['listing:gift-box'], 'min_quantity' => 3, 'members_only' => false],
        ];
    }

    public function posts(): array
    {
        return [
            ['title' => '花びら餅のご予約を承ります', 'slug' => 'hanabira-preorder', 'excerpt' => 'お正月の菓子を、年末にまとめてお届け。',
                'body' => [['type' => 'paragraph', 'text' => 'お正月の花びら餅のご予約を承ります（デモのお知らせです）。発売日から順にお届けします。のしの表書き「御年賀」と名入れも選べます。']]],
            ['title' => '住所を知らない方にも、和菓子を贈れます', 'slug' => 'gift-by-email', 'excerpt' => '相手のメールアドレスだけで。お届け先はお相手が入力します。',
                'body' => [['type' => 'paragraph', 'text' => 'ご注文の時に「贈り物」を選び、お相手のメールアドレスを入れてください。お相手にお届け先を入力していただくご案内が届きます（デモではメールは送らず、画面の上の「送るはずだったメール」に出ます）。']]],
        ];
    }

    public function settings(): array
    {
        return [
            'delivery' => ['dates' => true, 'slots' => ['am', '14-16', '16-18', '18-20'], 'ship_days' => 2, 'transit_days' => 1, 'span_days' => 14, 'cutoff' => '12:00', 'closed_weekdays' => [3], 'closed_dates' => []],
            'pickup' => ['nerikiri', 'dango', 'mitarashi', 'mame-daifuku'],
            'pickup_name' => '店頭でお受け取り（こよみ堂 本店）',
        ];
    }
}
