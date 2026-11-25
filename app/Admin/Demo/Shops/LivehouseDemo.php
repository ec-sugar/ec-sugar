<?php

declare(strict_types=1);

namespace App\Admin\Demo\Shops;

/**
 * デモの店「ライブハウス」（ADR 0086）。テーマは fashion。
 * 見せる売り方: デジタルチケット（公演・区分・席。会員だけ・動く QR）・10 周年公演の抽選・グッズ・音源のダウンロード販売・会員ランク（会員だけのグッズ）・
 * 公式の定価リセールの抽選（ADR 0069 の「リセール」）。
 * リセールの抽選は、ふつうの在庫が売り切れた区分にだけ申し込めて、抽選販売の受付中の商品には出ない。そこで 10 周年公演（受付は 21 日先まで）でなく、
 * 売り切れた対バン（friday。在庫 0）でリセールを使うにして、来訪者が「リセールの抽選に申し込む」を試せるようにする。
 * 売り切れなので誰もチケットを持たず、リセールに出ているチケットは無い（毎時の抽選は走っても当てる物が無く、請求しない。毎時の戻しも在庫 0 のまま）
 */
final class LivehouseDemo extends BaseDemo
{
    private const string VENUE = 'ライブハウス 夜鷹座（架空）';

    public function kind(): string
    {
        return 'livehouse';
    }

    public function names(): array
    {
        return ['ライブハウス 夜鷹座', 'ホール・ノクターン', 'ライブバー 三日月堂'];
    }

    public function theme(): string
    {
        return 'fashion';
    }

    public function pitch(): string
    {
        return "ライブハウスの課題は、紙のチケットの手配と当日の受付、それから人気の公演の買い占めと高い値段での転売でした。\n"
            .'EC-SUGAR では、チケットは会員だけが買え、スマホに出る動く QR で入場します（スクリーンショットは使えません）。席は当日まで知らせずに区分の中で割り当て、'
            .'人気の公演は抽選にできます。行けなくなったチケットはお店の定価のリセールで、買う方を抽選で決めて譲れます。グッズと音源（ダウンロード）も同じカートで売れます。';
    }

    public function story(): string
    {
        return "駅から歩いて 3 分、地下にある 200 人ほどのライブハウスです（架空のお店です）。\n"
            .'週末はワンマンと対バン、平日はアコースティックの夜。出演者の物販と、ライブの音源の配信もしています。'
            .'このお店の出演者・公演は、どれも架空です。';
    }

    protected function company(): array
    {
        return [
            'seller' => '株式会社 夜鷹座（架空）',
            'person' => '夜鷹 響',
            'address' => '〒000-0000 架空県 三日月市 駅前通り 10-11 地下 2 階',
            'phone' => '000-0000-0000',
            'email' => 'demo-livehouse@example.jp',
        ];
    }

