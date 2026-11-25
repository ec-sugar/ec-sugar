<?php

declare(strict_types=1);

namespace App\Admin\Demo\Shops;

/**
 * デモの店「コーヒー焙煎所」（ADR 0086）。テーマは botanica。
 * 見せる売り方: 豆の定期便（毎週・2 週ごと・毎月・10% 引き）・挽き方のオプション・ギフトカード・ポイント・レビュー・焙煎教室の予約（App「予約」）
 */
final class CoffeeDemo extends BaseDemo
{
    private const array GRIND = [['name' => '挽き方', 'values' => ['豆のまま', '中挽き（ドリップ）', '細挽き（エスプレッソ）']]];

    public function kind(): string
    {
        return 'coffee';
    }

    public function names(): array
    {
        return ['月見坂珈琲焙煎所', 'くるみ舎コーヒーロースター', '港町三番地焙煎所'];
    }

    public function theme(): string
    {
        return 'botanica';
    }

    public function pitch(): string
    {
        return "小さな焙煎所の課題は、毎月の豆の注文を一件ずつ受けて、お届け先や周期の変更をメールでやり取りすることでした。\n"
            .'EC-SUGAR では、豆を「定期便」で申し込んでもらい、お届けの周期の変更・スキップ・止めるを、お客さんがマイページで自分でできます。'
            .'挽き方は商品の種類で選べ、焙煎教室は日時の枠から予約と先払いまで受けられます。';
    }

    public function story(): string
    {
        return "坂の上の小さな焙煎所です（架空のお店です）。\n"
            .'産地から届いた生豆を、毎週木曜に少しずつ焼いています。焼いてから 3 日目くらいが、いちばん香りの立つころ。'
            .'豆のまま・挽いてからのどちらでもお届けします。月に 2 回の焙煎教室もひらいています。';
    }

    protected function company(): array
    {
        return [
            'seller' => $this->names()[0],
            'person' => '坂上 灯',
            'address' => '〒000-0000 架空県 月見坂市 坂の上 1-2-3',
            'phone' => '000-0000-0000',
            'email' => 'demo-coffee@example.jp',
        ];
    }

