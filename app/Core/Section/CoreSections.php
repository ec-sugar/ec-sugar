<?php

declare(strict_types=1);

namespace App\Core\Section;

/**
 * 核の汎用の区画（ADR 0039 区切り 3）と核の共通の区画。自由なページ（私たちについて・キャンペーン・お問い合わせの案内など）と
 * トップに置ける、どのテーマでも描ける区画。テーマが theme.json に pages.page を書かなければ、自由なページはこの区画で作る。
 * テーマは pages の types にこの区画の名前を入れて使ってもよい（核の部品で描くか、テーマが同じ名前の部品で描き直す）。
 *
 * - 名前は basic_ で始まる（テーマは basic_ で始まる区画を宣言できない。SectionSchema::fromManifest）
 * - 欄は核の 7 種類のうち、どの店でも意味のあるものだけ（文・写真・リンク・商品の並び・特長の絵の選択肢）
 * - 描くのは核の部品（resources/views/storefront/sections/{種類}.blade.php）。見た目は核の CSS（resources/storefront/sections.css）が
 *   テーマの色の変数で付ける（テーマのビルドに頼らない）。テーマは views/sections/{種類}/… を置けば描き直せる
 */
final class CoreSections
{
    public const string PREFIX = 'basic_';

    /**
     * 宣言（theme.json の sections と同じ書き方。SectionSchema が同じ決まりで確かめる）。
     * example は編集の画面で区画を足したときに入れておく見本（見本と分かる短い文。写真は入れない）。
     * wireframe は「＋ 区画を足す」に出すワイヤーの絵の名前（Wireframe の語彙）
     */
    private const array DECLARATION = [
        'basic_text' => [
            'label' => '見出しと文',
            'patterns' => ['left' => ['label' => '左寄せ', 'wireframe' => 'text-left'], 'center' => ['label' => '中央', 'wireframe' => 'text-center']],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 80],
                'body' => ['type' => 'textarea', 'label' => '文', 'max' => 2000],
            ],
            'example' => [
                'heading' => '見出しを入力してください',
                'body' => "ここに文を入力してください。\nお店の紹介やお知らせなど、伝えたいことを自由に書けます。",
            ],
        ],
        'basic_image_text' => [
            'label' => '写真と文',
            'patterns' => ['image_left' => ['label' => '写真が左', 'wireframe' => 'feature-split'], 'image_right' => ['label' => '写真が右', 'wireframe' => 'feature-split-reverse']],
            'fields' => [
                'image' => ['type' => 'image', 'label' => '写真'],
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 80],
                'body' => ['type' => 'textarea', 'label' => '文', 'max' => 1000],
                'button_label' => ['type' => 'text', 'label' => 'ボタンの文字', 'max' => 30, 'help' => '空欄ならボタンを表示しません'],
                'button_link' => ['type' => 'link', 'label' => 'ボタンのリンク先', 'help' => 'ショップ内のページ（/products など）か、https:// で始まる URL'],
            ],
            'example' => [
                'heading' => '見出しを入力してください',
                'body' => '写真の横に表示する文です。商品やお店のこだわりを書いてください。',
                'button_label' => 'くわしく見る',
                'button_link' => '/products',
            ],
        ],
        'basic_image' => [
            'label' => '写真',
            'patterns' => ['wide' => ['label' => '横いっぱい', 'wireframe' => 'image-wide'], 'narrow' => ['label' => '本文と同じ幅', 'wireframe' => 'image-narrow']],
            'fields' => [
                'image' => ['type' => 'image', 'label' => '写真'],
                'caption' => ['type' => 'text', 'label' => '写真の説明', 'max' => 100],
            ],
            'example' => ['caption' => '写真の説明を入力してください'],
        ],
        'basic_button' => [
            'label' => 'ボタン',
            'patterns' => ['center' => ['label' => '中央', 'wireframe' => 'button-center'], 'left' => ['label' => '左寄せ', 'wireframe' => 'button-left']],
            'fields' => [
                'label' => ['type' => 'text', 'label' => 'ボタンの文字', 'max' => 30],
                'link' => ['type' => 'link', 'label' => 'リンク先', 'help' => 'ショップ内のページ（/products など）か、https:// で始まる URL'],
            ],
            'example' => ['label' => 'くわしく見る', 'link' => '/products'],
        ],
        'basic_products' => [
            'label' => '商品の並び',
            'wireframe' => 'products-grid',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
                'products' => ['type' => 'products', 'label' => '商品', 'max' => 8],
            ],
            'example' => ['heading' => 'おすすめの商品', 'products' => ['source' => 'new']],
        ],

        // ここから下は「核の共通の区画」（ページを作るのが得意でない人でも、良いページを組めるように）。
        // 世の中のページによくある形を、どのテーマでも描けるように核が持つ。トップと自由なページの両方に置ける（公式テーマの pages の types）。
        // 外とつながるもの（動画・地図・Instagram・メールの登録）は入れない（後で App として作る）
        'basic_testimonials' => [
            'label' => 'お客さんの声',
            'patterns' => [
                'cards' => ['label' => 'カード 3 列', 'wireframe' => 'testimonials-cards'],
                'quote' => ['label' => '大きな引用', 'wireframe' => 'testimonials-quote'],
                'scroll' => ['label' => '横に流れる', 'wireframe' => 'testimonials-scroll'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
            ],
            // 星の数は持たない（作り話のレビューに見えないよう、いただいた言葉と名前だけを載せる）
            'blocks' => [
                'voice' => ['label' => '声', 'fields' => [
                    'quote' => ['type' => 'textarea', 'label' => '声の文', 'max' => 400],
                    'name' => ['type' => 'text', 'label' => 'お名前', 'max' => 40, 'help' => '「K.S. さま」など、ご本人から掲載の許可をもらった形で'],
                    'detail' => ['type' => 'text', 'label' => '属性', 'max' => 40, 'help' => '「30 代・東京都」など。空欄でもかまいません'],
                ]],
            ],
            'max_blocks' => 12,
            'example' => ['heading' => 'お客さんの声'],
            'example_blocks' => [
                ['type' => 'voice', 'values' => ['quote' => 'ここにお客さんの声が入ります。いただいた感想を、そのままの言葉で載せてください。', 'name' => 'お名前', 'detail' => '属性（30 代・会社員など）']],
                ['type' => 'voice', 'values' => ['quote' => 'ここにお客さんの声が入ります。使ってみてよかったところなど。', 'name' => 'お名前', 'detail' => '属性']],
                ['type' => 'voice', 'values' => ['quote' => 'ここにお客さんの声が入ります。', 'name' => 'お名前', 'detail' => '属性']],
            ],
        ],
        'basic_faq' => [
            'label' => 'よくある質問',
            'patterns' => [
                'list' => ['label' => '開閉式の 1 列', 'wireframe' => 'faq-list'],
                'side' => ['label' => '見出しが左', 'wireframe' => 'faq-side'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
                'body' => ['type' => 'textarea', 'label' => '説明', 'max' => 300, 'help' => '見出しの下に表示する短い文。空欄でもかまいません'],
                'button_label' => ['type' => 'text', 'label' => 'ボタンの文字', 'max' => 30, 'help' => '「お問い合わせ」など。空欄ならボタンを表示しません'],
                'button_link' => ['type' => 'link', 'label' => 'ボタンのリンク先', 'help' => 'ショップ内のページ（/products など）か、https:// で始まる URL'],
            ],
            // 開閉は <details>（JavaScript が無くても開く）
            'blocks' => [
                'question' => ['label' => '質問', 'fields' => [
                    'question' => ['type' => 'text', 'label' => '質問', 'max' => 120],
                    'answer' => ['type' => 'textarea', 'label' => '回答', 'max' => 1000],
                ]],
            ],
            'example' => ['heading' => 'よくある質問'],
            'example_blocks' => [
                ['type' => 'question', 'values' => ['question' => '送料はいくらですか？', 'answer' => 'ここに回答を書いてください。']],
                ['type' => 'question', 'values' => ['question' => '届くまで何日かかりますか？', 'answer' => 'ここに回答を書いてください。']],
                ['type' => 'question', 'values' => ['question' => '返品はできますか？', 'answer' => 'ここに回答を書いてください。']],
            ],
        ],
        'basic_features' => [
            'label' => '特長',
            'patterns' => [
                'three' => ['label' => '3 列', 'wireframe' => 'benefits-three'],
                'four' => ['label' => '4 列', 'wireframe' => 'benefits-four'],
                'list' => ['label' => '2 列のリスト', 'wireframe' => 'benefits-list'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
            ],
            'blocks' => [
                'feature' => ['label' => '特長', 'fields' => [
                    // 絵は核が持つ決まった数の中から選ぶ（Lucide。テーマや店の人は SVG を持ち込まない。SectionIcons）
                    'icon' => ['type' => 'choice', 'label' => 'アイコン', 'options' => SectionIcons::OPTIONS],
                    'title' => ['type' => 'text', 'label' => '見出し', 'max' => 40],
                    'text' => ['type' => 'textarea', 'label' => '説明', 'max' => 200],
                ]],
            ],
            'max_blocks' => 12,
            'example' => ['heading' => '選ばれる理由'],
            'example_blocks' => [
                ['type' => 'feature', 'values' => ['icon' => 'leaf', 'title' => '特長の見出し', 'text' => 'ここに特長の説明を書きます。短く 1〜2 行で。']],
                ['type' => 'feature', 'values' => ['icon' => 'hand_heart', 'title' => '特長の見出し', 'text' => 'ここに特長の説明を書きます。']],
                ['type' => 'feature', 'values' => ['icon' => 'truck', 'title' => '特長の見出し', 'text' => 'ここに特長の説明を書きます。']],
            ],
        ],
        'basic_cta' => [
            'label' => 'CTA バナー',
            'patterns' => [
                'band' => ['label' => '色の背景', 'wireframe' => 'cta-band'],
                'photo' => ['label' => '写真の上', 'wireframe' => 'cta-photo'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 80],
                'body' => ['type' => 'textarea', 'label' => '文', 'max' => 300],
                'button_label' => ['type' => 'text', 'label' => 'ボタンの文字', 'max' => 30, 'help' => '空欄ならボタンを表示しません'],
                'button_link' => ['type' => 'link', 'label' => 'ボタンのリンク先', 'help' => 'ショップ内のページ（/products など）か、https:// で始まる URL'],
                'image' => ['type' => 'image', 'label' => '写真', 'help' => '「写真の上」のパターンで背景に表示します'],
            ],
            'example' => [
                'heading' => '呼びかけの見出しを入力してください',
                'body' => 'ここに短い説明を書きます。',
                'button_label' => '商品を見る',
                'button_link' => '/products',
            ],
        ],
        'basic_gallery' => [
            'label' => 'ギャラリー',
            'patterns' => [
                'grid' => ['label' => 'グリッド', 'wireframe' => 'gallery-grid'],
                'masonry' => ['label' => 'メイソンリー', 'wireframe' => 'gallery-masonry'],
                'scroll' => ['label' => '横に流れる', 'wireframe' => 'gallery-scroll'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
            ],
            'blocks' => [
                'photo' => ['label' => '写真', 'fields' => [
                    'image' => ['type' => 'image', 'label' => '写真'],
                    'caption' => ['type' => 'text', 'label' => '写真の説明', 'max' => 100],
                ]],
            ],
            'example' => ['heading' => '見出しを入力してください'],
            // 見本に写真は書けないので、写真を選ぶまでの枠が 6 つ入る（ショップには写真を選んだものだけ出る）
            'example_blocks' => [
                ['type' => 'photo', 'values' => []], ['type' => 'photo', 'values' => []], ['type' => 'photo', 'values' => []],
                ['type' => 'photo', 'values' => []], ['type' => 'photo', 'values' => []], ['type' => 'photo', 'values' => []],
            ],
        ],
        self::PRODUCT => [
            'label' => '1 つの商品を大きく',
            'patterns' => [
                'split' => ['label' => '写真が左', 'wireframe' => 'product-split'],
                'full' => ['label' => '写真が全面', 'wireframe' => 'product-full'],
            ],
            'fields' => [
                // 商品は選ぶだけ（新着から自動では選ばない）。公開中のものだけが出る
                'product' => ['type' => 'products', 'label' => '商品', 'max' => 1, 'from' => ['pick'], 'help' => '公開中の商品から 1 つ選びます。その商品を非公開にすると、このセクションは表示されません'],
                'kicker' => ['type' => 'text', 'label' => '小見出し', 'max' => 30, 'help' => '「今月のおすすめ」など'],
                'body' => ['type' => 'textarea', 'label' => 'おすすめの一言', 'max' => 300, 'help' => '空欄なら商品の短い説明を表示します'],
            ],
            // 種類を選ぶ部品と「カートに入れる」は核の部品で、ページ全体の印（価格・写真）を読むので、1 つのページに 1 つまで
            'limit' => 1,
            'example' => ['kicker' => '今月のおすすめ'],
        ],
        'basic_entries' => [
            'label' => 'リンクカード',
            'patterns' => [
                'two' => ['label' => '2 つ', 'wireframe' => 'entries-two'],
                'three' => ['label' => '3 つ', 'wireframe' => 'entries-three'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
            ],
            'blocks' => [
                'entry' => ['label' => 'カード', 'fields' => [
                    'image' => ['type' => 'image', 'label' => '写真'],
                    'title' => ['type' => 'text', 'label' => '名前', 'max' => 40],
                    'link' => ['type' => 'link', 'label' => 'リンク先', 'help' => 'ショップ内のページ（/products など）か、https:// で始まる URL'],
                    'button_label' => ['type' => 'text', 'label' => 'ボタンの文字', 'max' => 20, 'help' => '空欄なら「見る」'],
                ]],
            ],
            'max_blocks' => 6,
            'example_blocks' => [
                ['type' => 'entry', 'values' => ['title' => 'ギフト', 'link' => '/products', 'button_label' => '見る']],
                ['type' => 'entry', 'values' => ['title' => '自分用', 'link' => '/products', 'button_label' => '見る']],
                ['type' => 'entry', 'values' => ['title' => '新作', 'link' => '/products', 'button_label' => '見る']],
            ],
        ],
        'basic_story' => [
            'label' => 'ブランドストーリー',
            'patterns' => [
                'longform' => ['label' => '写真と長文', 'wireframe' => 'story-longform'],
                'timeline' => ['label' => '年表', 'wireframe' => 'story-timeline'],
            ],
            'fields' => [
                'image' => ['type' => 'image', 'label' => '写真', 'help' => '「写真と長文」のパターンで表示されます'],
                'kicker' => ['type' => 'text', 'label' => '小見出し', 'max' => 30],
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 80],
                'body' => ['type' => 'textarea', 'label' => '文', 'max' => 2000],
            ],
            'blocks' => [
                'milestone' => ['label' => '年表の 1 行', 'fields' => [
                    'when' => ['type' => 'text', 'label' => 'いつ', 'max' => 20, 'help' => '「2015 年」「創業」など'],
                    'title' => ['type' => 'text', 'label' => '出来事', 'max' => 60],
                    'text' => ['type' => 'textarea', 'label' => '説明', 'max' => 300],
                ]],
            ],
            'max_blocks' => 12,
            'example' => [
                'kicker' => '私たちについて',
                'heading' => '見出しを入力してください',
                'body' => "ここに作り手の思いやお店の歩みを書きます。\nどんな人が、どんな場所で、どんな気持ちで作っているかを書くと、はじめてのお客さんにも伝わります。",
            ],
            // 年表の行は「年表」のパターンで出る
            'example_blocks' => [
                ['type' => 'milestone', 'values' => ['when' => '〇〇〇〇 年', 'title' => '出来事を入力してください', 'text' => 'ここに説明を書きます。']],
                ['type' => 'milestone', 'values' => ['when' => '〇〇〇〇 年', 'title' => '出来事を入力してください', 'text' => 'ここに説明を書きます。']],
                ['type' => 'milestone', 'values' => ['when' => '今', 'title' => '出来事を入力してください', 'text' => 'ここに説明を書きます。']],
            ],
        ],

        // ここから下は「核の共通の区画」の残り（見本帳の「次に」）。見本は「ここに〜を書きます」の形で、作り話の実績・数を書かない
        // （数字の見本は「00」。写真は見本に書けないので、写真を選ぶまでの枠か文字で出る）
        'basic_numbers' => [
            'label' => '実績の数字',
            'patterns' => [
                'row' => ['label' => '3 つ並び', 'wireframe' => 'numbers-row'],
                'photo' => ['label' => '写真と数字', 'wireframe' => 'numbers-photo'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
                'body' => ['type' => 'textarea', 'label' => '説明', 'max' => 300, 'help' => '見出しの下に表示する短い文。空欄でもかまいません'],
                'image' => ['type' => 'image', 'label' => '写真', 'help' => '「写真と数字」のパターンで表示されます'],
            ],
            'blocks' => [
                'stat' => ['label' => '数字', 'fields' => [
                    'value' => ['type' => 'text', 'label' => '数字', 'max' => 12, 'help' => '「30」「10 万」など。事実に基づく数だけを書いてください'],
                    'unit' => ['type' => 'text', 'label' => '単位', 'max' => 10, 'help' => '「年」「個」「%」など。空欄でもかまいません'],
                    'label' => ['type' => 'text', 'label' => '数字の説明', 'max' => 40],
                ]],
            ],
            'max_blocks' => 6,
            'example' => ['heading' => '数字で見るお店', 'body' => 'ここに、数字で伝えたいことの導入の文を書きます。'],
            'example_blocks' => [
                ['type' => 'stat', 'values' => ['value' => '00', 'unit' => '年', 'label' => 'ここに数字の意味を書きます（創業からの年数など）']],
                ['type' => 'stat', 'values' => ['value' => '00', 'unit' => '個', 'label' => 'ここに数字の意味を書きます（作った数など）']],
                ['type' => 'stat', 'values' => ['value' => '00', 'unit' => '%', 'label' => 'ここに数字の意味を書きます']],
            ],
        ],
        'basic_logos' => [
            'label' => 'ロゴ一覧',
            'patterns' => [
                'row' => ['label' => '1 行', 'wireframe' => 'logos-row'],
                'grid' => ['label' => 'グリッド', 'wireframe' => 'logos-grid'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60, 'help' => '「取扱店」「掲載されたメディア」など'],
            ],
            // ロゴはショップの写真から（JPEG・PNG・WebP。透過の PNG は透過のまま。SVG は置き場が受け付けない）。写真が無ければ名前を文字で出す
            'blocks' => [
                'logo' => ['label' => 'ロゴ', 'fields' => [
                    'image' => ['type' => 'image', 'label' => 'ロゴの写真', 'help' => '背景が透明な PNG が向いています。写真が無ければ名前を文字で表示します'],
                    'name' => ['type' => 'text', 'label' => '名前', 'max' => 40, 'help' => '写真の代替テキスト（画面の読み上げにも使います）'],
                    'link' => ['type' => 'link', 'label' => 'リンク先', 'help' => '空欄ならリンクにしません。ショップ内のページ（/products など）か、https:// で始まる URL'],
                ]],
            ],
            'max_blocks' => 12,
            'example' => ['heading' => 'ここに見出しを書きます（取扱店・掲載など）'],
            'example_blocks' => [
                ['type' => 'logo', 'values' => ['name' => 'ロゴ 1']], ['type' => 'logo', 'values' => ['name' => 'ロゴ 2']],
                ['type' => 'logo', 'values' => ['name' => 'ロゴ 3']], ['type' => 'logo', 'values' => ['name' => 'ロゴ 4']],
                ['type' => 'logo', 'values' => ['name' => 'ロゴ 5']],
            ],
        ],
        'basic_steps' => [
            'label' => '手順・流れ',
            'patterns' => [
                'row' => ['label' => '横に番号', 'wireframe' => 'steps-row'],
                'line' => ['label' => '縦の線', 'wireframe' => 'steps-line'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
                'body' => ['type' => 'textarea', 'label' => '説明', 'max' => 300, 'help' => '見出しの下に表示する短い文。空欄でもかまいません'],
            ],
            // 番号は並びの順に核が付ける（店の人は書かない）
            'blocks' => [
                'step' => ['label' => '手順', 'fields' => [
                    'title' => ['type' => 'text', 'label' => '手順の名前', 'max' => 40],
                    'text' => ['type' => 'textarea', 'label' => '説明', 'max' => 300],
                ]],
            ],
            'max_blocks' => 8,
            'example' => ['heading' => 'ここに見出しを書きます（ご注文の流れなど）'],
            'example_blocks' => [
                ['type' => 'step', 'values' => ['title' => '1 つ目の手順', 'text' => 'ここに手順の説明を書きます。']],
                ['type' => 'step', 'values' => ['title' => '2 つ目の手順', 'text' => 'ここに手順の説明を書きます。']],
                ['type' => 'step', 'values' => ['title' => '3 つ目の手順', 'text' => 'ここに手順の説明を書きます。']],
                ['type' => 'step', 'values' => ['title' => '4 つ目の手順', 'text' => 'ここに手順の説明を書きます。']],
            ],
        ],
        // 表は核の欄の種類の中で作る（表の欄の種類は足さない）: 列の見出しは区画の欄（col1〜col5）、行はブロック（行の見出し label と c1〜c5）。
        // 見出しも中身も空の列は出さない。狭い画面では表の箱の中だけが横に動く
        self::TABLE => [
            'label' => '比較表',
            'patterns' => [
                'table' => ['label' => '表', 'wireframe' => 'table-plain'],
                'photo' => ['label' => '写真とサイズ表', 'wireframe' => 'table-photo'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
                'image' => ['type' => 'image', 'label' => '写真', 'help' => '「写真とサイズ表」のパターンで表の横に表示されます'],
                'corner' => ['type' => 'text', 'label' => '左上のセル', 'max' => 30, 'help' => '行の見出しが並ぶ列の見出し（「サイズ」など）。空欄でもかまいません'],
                'col1' => ['type' => 'text', 'label' => '1 列目の見出し', 'max' => 30],
                'col2' => ['type' => 'text', 'label' => '2 列目の見出し', 'max' => 30],
                'col3' => ['type' => 'text', 'label' => '3 列目の見出し', 'max' => 30],
                'col4' => ['type' => 'text', 'label' => '4 列目の見出し', 'max' => 30, 'help' => '見出しも内容も空の列は表示されません'],
                'col5' => ['type' => 'text', 'label' => '5 列目の見出し', 'max' => 30, 'help' => '見出しも内容も空の列は表示されません'],
                'note' => ['type' => 'textarea', 'label' => '表の下の注記', 'max' => 300, 'help' => '「単位は cm です」など。空欄でもかまいません'],
            ],
            'blocks' => [
                'row' => ['label' => '行', 'fields' => [
                    'label' => ['type' => 'text', 'label' => '行の見出し', 'max' => 40],
                    'c1' => ['type' => 'text', 'label' => '1 列目', 'max' => 60],
                    'c2' => ['type' => 'text', 'label' => '2 列目', 'max' => 60],
                    'c3' => ['type' => 'text', 'label' => '3 列目', 'max' => 60],
                    'c4' => ['type' => 'text', 'label' => '4 列目', 'max' => 60],
                    'c5' => ['type' => 'text', 'label' => '5 列目', 'max' => 60],
                ]],
            ],
            'example' => [
                'heading' => 'ここに表の見出しを書きます（サイズ表など）',
                'corner' => 'サイズ', 'col1' => 'S', 'col2' => 'M', 'col3' => 'L',
                'note' => 'ここに単位や測り方を書きます（単位は cm など）。',
            ],
            'example_blocks' => [
                ['type' => 'row', 'values' => ['label' => '項目 1', 'c1' => '00', 'c2' => '00', 'c3' => '00']],
                ['type' => 'row', 'values' => ['label' => '項目 2', 'c1' => '00', 'c2' => '00', 'c3' => '00']],
                ['type' => 'row', 'values' => ['label' => '項目 3', 'c1' => '00', 'c2' => '00', 'c3' => '00']],
            ],
        ],
        // 値段の表（公式の店で設置の代行などのサービスの料金を「特長」で代用した）。プランは 1〜4 つのブロック。
        // 値段は見せ方の文字（「¥30,000〜」「月 ¥980」）で、注文・支払い・台帳とは結びつかない（金額を整数で持つ決まりの外。ADR 0039 の作って決めたこと）。
        // 含むものは 1 行に 1 つの複数行の文で、印つきの箇条で出す（ブロックの中にブロックは置けないため）。おすすめの印は 1 つのプランだけ（pricingRules）
        self::PRICING => [
            'label' => '値段の表',
            'patterns' => [
                'cards' => ['label' => 'カードを横に', 'wireframe' => 'pricing-cards'],
                'featured' => ['label' => 'おすすめを大きく', 'wireframe' => 'pricing-featured'],
                'table' => ['label' => '表で比べる', 'wireframe' => 'pricing-table'],
            ],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
                'body' => ['type' => 'textarea', 'label' => '説明', 'max' => 300, 'help' => '見出しの下に表示する短い文。空欄でもかまいません'],
                'note' => ['type' => 'textarea', 'label' => '表の下の注記', 'max' => 300, 'help' => '「値段はすべて税込です」など。空欄でもかまいません'],
            ],
            'blocks' => [
                'plan' => ['label' => 'プラン', 'fields' => [
                    'name' => ['type' => 'text', 'label' => 'プランの名前', 'max' => 40],
                    'price' => ['type' => 'text', 'label' => '値段', 'max' => 30, 'help' => '「¥30,000〜」「月 ¥980」など、表示したい形のまま。注文や支払いには使いません'],
                    'price_note' => ['type' => 'text', 'label' => '値段の添え書き', 'max' => 40, 'help' => '「税込」「1 回あたり」など。空欄でもかまいません'],
                    'description' => ['type' => 'textarea', 'label' => '説明', 'max' => 200],
                    'includes' => ['type' => 'textarea', 'label' => '含むもの', 'max' => 600, 'help' => '1 行に 1 つ書くと、記号付きの箇条書きで表示されます（'.self::PRICING_LINES.' 行まで）'],
                    'button_label' => ['type' => 'text', 'label' => 'ボタンの文字', 'max' => 30, 'help' => '空欄ならボタンを表示しません'],
                    'button_link' => ['type' => 'link', 'label' => 'ボタンのリンク先', 'help' => 'ショップ内のページ（/products など）か、https:// で始まる URL'],
                    // 札の文字は選択肢の名前（店の人が書く文字にしない）。付けたプランは目立つ枠と札で出す
                    'featured' => ['type' => 'choice', 'label' => 'おすすめのラベル', 'options' => ['recommended' => 'おすすめ', 'popular' => '人気', 'best' => 'いちばん人気'],
                        'help' => '目立つ枠とバッジで表示します。1 つのプランだけに付けられます'],
                ]],
            ],
            'max_blocks' => 4,
            'example' => ['heading' => 'ここに見出しを書きます（料金など）', 'note' => 'ここに注記を書きます（税込かどうかなど）。'],
            'example_blocks' => [
                ['type' => 'plan', 'values' => ['name' => 'プランの名前', 'price' => '¥00,000', 'price_note' => '税込', 'description' => 'ここにプランの説明を書きます。',
                    'includes' => "ここに含むものを書きます\n1 行に 1 つ", 'button_label' => 'くわしく見る', 'button_link' => '/products']],
                ['type' => 'plan', 'values' => ['name' => 'プランの名前', 'price' => '¥00,000', 'price_note' => '税込', 'description' => 'ここにプランの説明を書きます。',
                    'includes' => "ここに含むものを書きます\n1 行に 1 つ\nおすすめのラベルは 1 つだけ", 'button_label' => 'くわしく見る', 'button_link' => '/products', 'featured' => 'recommended']],
                ['type' => 'plan', 'values' => ['name' => 'プランの名前', 'price' => '¥00,000', 'price_note' => '税込', 'description' => 'ここにプランの説明を書きます。',
                    'includes' => "ここに含むものを書きます\n1 行に 1 つ", 'button_label' => 'くわしく見る', 'button_link' => '/products']],
            ],
        ],
        'basic_spacer' => [
            'label' => '余白・区切り',
            'patterns' => [
                'space' => ['label' => '余白', 'wireframe' => 'spacer-space'],
                'line' => ['label' => '線', 'wireframe' => 'spacer-line'],
            ],
            'fields' => [
                'size' => ['type' => 'choice', 'label' => '大きさ', 'options' => ['s' => '小さく', 'm' => 'ふつう', 'l' => '大きく']],
            ],
            'example' => ['size' => 'm'],
        ],

        // ここから下は「カスタム区画」（店が HTML を書ける区画）。
        // どちらも中身は HTML で、保存のときと描くときに核が決まったタグと属性だけに整える（SectionHtml）。スクリプトは動かせない
        // （動かしたいときは開発者モードか App。CONCEPT 7.2）。見本は整えた後の形のまま（整えても変わらないことをテストで見る）
        'basic_html' => [
            'label' => '自由な HTML',
            'patterns' => [
                'narrow' => ['label' => '本文と同じ幅', 'wireframe' => 'html-narrow'],
                'wide' => ['label' => '横いっぱい', 'wireframe' => 'html-wide'],
            ],
            'fields' => [
                'html' => ['type' => 'html', 'label' => 'HTML', 'help' => '使えるタグと属性だけがショップに表示されます。スクリプト・style・フォーム・外部サイトの画像は、保存するときに取り除きます'],
            ],
            'example' => [
                'html' => "<h2>見出しを入力してください</h2>\n<p>ここに HTML を書けます。<strong>太字</strong>・リスト・表・ショップの写真と、用意された class（2 列・ボタンの形など）が使えます。</p>\n"
                    .'<div class="sugar-cols-2"><div><h3>左の列</h3><p>ここに文を入力します。</p></div><div><h3>右の列</h3><p>ここに文を入力します。</p></div></div>'."\n"
                    .'<p class="sugar-align-center"><a class="sugar-btn" href="/products" rel="noopener noreferrer">商品を見る</a></p>',
            ],
        ],
        'basic_richtext' => [
            'label' => 'リッチテキスト',
            'patterns' => [
                'narrow' => ['label' => '本文と同じ幅', 'wireframe' => 'richtext-narrow'],
                'wide' => ['label' => '横いっぱい', 'wireframe' => 'richtext-wide'],
            ],
            'fields' => [
                'body' => ['type' => 'richtext', 'label' => '文', 'help' => '上のボタンで、見出し・太字・リスト・リンク・表・写真・ボタンを追加できます'],
            ],
            'example' => [
                'body' => '<h2>見出しを入力してください</h2><p>ここに文を書きます。上のボタンで<strong>太字</strong>や見出し・リスト・リンク・表・写真を追加できます。</p>'
                    .'<ul><li>伝えたいことの 1 つ目</li><li>伝えたいことの 2 つ目</li></ul>',
            ],
        ],
    ];

    /** 1 つの商品を大きく（値の product を、店頭で ProductView に組み立てる。SectionResolver） */
    public const string PRODUCT = 'basic_product';

    /** 比べる表（列の数は核が決める: 1〜5 列目の見出しか中身のある列だけ。SectionTable） */
    public const string TABLE = 'basic_table';

    /** 比べる表の列の数（col1〜col5・c1〜c5） */
    public const int TABLE_COLUMNS = 5;

    /** 値段の表（プランはブロック。おすすめの印は 1 つまで。blockRules） */
    public const string PRICING = 'basic_pricing';

    /** 値段の表の「含むもの」の行の数（空の行は数えない） */
    public const int PRICING_LINES = 10;

    /** 新しく作ったページの最初の並び */
    private const array DEFAULT = [['type' => 'basic_text', 'pattern' => 'left']];

    /** @var array<string, SectionSpec>|null */
    private static ?array $specs = null;

    /**
     * @return array<string, SectionSpec>
     */
    public static function specs(): array
    {
        return self::$specs ??= SectionSchema::fromArray(self::DECLARATION, 'core')->sections;
    }

    public static function section(string $key): ?SectionSpec
    {
        return str_starts_with($key, self::PREFIX) ? (self::specs()[$key] ?? null) : null;
    }

    /**
     * 欄 1 つでは確かめられない核の区画の決まり（ブロックをまたぐもの）。保存のときに SectionValues が、宣言どおりに確かめた後のブロックを渡す。
     * 返すのは区画の中の道筋（blocks.1.values.featured）=> 断る理由。決まりの無い種類は空
     *
     * - 値段の表: おすすめの印は 1 つのプランだけ（2 つ目から断る）・含むものは空でない行が PRICING_LINES 行まで
     *
     * @param  list<array{type: string, values: array<string, mixed>}>  $blocks
     * @return array<string, string>
     */
    public static function blockRules(string $type, array $blocks): array
    {
        if ($type !== self::PRICING) {
            return [];
        }

        $errors = [];
        $featured = false;

        foreach ($blocks as $i => $block) {
            if (($block['values']['featured'] ?? null) !== null) {
                if ($featured) {
                    $errors["blocks.{$i}.values.featured"] = 'おすすめのラベルは 1 つのプランだけに付けられます。';
                }
                $featured = true;
            }

            $includes = $block['values']['includes'] ?? null;
            if (is_string($includes) && count(self::lines($includes)) > self::PRICING_LINES) {
                $errors["blocks.{$i}.values.includes"] = '「含むもの」は '.self::PRICING_LINES.' 行までです。';
            }
        }

        return $errors;
    }

    /**
     * 複数行の文を、前後の空白を除いた空でない行の並びに（値段の表の「含むもの」の箇条）
     *
     * @return list<string>
     */
    public static function lines(?string $text): array
    {
        if ($text === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== ''));
    }

    /**
     * 自由なページの雛形（テーマが pages.page を書かないとき）
     */
    public static function freePage(): PageSpec
    {
        return new PageSpec(PageSpec::FREE, array_keys(self::specs()), self::DEFAULT, free: true);
    }
}
