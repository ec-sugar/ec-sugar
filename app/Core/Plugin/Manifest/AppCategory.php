<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

/**
 * App の目的（管理画面の App の画面で、目的ごとのまとまりに札で並べる）。
 * 核が決めた言葉だけ。App はマニフェスト（composer.json の extra.ec-sugar.category）に 1 つ書き、知らない言葉は登録で断る。
 * 書かない App（マニフェストを渡さないプラグインも）は「そのほか」。
 * 並びは画面に出す順（集客から店の運営まで。買取・定期・予約などの売り方の App が増えても、まとまりを足すだけで崩れない）。
 * 言葉を足すのは核の変更（ADR 0025 の「作って決めたこと」に書く）。消す・意味を変えるとマニフェストが読めなくなるので、しない
 */
enum AppCategory: string
{
    case Analytics = 'analytics';   // アクセス解析（GA4・Clarity）
    case Marketing = 'marketing';   // 集客と広告（広告の計測タグ・SNS・メールでのお知らせ）
    case Page = 'page';             // ページの部品（動画・地図・よくある質問など、ページに足す区画）
    case Selling = 'selling';       // 売り方（定期便・予約・買取・まとめ買い）
    case Products = 'products';     // 商品と在庫
    case Payment = 'payment';       // お支払い
    case Shipping = 'shipping';     // 配送
    case Customers = 'customers';   // お客さん（会員・レビュー・問い合わせ）
    case Operations = 'operations'; // 店の運営（会計・帳票・スタッフの仕事）
    case Other = 'other';           // そのほか（書かなければこれ）

    public function label(): string
    {
        return match ($this) {
            self::Analytics => 'アクセス解析',
            self::Marketing => '集客と広告',
            self::Page => 'ページの部品',
            self::Selling => '売り方',
            self::Products => '商品と在庫',
            self::Payment => 'お支払い',
            self::Shipping => '配送',
            self::Customers => 'お客さん',
            self::Operations => '店の運営',
            self::Other => 'そのほか',
        };
    }

    /**
     * まとまりの見出しの下に添える一言（お店の人向けのやさしい言葉）
     */
    public function description(): string
    {
        return match ($this) {
            self::Analytics => 'どのページがよく見られているかを数え、ショップを良くするために使います。',
            self::Marketing => '広告から来たお客さんや購入を数え、お客さんを呼ぶために使います。',
            self::Page => 'ページの編集の画面で、動画・地図・よくある質問などのセクションを足せます。',
            self::Selling => '定期便・予約・買取など、売り方を増やします。',
            self::Products => '商品と在庫の管理を助けます。',
            self::Payment => 'お支払いの方法を増やします。',
            self::Shipping => '配送と送料の扱いを増やします。',
            self::Customers => 'お客さんとのやり取りを助けます。',
            self::Operations => '会計や帳票など、店の運営を助けます。',
            self::Other => 'ほかのまとまりに入らない App です。',
        };
    }
}