    protected function products(): array
    {
        $beans = static fn (string $key, int $stock): array => [
            'options' => self::GRIND,
            'variants' => [
                '豆のまま' => ['sku' => 'DEMO-COFFEE-'.strtoupper($key).'-BEAN', 'stock' => $stock],
                '中挽き（ドリップ）' => ['sku' => 'DEMO-COFFEE-'.strtoupper($key).'-DRIP', 'stock' => $stock],
                '細挽き（エスプレッソ）' => ['sku' => 'DEMO-COFFEE-'.strtoupper($key).'-ESPRESSO', 'stock' => $stock],
            ],
            'tax_class' => 'reduced',
        ];
        $food = ['tax_class' => 'reduced'];

        return [
            'beans-1kg' => ['業務用 ハウスブレンド 1kg', 6800, 8, 'beans', ['beans-1kg'],
                'お店や事務所で使う方に。いつものブレンドを 1kg の袋で。', "いつものハウスブレンドを 1kg の袋でお届けします。豆のままの発送です。\n\n開けたあとは、口を閉じて冷暗所で。2 週間ほどで飲みきる量がおすすめです。",
                [['label' => '内容量', 'value' => '1kg'], ['label' => '焙煎度', 'value' => '中深煎り']], ['sku' => 'DEMO-COFFEE-1KG'] + $food],
            'mug' => ['白いマグカップ', 2200, 20, 'tools', ['mug'],
                '厚手で冷めにくい、白い陶器のマグ。', '厚手で冷めにくい、白い陶器のマグです。電子レンジ・食洗機に使えます。',
                [['label' => '容量', 'value' => '280ml']], ['sku' => 'DEMO-COFFEE-MUG', 'addons' => [['kind' => 'text', 'name' => '名入れ（ローマ字）', 'max_length' => 10, 'amount' => 770]]]],
            'drip-gift' => ['ドリップバッグのギフト箱 20 袋', 3400, 15, 'gift', ['drip-gift', 'bag-kraft'],
                '4 種のドリップバッグを 5 袋ずつ。贈り物に。', "4 種の豆のドリップバッグを 5 袋ずつ、箱に詰めました。カップにかけて、お湯を注ぐだけです。\n\nのしは付けられませんが、ギフトカードと一緒に贈ることもできます。",
                [['label' => '内容量', 'value' => '10g × 20 袋'], ['label' => '賞味期限', 'value' => '製造から 6 か月']], ['sku' => 'DEMO-COFFEE-DRIP-GIFT', 'addons' => [['kind' => 'toggle', 'name' => 'メッセージカード', 'amount' => 110]]] + $food],
            'drip-bag' => ['ドリップバッグ 10 袋', 1600, 30, 'drip', ['drip-bag'],
                'ハウスブレンドのドリップバッグ。', 'ハウスブレンドのドリップバッグです。旅先や職場でも、いれたての香りを。',
                [['label' => '内容量', 'value' => '10g × 10 袋']], ['sku' => 'DEMO-COFFEE-DRIP-10'] + $food],
            'class-drip' => ['ハンドドリップ教室（60 分）', 3000, 0, 'class', ['class-drip'],
                'お湯の温度と注ぎ方で、味が変わるのを確認します。', "お湯の温度と注ぎ方で、同じ豆の味がどう変わるかを確認する 60 分の教室です。道具はお店で用意します。\n\n日時の枠から選んで予約し、お支払いは予約の時に済ませます（デモでは代金は動きません）。前日まではマイページから取り消せます。",
                [['label' => '時間', 'value' => '60 分'], ['label' => '定員', 'value' => '1 回 4 名']], ['kind' => 'booking', 'sku' => 'DEMO-COFFEE-CLASS-DRIP']],
            'class-roast' => ['はじめての焙煎教室（90 分）', 4500, 0, 'class', ['class-roast'],
                '小さな焙煎機で、自分の豆を焼いて持ち帰ります。', "小さな焙煎機で 200g の生豆を焼き、焼いた豆を持ち帰っていただきます。はじめての方向けの 90 分の教室です。\n\n日時の枠から選んで予約し、お支払いは予約の時に済ませます（デモでは代金は動きません）。",
                [['label' => '時間', 'value' => '90 分'], ['label' => '定員', 'value' => '1 回 3 名'], ['label' => '持ち帰り', 'value' => '焼いた豆 約 170g']], ['kind' => 'booking', 'sku' => 'DEMO-COFFEE-CLASS-ROAST']],
            'beans-subscription' => ['豆の定期便（200g × 2 種）', 3200, 40, 'beans', ['beans-subscription', 'cups-three'],
                'その月の豆を 2 種。定期便は 10% 引き。', "焙煎所がその月に選んだ豆を 2 種（各 200g）お届けします。毎週・2 週ごと・毎月から選べ、定期便の値段は 10% 引きです。\n\n次のお届けのスキップ・周期の変更・止めるは、マイページからいつでもできます。",
                [['label' => '内容量', 'value' => '200g × 2 種'], ['label' => '挽き方', 'value' => '豆のまま']], ['sku' => 'DEMO-COFFEE-SUBSCRIPTION'] + $food],
            'beans-trial' => ['お試し 3 種セット（各 100g）', 2480, 25, 'beans', ['beans-trial'],
                '浅煎り・中煎り・深煎りを少しずつ。', 'はじめての方に。浅煎り・中煎り・深煎りを 100g ずつ詰めました。好みの焼き加減を見つけてください。',
                [['label' => '内容量', 'value' => '100g × 3 種']], $beans('trial', 12)],
            'beans-decaf' => ['カフェインレス コロンビア 200g', 1880, 18, 'beans', ['beans-decaf'],
                '夜でも安心の、水で抜いたカフェインレス。', '薬品を使わず、水でカフェインを抜いた豆です。甘い香りと、まろやかな苦み。',
                [['label' => '内容量', 'value' => '200g'], ['label' => '焙煎度', 'value' => '中煎り']], $beans('decaf', 8)],
            'beans-mandheling' => ['マンデリン 深煎り 200g', 1680, 30, 'beans', ['beans-mandheling'],
                'どっしりした苦みと、黒糖のような甘さ。', 'どっしりした苦みと、黒糖のような甘さ。ミルクにもよく合います。',
                [['label' => '内容量', 'value' => '200g'], ['label' => '焙煎度', 'value' => '深煎り']], $beans('mandheling', 10)],
            'beans-brazil' => ['ブラジル 中煎り 200g', 1580, 30, 'beans', ['beans-brazil'],
                'ナッツとチョコレートの、毎日の一杯。', 'ナッツとチョコレートのような香り。毎日飲んでも飽きない、まるい味です。',
                [['label' => '内容量', 'value' => '200g'], ['label' => '焙煎度', 'value' => '中煎り']], $beans('brazil', 10)],
            'beans-ethiopia' => ['エチオピア 浅煎り 200g', 1780, 30, 'beans', ['beans-ethiopia'],
                '紅茶のような、花と柑橘の香り。', "花と柑橘の香りの、紅茶のような浅煎りです。\n\nお湯は少し低めの 88℃ くらいで。",
                [['label' => '内容量', 'value' => '200g'], ['label' => '焙煎度', 'value' => '浅煎り']], $beans('ethiopia', 10)],
            'beans-house' => ['森の窓ブレンド 200g', 1480, 40, 'beans', ['beans-house'],
                'いちばん人気の、焙煎所のいつもの味。', "中南米の豆をあわせた、焙煎所のいつもの味です。苦みと甘みのつり合いがよく、どんないれ方にも合います。\n\n挽き方は、豆のまま・中挽き（ドリップ）・細挽き（エスプレッソ）から選べます。",
                [['label' => '内容量', 'value' => '200g'], ['label' => '焙煎度', 'value' => '中深煎り']], $beans('house', 15)],
        ];
    }

