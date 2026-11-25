<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 店頭（ADR 0018）
|--------------------------------------------------------------------------
| 店頭は Blade でサーバーが描く。見た目はテーマ（composer のパッケージ）が持ち、テーマは DB に触らない。
*/

return [
    // 既定のテーマ（テーマの名前）。店ごとのテーマはテナントの data.theme（php artisan storefront:theme で変える）
    'theme' => env('STOREFRONT_THEME', 'default'),

    // 税率は商品の区分から核が決める（App\Core\Tax\TaxClass。ADR 0063）。価格は税込み。ここには置かない

    // トップに並べる商品の数
    'featured_limit' => 8,

    // 「残りわずか」と出す在庫の数（これ以下で、0 より多いとき）
    'low_stock' => 2,

    // ブラウザのテストだけ: 区画を並べられる見本のテーマ（tests/Support/Themes/sample。ADR 0039）を登録する。公式テーマはまだ決まった並びなので、
    // ページの編集の画面の足す・並べ替えを本物のブラウザで確かめるため。本番では読まない（APP_ENV が production なら常に偽）
    'sample_theme' => env('APP_ENV') !== 'production' && (bool) env('STOREFRONT_SAMPLE_THEME', false),

    // 店頭の CSP。インラインの script・style、外部の読み込みを許さない（テーマも同じ。外部の画像の置き場所を使うときはここに足す）。
    // 支払い（ADR 0019）は Stripe の画面へ移る形なので script は要らない。注文の確定のフォームの後に Stripe の画面へ移るので、
    // form-action に Stripe の支払いの画面だけ足す（Chrome はフォームの後のリダイレクト先も form-action で見る）。
    // STOREFRONT_TEST_FORM_ACTION はブラウザのテストの偽の Stripe のためだけで、本番では足さない
    'csp' => implode('; ', [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self'",
        "font-src 'self'",
        "connect-src 'self'",
        "form-action 'self' https://checkout.stripe.com".(env('APP_ENV') !== 'production' && is_string(env('STOREFRONT_TEST_FORM_ACTION')) ? ' '.env('STOREFRONT_TEST_FORM_ACTION') : ''),
        "base-uri 'none'",
        "frame-ancestors 'none'",
        "require-trusted-types-for 'script'",
        // 作れる関所は既定の関所（default）だけ、1 度だけ（'allow-duplicates' なし）。作るのは核（resources/storefront/trusted-types.js）で、
        // App を差し込む画面で App のスクリプトより前に作る。App と外のタグは別の名前の関所も、default の作り直しも作れない（ADR 0034）
        'trusted-types default',
    ]),
];