    protected function products(): array
    {
        $ticketOne = static fn (string $sku): array => ['kind' => 'ticket', 'sku' => $sku];

        return [
            'members-tote' => ['会員だけの先行トート', 2800, 30, 'goods', ['tote-beige'],
                '会員の方だけに先に。生成りの帆布トート。', "会員の方だけに見える、先行販売のトートです（ログインしていない方には一覧にも出ません）。\n\nデモの会員でログインすると見られます。",
                [['label' => '大きさ', 'value' => '縦 38 × 横 36cm']], ['sku' => 'DEMO-LIVE-MEMBERS-TOTE']],
            'single' => ['シングル「三日月の下で」（ダウンロード）', 250, 0, 'music', ['vinyl-red'],
                'ハルカゼ楽団（架空）の新しいシングル。', "ハルカゼ楽団（架空のバンド）の新しいシングルです。お支払いの確認が取れると、ご注文の画面・マイページ・メールにダウンロードのリンクが出ます。\n\nデモの音源は、4 秒の無音の見本です。",
                [['label' => '形', 'value' => 'WAV（見本）'], ['label' => 'ダウンロード', 'value' => '30 日・5 回まで']], ['kind' => 'download', 'sku' => 'DEMO-LIVE-SINGLE']],
            'live-album' => ['ライブ音源「夜鷹座の夏」（ダウンロード）', 1200, 0, 'music', ['turntable', 'player'],
                'この夏のワンマンを、そのまま。歌詞カード付き。', "この夏のワンマンの音源と、歌詞カード（文字）をお届けします。お支払いの確認が取れると、ダウンロードのリンクが出ます。\n\nデモの音源は、4 秒の無音の見本です。",
                [['label' => '形', 'value' => 'WAV（見本）・歌詞カード（文字）']], ['kind' => 'download', 'sku' => 'DEMO-LIVE-ALBUM']],
            'record' => ['7 インチ レコード「夜鷹」', 1800, 40, 'music', ['vinyl-white'],
                'ジャケット付きの 7 インチ。', 'ハルカゼ楽団（架空）の 7 インチです。会場でも売っています。',
                [['label' => '形', 'value' => '7 インチ・45 回転']], ['sku' => 'DEMO-LIVE-RECORD']],
            'towel' => ['マフラータオル', 1800, 60, 'goods', ['crowd-hands'],
                'ライブで振りまわせる、細長いタオル。', 'ライブで振りまわせる、細長いタオルです。',
                [['label' => '大きさ', 'value' => '20 × 110cm']], ['sku' => 'DEMO-LIVE-TOWEL']],
            'tote' => ['ロゴ トートバッグ', 2200, 50, 'goods', ['tote-white'],
                'A4 が入る、厚手の帆布。', 'A4 が入る厚手の帆布のトートです。',
                [['label' => '大きさ', 'value' => '縦 38 × 横 36cm']], ['sku' => 'DEMO-LIVE-TOTE']],
            'tshirt' => ['ロゴ T シャツ', 3500, 0, 'goods', ['tshirt-black', 'tshirt-white'],
                '黒と白の 2 色。S から XL まで。', '夜鷹座のロゴの T シャツです。色とサイズを選べます。',
                [['label' => '素材', 'value' => '綿 100%']], [
                    'options' => [['name' => '色', 'values' => ['黒', '白']], ['name' => 'サイズ', 'values' => ['S', 'M', 'L', 'XL']]],
                    'variants' => [
                        '黒 / S' => ['sku' => 'DEMO-LIVE-TEE-BLK-S', 'stock' => 10], '黒 / M' => ['sku' => 'DEMO-LIVE-TEE-BLK-M', 'stock' => 15],
                        '黒 / L' => ['sku' => 'DEMO-LIVE-TEE-BLK-L', 'stock' => 15], '黒 / XL' => ['sku' => 'DEMO-LIVE-TEE-BLK-XL', 'stock' => 5],
                        '白 / S' => ['sku' => 'DEMO-LIVE-TEE-WHT-S', 'stock' => 8], '白 / M' => ['sku' => 'DEMO-LIVE-TEE-WHT-M', 'stock' => 12],
                        '白 / L' => ['sku' => 'DEMO-LIVE-TEE-WHT-L', 'stock' => 0], '白 / XL' => ['sku' => 'DEMO-LIVE-TEE-WHT-XL', 'stock' => 3],
                    ],
                ]],
            'anniversary' => ['〈抽選〉夜鷹座 10 周年記念公演', 5500, 100, 'tickets', ['crowd-orange', 'look-crowd'],
                '10 周年の特別な夜。チケットは抽選です。', "夜鷹座の 10 周年を祝う特別な公演です（出演者は架空です）。チケットは抽選でお受けします。\n\n受付の間に会員の方がカードを預けて申し込み、抽選の日に自動で抽選します。当選した方にだけ請求し、チケットはマイページに出ます。",
                [['label' => '会場', 'value' => self::VENUE], ['label' => '区分', 'value' => 'スタンディング']], $ticketOne('DEMO-LIVE-10TH')],
            // 売り切れ（在庫 0）の公演。公式の定価リセールを使い、来訪者はリセールの抽選に申し込める（panels()）
            'friday' => ['〈対バン〉金曜の夜の三組', 2800, 0, 'tickets', ['look-mic', 'stage-empty'],
                '完売。リセールに出たチケットを抽選でお譲りします。', "金曜の夜の対バンです。出演: ミドリノオト／夜更けのトリオ／ハルカゼ楽団（どれも架空のバンドです）。\n\n"
                    .'チケットは完売しました。行けなくなった方がマイページからお店のリセールに定価で出したチケットを、毎時 0 分ごろの抽選でお譲りします。'
                    ."会員の方がカードを預けて「リセールの抽選に申し込む」から申し込み、当たった時だけお支払いです。\n\n"
                    .'デモのお店では、リセールに出ているチケットはありません（お申し込みまで試せます。当たることはありません）。',
                [['label' => '会場', 'value' => self::VENUE], ['label' => '区分', 'value' => 'スタンディング']], $ticketOne('DEMO-LIVE-FRIDAY')],
            'oneman' => ['〈ワンマン〉ハルカゼ楽団「夜明けまで」', 3800, 0, 'tickets', ['stage-empty', 'look-crowd'],
                'スタンディングと 2 階指定席。席は当日まで分かりません。', "ハルカゼ楽団（架空のバンド）のワンマンです。スタンディングと 2 階指定席から選べます。\n\n2 階指定席の席は、開演の 3 時間前に区分の中で割り当て、チケットの画面に出します（買い占めて良い席を選ぶことができません）。チケットは会員の方だけ、お一人 4 枚まで。",
                [['label' => '会場', 'value' => self::VENUE]], [
                    'kind' => 'ticket',
                    'options' => [['name' => '区分', 'values' => ['スタンディング', '2階指定席']]],
                    'variants' => ['スタンディング' => ['sku' => 'DEMO-LIVE-ONEMAN-STD', 'stock' => 150], '2階指定席' => ['sku' => 'DEMO-LIVE-ONEMAN-SEAT', 'stock' => 40, 'price' => 4800]],
                ]],
        ];
    }