    protected function alts(): array
    {
        return [
            'beans-1kg' => '麻袋に入ったコーヒー豆', 'mug' => 'コーヒーを注いだ白いマグカップ', 'drip-gift' => 'コーヒーの袋とカップ', 'bag-kraft' => '茶色の紙の袋',
            'drip-bag' => 'ドリップにお湯を注ぐところ', 'class-drip' => 'ドリップでいれるコーヒー', 'class-roast' => '焙煎機の中の豆', 'beans-subscription' => '袋に入ったコーヒー豆',
            'cups-three' => '3 つの白いカップ', 'beans-trial' => '白い台に置いたコーヒー豆', 'beans-decaf' => 'さじにのせたコーヒー豆', 'beans-mandheling' => '黒い台のコーヒー豆',
            'beans-brazil' => '焙煎したコーヒー豆', 'beans-ethiopia' => '白い器に入ったコーヒー豆', 'beans-house' => 'コーヒー豆',
        ];
    }

    public function categories(): array
    {
        return ['beans' => 'コーヒー豆', 'drip' => 'ドリップバッグ', 'gift' => '贈り物', 'class' => '教室', 'tools' => '道具'];
    }

    public function media(): array
    {
        return [
            'hero' => ['hero', '焙煎機から落ちるコーヒー豆'],
            'seasonal' => ['seasonal', '枝になったコーヒーの実'],
            'look-farm' => ['look-farm', 'コーヒーの木と赤い実'],
            'look-roast' => ['look-roast', '生豆と焙煎した豆'],
        ];
    }

    public function themeSections(): array
    {
        return [
            'announcement' => ['values' => ['text' => 'デモのお店です（架空の焙煎所）。豆の定期便は 10% 引き・¥5,000 以上で送料無料']],
            'hero' => ['values' => ['kicker' => 'COFFEE ROASTERY', 'heading' => "焼きたての豆を、\nいつもの朝に。", 'body' => '坂の上の小さな焙煎所です。毎週木曜に焼いた豆を、定期便でお届けします。', 'image' => 'media:hero']],
            'looks' => ['items' => [
                ['title' => '産地のはなし', 'image' => 'media:look-farm', 'link' => '/news'],
                ['title' => '焙煎教室', 'image' => 'media:look-roast', 'link' => '/categories/class'],
            ]],
            'categories' => ['values' => ['list' => ['category:beans', 'category:drip', 'category:gift', 'category:class', 'category:tools']]],
            'new_arrivals' => [],
            'favorites' => ['values' => ['products' => ['source' => 'pick', 'ids' => ['listing:beans-house', 'listing:beans-subscription', 'listing:beans-trial', 'listing:drip-gift']]]],
            'seasonal' => ['values' => [
                'label' => 'SUBSCRIPTION', 'heading' => "豆が切れる前に、\n届く定期便。",
                'body' => '毎週・2 週ごと・毎月から選べます。スキップや周期の変更は、マイページからいつでも。',
                'highlight' => '定期便は 10% 引き', 'note' => 'デモのお店です。代金は動きません。', 'image' => 'media:seasonal',
            ]],
            'promises' => ['items' => [
                ['icon' => 'shipping', 'title' => '焼いて 3 日以内に発送', 'body' => '¥5,000 以上で送料無料'],
                ['icon' => 'support', 'title' => 'いれ方のご相談', 'body' => 'お問い合わせからどうぞ'],
                ['icon' => 'secure', 'title' => 'カード情報は店に残りません', 'body' => '決済代行の画面でお支払い'],
            ]],
        ];
    }

