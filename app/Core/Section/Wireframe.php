<?php

declare(strict_types=1);

namespace App\Core\Section;

/**
 * 区画のパターンの「ワイヤーの絵」の語彙（ADR 0039）。編集の画面の「＋ 区画を足す」で、パターンを文字でなく絵で選ばせる。
 * 絵そのものは核（管理画面の部品 resources/admin/components/sections/wireframes.js）が持ち、テーマは theme.json で名前を選ぶだけ
 * （サードパーティのテーマはコードを持ち込まない。ADR 0020。SVG も持ち込ませない）。知らない名前は登録で断る。
 * 書かなかったパターンは、編集の画面が汎用の絵と名前で出す。
 *
 * 名前を足すときは、ここと wireframes.js の両方に足す（WireframeTest が 2 つの一覧がそろっていることを見る）。
 * 名前を消す・変えると、その名前を書いたテーマが登録できなくなるので、消さずに残す
 */
final class Wireframe
{
    /** 名前 => 何の絵か（docs/design/theme-api.md の一覧と同じ） */
    public const array NAMES = [
        // お知らせの帯
        'announcement-bar' => 'ページの上の細いバーに 1 行の文',
        // ヒーロー
        'hero-full' => '写真が幅いっぱい、文字を写真に重ねる',
        'hero-split' => '写真と文を左右に',
        'hero-grid' => '写真のグリッドと文',
        'hero-band' => '写真だけのバナーの下に説明の文',
        'hero-panel' => '上に写真、下に色の背景と文字',
        // 商品の並び
        'products-grid' => '商品カードを 4〜6 列で',
        'products-large' => '大きな商品カードを 2 列で',
        'products-scroll' => '商品カードが横に流れる',
        'products-side' => '左に見出し、右に商品カード',
        // カテゴリ
        'categories-cards' => '写真のカード（四角）',
        'categories-circles' => '丸い写真と名前',
        'categories-bento' => '大小の枠を組み合わせたグリッド（ベント）',
        'categories-links' => '文字のリンクの並び',
        // 写真のある特集の並び（コーディネートなど、繰り返しの組）
        'features-stack' => '写真と文のセットを縦に積む',
        'features-two' => '写真と文のセットを 2 列で',
        'features-three' => '写真と文のセットを 3 列で',
        'features-scroll' => '写真と文のセットが横に流れる',
        'features-band' => '色の背景に文と写真の並び',
        // 写真と文の 1 組（季節の特集など）
        'feature-split' => '写真が左、文が右',
        'feature-split-reverse' => '文が左、写真が右',
        'feature-stack' => '写真が上、文が下',
        'feature-overlay' => '写真が幅いっぱい、文を重ねる',
        'feature-overlap' => '写真に文の枠が一部重なる',
        // 安心の一行
        'promises-row' => '小さなアイコンと文を 1 行に並べる',
        'promises-cards' => 'アイコンと文のカードを複数の列で',
        'promises-dark' => '濃い色の背景に、アイコンと文を 1 行で',
        // フッター
        'footer-columns' => 'リンクを複数の列で',
        'footer-dark' => '濃い色の背景に、リンクを複数の列で',
        'footer-line' => 'リンクを 1 行で',
        'footer-cta' => '濃い色の背景に、呼びかけの文とリンク',
        // 文・写真・ボタン（核の汎用の区画にも使う）
        'text-left' => '見出しと文を左寄せ',
        'text-center' => '見出しと文を中央に',
        'image-wide' => '写真を幅いっぱい',
        'image-narrow' => '写真を本文と同じ幅で',
        'button-center' => 'ボタンを中央に',
        'button-left' => 'ボタンを左寄せ',
        // 核の共通の区画（見本帳の形）
        'testimonials-cards' => 'お客さんの声のカードを 3 列で',
        'testimonials-quote' => 'お客さんの声を大きな引用で',
        'testimonials-scroll' => 'お客さんの声のカードが横に流れる',
        'faq-list' => '質問を開閉式の 1 列で',
        'faq-side' => '左に見出し、右に質問の並び',
        'benefits-three' => 'アイコンと見出しと文を 3 列で',
        'benefits-four' => 'アイコンと見出しと文を 4 列で',
        'benefits-list' => 'アイコンと見出しと文を 2 列のリストで',
        'cta-band' => '色の背景に見出しとボタン',
        'cta-photo' => '写真の上に見出しとボタン',
        'gallery-grid' => '写真のグリッド',
        'gallery-masonry' => '高さの違う写真をメイソンリーで',
        'gallery-scroll' => '写真が横に流れる',
        'product-split' => '1 つの商品の写真が左、名前と価格とカートが右',
        'product-full' => '1 つの商品の写真が全面、名前とカートの箱を重ねる',
        'entries-two' => '写真のリンクカードを 2 つ並べる',
        'entries-three' => '写真のリンクカードを 3 つ並べる',
        'story-longform' => '写真と長い文',
        'story-timeline' => '年と出来事の年表',
        // 核の共通の区画の残り（見本帳の「次に」）
        'numbers-row' => '大きな数字と説明を 3 つ並べる',
        'numbers-photo' => '写真が左、数字と説明を 2 × 2 で右に',
        'logos-row' => 'ロゴを 1 行に並べる',
        'logos-grid' => 'ロゴをグリッドに並べる',
        'steps-row' => '番号の丸を線でつなぎ、手順を横に並べる',
        'steps-line' => '左に見出し、右に番号の丸を縦の線でつないだ手順',
        'table-plain' => '見出しの行がある表',
        'table-photo' => '写真が左、サイズ表が右',
        'spacer-space' => 'セクションの間を空ける（上下の矢印）',
        'spacer-line' => 'セクションの間に線を 1 本',
        // 値段の表
        'pricing-cards' => '値段のカードを 3 つ横に並べる（名前・値段・箇条書き・ボタン）',
        'pricing-featured' => '中央のおすすめのカードを大きく濃い色の背景で、両側に小さなカード',
        'pricing-table' => 'プランを列にした表（値段の行・含むものの行・ボタンの行）',
        // App の区画（ADR 0040。動画・地図の App）
        'video-wide' => '動画を幅いっぱい、中央に再生ボタン',
        'video-narrow' => '見出しと、本文と同じ幅の動画',
        'map-wide' => '地図を幅いっぱい、場所にピン',
        'map-split' => '地図が左、見出しと案内の文が右',
        // カスタム区画
        'html-narrow' => 'HTML の記号（</>）と見出し・文・2 列の枠を本文と同じ幅で',
        'html-wide' => 'HTML の記号（</>）と見出し・文・2 列の枠を幅いっぱいに',
        'richtext-narrow' => '書式のツールバーと見出し・太字・リストの文を本文と同じ幅で',
        'richtext-wide' => '書式のツールバーと見出し・太字・リストの文を幅いっぱいに',
    ];

    public static function known(string $name): bool
    {
        return array_key_exists($name, self::NAMES);
    }
}