    protected function alts(): array
    {
        return [
            'tote-beige' => '椅子に置いた生成りのトート', 'vinyl-red' => '赤いレコード', 'turntable' => '青いレコードとプレーヤー', 'player' => 'レコードプレーヤー',
            'vinyl-white' => '白いラベルのレコード', 'crowd-hands' => '手を上げる観客の影', 'tote-white' => '扉に掛けた白いトート', 'tshirt-black' => 'ハンガーに掛けた黒い T シャツ',
            'tshirt-white' => 'ハンガーに掛けた白い T シャツ', 'crowd-orange' => '光に照らされた舞台と観客', 'look-mic' => '光の中のマイクスタンド',
            'stage-empty' => '開演前の舞台の楽器とマイク', 'look-crowd' => '照明に向かって手を上げる観客',
        ];
    }

    public function categories(): array
    {
        return ['tickets' => 'チケット', 'goods' => 'グッズ', 'music' => '音源・レコード'];
    }

    public function media(): array
    {
        return [
            'hero' => ['crowd-orange', '光に照らされた舞台と観客の影'],
            'look-crowd' => ['look-crowd', '照明に向かって手を上げる観客'],
            'look-mic' => ['look-mic', '光の中のマイクスタンド'],
            'seasonal' => ['seasonal', '赤い光の舞台のドラム'],
        ];
    }