    public function photos(): array
    {
        return [
            'hero' => ['id' => 'Jmc0PORTQ-A', 'author' => 'Gregory Hayes', 'page' => 'https://unsplash.com/photos/time-lapse-photography-of-coffee-beans-grind-Jmc0PORTQ-A'],
            'seasonal' => ['id' => 'h28p96ICizo', 'author' => 'Clint McKoy', 'page' => 'https://unsplash.com/photos/round-green-and-red-berries-h28p96ICizo'],
            'look-farm' => ['id' => 'JqF4IS65xEg', 'author' => 'Dang Cong', 'page' => 'https://unsplash.com/photos/red-round-fruits-on-green-leaves-JqF4IS65xEg'],
            'look-roast' => ['id' => 'myPzH34VYK4', 'author' => 'nousnou iwasaki', 'page' => 'https://unsplash.com/photos/brown-coffee-beans-myPzH34VYK4'],
            'beans-house' => ['id' => 'TD4DBagg2wE', 'author' => 'Mike Kenneally', 'page' => 'https://unsplash.com/photos/coffee-bean-lot-TD4DBagg2wE'],
            'beans-ethiopia' => ['id' => 'lGl3spVIU0g', 'author' => 'Alin Luna', 'page' => 'https://unsplash.com/photos/brown-coffee-beans-on-white-ceramic-bowl-lGl3spVIU0g'],
            'beans-brazil' => ['id' => '4KqHNQ3sJ6g', 'author' => 'Fatih Mehmet YILDIZ', 'page' => 'https://unsplash.com/photos/roasted-brown-coffee-beans-4KqHNQ3sJ6g'],
            'beans-mandheling' => ['id' => 'fmc-tFMMiBs', 'author' => 'Łukasz Rawa', 'page' => 'https://unsplash.com/photos/brown-coffee-beans-on-black-surface-fmc-tFMMiBs'],
            'beans-decaf' => ['id' => 'OgJYC8q7QSE', 'author' => 'Wojciech Pacześ', 'page' => 'https://unsplash.com/photos/a-spoon-full-of-coffee-beans-on-top-of-a-table-OgJYC8q7QSE'],
            'beans-trial' => ['id' => 'm7ijvnNGaV0', 'author' => 'Mockup Graphics', 'page' => 'https://unsplash.com/photos/brown-coffee-beans-on-white-surface-m7ijvnNGaV0'],
            'drip-bag' => ['id' => 'a0v-gPTSopg', 'author' => 'Marie G.', 'page' => 'https://unsplash.com/photos/hot-water-pouring-into-a-pour-over-coffee-filter-a0v-gPTSopg'],
            'drip-gift' => ['id' => 'PGctPZuz65I', 'author' => 'Point Normal', 'page' => 'https://unsplash.com/photos/a-coffee-bag-and-a-cup-of-coffee-on-a-pink-background-PGctPZuz65I'],
            'beans-subscription' => ['id' => '7QlJ79Cj43w', 'author' => 'Nadia Valko', 'page' => 'https://unsplash.com/photos/brown-coffee-beans-inside-white-sack-7QlJ79Cj43w'],
            'class-roast' => ['id' => '0vB20AT_39o', 'author' => 'Nathan Dumlao', 'page' => 'https://unsplash.com/photos/brown-coffee-beans-on-round-container-0vB20AT_39o'],
            'class-drip' => ['id' => 'LR_ZHJtlRFE', 'author' => 'Art Rachen', 'page' => 'https://unsplash.com/photos/white-ceramic-cup-with-brown-liquid-LR_ZHJtlRFE'],
            'mug' => ['id' => 'Zgq3cqztoLI', 'author' => 'Andrew Neel', 'page' => 'https://unsplash.com/photos/white-ceramic-mug-with-coffee-Zgq3cqztoLI'],
            'beans-1kg' => ['id' => 'obV_LM0KjxY', 'author' => 'Tina Guina', 'page' => 'https://unsplash.com/photos/shallow-focus-photography-of-coffee-beans-in-sack-obV_LM0KjxY'],
            'bag-kraft' => ['id' => 'wizWrRZJXSg', 'author' => 'With Mahdy', 'page' => 'https://unsplash.com/photos/a-brown-paper-bag-sitting-on-top-of-a-white-table-wizWrRZJXSg'],
            'cups-three' => ['id' => 'vbt-Fp3b5FA', 'author' => 'Nathan Dumlao', 'page' => 'https://unsplash.com/photos/three-white-ceramic-mugs-vbt-Fp3b5FA'],
        ];
    }