    public function themeSections(): array
    {
        return [
            'announcement' => ['values' => ['text' => 'デモのお店です（架空のライブハウス・架空の出演者）。チケットは会員の方だけ']],
            'hero' => ['values' => ['heading' => "地下二階の、\n夜の音。", 'body' => '駅から歩いて 3 分、200 人のライブハウスです。チケットはスマホの動く QR で、グッズと音源も同じカートで。', 'image' => 'media:hero']],
            'categories' => ['values' => ['list' => ['category:tickets', 'category:goods', 'category:music']]],
            'new_arrivals' => ['values' => ['heading' => 'これからの公演とグッズ']],
            'looks' => [
                'values' => ['heading' => "ライブの\nある夜", 'body' => 'ワンマン・対バン・アコースティック。今月の夜鷹座です。'],
                'items' => [
                    ['title' => 'ワンマン', 'image' => 'media:look-crowd'],
                    ['title' => 'アコースティックの夜', 'image' => 'media:look-mic'],
                ],
            ],
            'seasonal' => ['values' => [
                'label' => '10TH ANNIVERSARY', 'heading' => "10 周年の夜は、\n抽選で。",
                'body' => '記念公演のチケットは抽選です。受付の間に申し込み、当選した方にだけ請求します。',
                'highlight' => '抽選の受付中', 'note' => 'デモのお店です。代金は動きません。', 'image' => 'media:seasonal',
            ]],
            'favorites' => ['values' => ['products' => ['source' => 'pick', 'ids' => ['listing:oneman', 'listing:anniversary', 'listing:tshirt', 'listing:live-album']]]],
            'promises' => ['items' => [
                ['icon' => 'secure', 'title' => 'チケットは動く QR', 'body' => 'スクリーンショットでは入れません'],
                ['icon' => 'support', 'title' => '席は当日に割り当て', 'body' => '区分の中でランダムに'],
                ['icon' => 'shipping', 'title' => 'グッズは ¥5,000 以上で送料無料', 'body' => '音源はダウンロードで'],
            ]],
        ];
    }

    public function photos(): array
    {
        return [
            'look-crowd' => ['id' => 'eXVd7gDPO9A', 'author' => 'ActionVance', 'page' => 'https://unsplash.com/photos/crowd-facing-lighted-stage-eXVd7gDPO9A'],
            'look-mic' => ['id' => 'l3abtqeY38E', 'author' => 'Matthew Jungling', 'page' => 'https://unsplash.com/photos/black-and-silver-microphone-on-stand-l3abtqeY38E'],
            'seasonal' => ['id' => '5zWN8DTNeVA', 'author' => 'Nick Moore', 'page' => 'https://unsplash.com/photos/red-drum-set-besides-microphone-stands-5zWN8DTNeVA'],
            'stage-empty' => ['id' => 'LHR6tUw8N34', 'author' => 'Magnus Lunay', 'page' => 'https://unsplash.com/photos/musical-instruments-on-hazy-stage-LHR6tUw8N34'],
            'crowd-hands' => ['id' => 'LOV7Gbmgm30', 'author' => 'Kelvin Moquete', 'page' => 'https://unsplash.com/photos/silhouette-of-people-raising-their-hands-LOV7Gbmgm30'],
            'crowd-orange' => ['id' => 'm1WZS5ye404', 'author' => 'Vishnu R Nair', 'page' => 'https://unsplash.com/photos/band-performing-on-stage-in-front-of-people-m1WZS5ye404'],
            'tshirt-black' => ['id' => 'Cs4GVbMqKGY', 'author' => 'Ryan Hoffman', 'page' => 'https://unsplash.com/photos/black-crew-neck-t-shirt-Cs4GVbMqKGY'],
            'tshirt-white' => ['id' => '8ACmRoleM24', 'author' => 'Avtar Singh', 'page' => 'https://unsplash.com/photos/a-plain-white-t-shirt-hangs-on-a-wooden-hanger-8ACmRoleM24'],
            'tote-white' => ['id' => 'AFCo5H6rFEE', 'author' => 'Brando Makes Branding', 'page' => 'https://unsplash.com/photos/a-white-bag-hanging-on-a-white-door-AFCo5H6rFEE'],
            'tote-beige' => ['id' => '6Rh4vvSaiSg', 'author' => 'Simply Mersah', 'page' => 'https://unsplash.com/photos/a-bag-sitting-on-top-of-a-white-chair-next-to-a-plant-6Rh4vvSaiSg'],
            'vinyl-white' => ['id' => 'aZVuQWEtX5Y', 'author' => 'Brett Jordan', 'page' => 'https://unsplash.com/photos/black-vinyl-record-on-white-background-aZVuQWEtX5Y'],
            'vinyl-red' => ['id' => '7RZ_N2r9QQ0', 'author' => 'Andras Vas', 'page' => 'https://unsplash.com/photos/a-close-up-of-a-red-record-on-a-black-surface-7RZ_N2r9QQ0'],
            'turntable' => ['id' => 'aivbDhWuFyw', 'author' => 'Konstantinos Hasandras', 'page' => 'https://unsplash.com/photos/black-and-blue-turntable-aivbDhWuFyw'],
            'player' => ['id' => 'PvqGd3PH6ZE', 'author' => 'Jace & Afsoon', 'page' => 'https://unsplash.com/photos/shallow-focus-photography-of-black-vinyl-player-PvqGd3PH6ZE'],
        ];
    }

    public function apps(): array
    {
        return [
            'tickets' => [],
            'lottery' => [],
            'downloads' => ['days' => '30', 'limit' => '5'],
            'member-ranks' => [
                'ranks' => 'three',
                'rank1_name' => 'メンバー', 'rank1_threshold' => '0', 'rank1_discount' => '0', 'rank1_points' => '0',
                'rank2_name' => 'レギュラー', 'rank2_threshold' => '20000', 'rank2_discount' => '3', 'rank2_points' => '1',
                'rank3_name' => 'VIP', 'rank3_threshold' => '60000', 'rank3_discount' => '5', 'rank3_points' => '2',
            ],
            'blog' => [],
        ];
    }

    public function panels(): array
    {
        $admission = static fn (int $days, string $hours, string $extra = ''): array => [
            'type' => 'admission', 'starts_at' => "+{$days}d ".substr($hours, 0, 5), 'ends_at' => "+{$days}d ".substr($hours, 6, 5),
            'venue' => self::VENUE, 'sales_close_at' => '+'.($days - 1).'d 23:00', 'display_hours' => '3', 'per_member' => '4',
        ];

        return [
            'oneman' => ['tickets' => $admission(21, '19:00-21:30') + ['seats' => '2階指定席: A-1〜A-20, B-1〜B-20', 'seat_hours' => '3']],
            // 公式の定価リセール（手数料と締め切りは既定。買う人は App「抽選販売」の毎時の抽選で決まる）
            'friday' => ['tickets' => $admission(10, '18:30-22:00') + ['resale' => true]],
            'anniversary' => [
                'tickets' => $admission(45, '18:00-21:00') + ['per_member' => '2'],
                'lottery' => ['enabled' => true, 'opens_at' => 'now-1h', 'closes_at' => '+20d 23:00', 'draws_at' => '+21d 12:00', 'winners' => '100', 'per_member' => '2', 'grace_days' => '3'],
            ],
            'live-album' => ['downloads' => ['days' => '30', 'max_count' => '5']],
            'members-tote' => ['member-ranks' => ['visibility' => 'members']],
        ];
    }

    public function files(): array
    {
        return [
            'single' => [['mikazuki.wav', 'wav']],
            'live-album' => [['yotaka-summer.wav', 'wav'], ['lyrics.txt', 'txt:夜鷹座の夏（架空のライブ音源の歌詞カード。デモの見本です）']],
        ];
    }

    public function coupons(): array
    {
        return [
            ['code' => 'YOTAKA10', 'name' => 'グッズ 10% 引き', 'kind' => 'percent', 'value' => 10, 'target' => 'categories', 'target_ids' => ['category:goods'], 'per_customer_limit' => 1, 'members_only' => true],
        ];
    }

    public function posts(): array
    {
        return [
            ['title' => '10 周年記念公演の抽選を受け付けています', 'slug' => '10th-lottery', 'app' => 'lottery', 'excerpt' => '会員の方だけ、お一人 2 枚まで。',
                'body' => [['type' => 'paragraph', 'text' => '10 周年記念公演のチケットの抽選を受け付けています（デモのお知らせです。出演者は架空です）。会員の方だけ、お一人 2 枚まで申し込めます。']]],
            ['title' => 'チケットは動く QR で入場します', 'slug' => 'live-qr', 'app' => 'tickets', 'excerpt' => 'スクリーンショットでは入れません。',
                'body' => [['type' => 'paragraph', 'text' => 'チケットはマイページの「チケット」に出る、30 秒ごとに変わる QR で入場します。スクリーンショットでは入れません。2 枚目からは、同行者のお名前を入れてください。']]],
        ];
    }
}