    public function apps(): array
    {
        return [
            'subscription' => [],
            'booking' => [],
            'gift-cards' => ['denominations' => '3000,5000,10000'],
            'reviews' => ['publish' => 'manual'],
            'blog' => [],
            'favorites' => [],
        ];
    }

    public function panels(): array
    {
        $class = static fn (string $rules, string $capacity, string $place): array => ['booking' => [
            'rules' => $rules, 'capacity' => $capacity, 'weeks' => 'w8', 'book_until' => 'day_before', 'payment' => 'prepay',
            'cancel_until' => 'day_before', 'place' => $place, 'per_member' => '2',
        ]];

        return [
            'beans-subscription' => ['subscription' => ['enabled' => true, 'interval_weekly' => true, 'interval_biweekly' => true, 'interval_monthly' => true, 'discount' => 'pct10']],
            'beans-house' => ['subscription' => ['enabled' => true, 'interval_biweekly' => true, 'interval_monthly' => true, 'discount' => 'pct5']],
            'class-roast' => $class('土 10:00-11:30 / 日 14:00-15:30', '3', '焙煎所の奥の教室（架空）'),
            'class-drip' => $class('水 19:00-20:00 / 土 13:00-14:00', '4', '焙煎所のカウンター（架空）'),
        ];
    }

    public function coupons(): array
    {
        return [
            ['code' => 'WELCOME10', 'name' => 'はじめてのご注文 10% 引き', 'kind' => 'percent', 'value' => 10, 'max_discount' => 1000, 'target' => 'all', 'per_customer_limit' => 1, 'members_only' => false],
            ['method' => 'automatic', 'label' => '豆を 3 袋以上で 5% 引き', 'name' => '豆のまとめ買い', 'kind' => 'percent', 'value' => 5, 'target' => 'categories', 'target_ids' => ['category:beans'], 'min_quantity' => 3, 'members_only' => false],
        ];
    }

    public function posts(): array
    {
        return [
            ['title' => '今月の定期便は、エチオピアとブラジル', 'slug' => 'subscription-this-month', 'app' => 'subscription', 'excerpt' => '定期便の今月の豆と、おすすめのいれ方。',
                'body' => [['type' => 'paragraph', 'text' => '今月の定期便は、花の香りのエチオピアと、ナッツのようなブラジルの 2 種です（デモのお知らせです）。'], ['type' => 'paragraph', 'text' => 'お届けの周期の変更・スキップは、マイページの「定期便」からいつでもできます。']]],
            ['title' => '焙煎教室の日程を追加しました', 'slug' => 'class-schedule', 'app' => 'booking', 'excerpt' => '土曜の午前と日曜の午後に、はじめての焙煎教室。',
                'body' => [['type' => 'paragraph', 'text' => '土曜の午前と日曜の午後に、はじめての焙煎教室の枠を追加しました。予約の画面から日時を選んでください（デモのお知らせです）。']]],
            ['title' => 'ギフトカードを始めました', 'slug' => 'gift-cards', 'excerpt' => '¥3,000 から。メールで贈れます。',
                'body' => [['type' => 'paragraph', 'text' => 'このお店で使えるギフトカードを始めました。¥3,000・¥5,000・¥10,000 から選んで、贈りたい方のメールアドレスへ届けられます（デモでは届かず、画面の上の「送るはずだったメール」に出ます）。']]],
        ];
    }

    public function settings(): array
    {
        return ['points' => ['enabled' => true, 'rate_bp' => 300]];
    }
}
