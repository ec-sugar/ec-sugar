<?php

declare(strict_types=1);

// 決済（ADR 0019）
return [
    // 使う決済代行のドライバの名前（ADR 0026・ADR 0107 の段 3a）。入っているドライバが 1 つだけなら決めなくてよい。
    // 名乗るのはドライバ自身のサービスプロバイダ（App\Core\Payment\PaymentDrivers::register）
    'driver' => env('PAYMENT_DRIVER'),

    // 本番の鍵（sk_live_）を置けるか。既定は本番の環境だけ（開発・ステージング・テストで本物のお金を動かさない）
    'live_keys_allowed' => (bool) env('PAYMENT_LIVE_KEYS_ALLOWED', env('APP_ENV') === 'production'),

    // 支払いの画面の有効な時間（分）。Stripe は 30 分〜24 時間。過ぎたら注文の在庫を戻す（区切り 4）
    // 下限は 35 分（Stripe の下限 30 分と、作りかけの予約を使い回す 31 分の余裕。短いと同時に押して画面が 2 つできる）
    'checkout_minutes' => max(35, (int) env('PAYMENT_CHECKOUT_MINUTES', 60)),
    // 注文から何分まで新しい支払いの画面を作れるか（押し続けて在庫を押さえ続けないように。過ぎたら期限切れの片付けが取り消す）
    'order_payable_minutes' => (int) env('PAYMENT_ORDER_PAYABLE_MINUTES', 180),

    // 支払いの画面の期限の後、注文を取り消すまでの猶予（分）。期限の間際の支払いの通知が遅れて届くのを待つ
    'expiry_grace_minutes' => (int) env('PAYMENT_EXPIRY_GRACE_MINUTES', 10),

    // コンビニ払いの入金待ち（ADR 0058）を、払いの期限の後どれだけ待ってから見回りが片付けるか（時間）。
    // ふだんは Stripe の「払われなかった」の知らせで取り消す。これは知らせが届かなかった時の受け皿（期限の間際にお店で払い始めた分も待つ）
    'awaiting_grace_hours' => (int) env('PAYMENT_AWAITING_GRACE_HOURS', 24),

    // 定期の確かめで 1 回に見る数（店ごと）。確かめた時刻が古い順に見るので、決まりきらないものが溜まっても後ろが止まらない
    'expiry_batch' => 500,
    'refund_reconcile_batch' => 200,

    // 「Stripe とつなぐ」（Connect、ADR 0022）。運営のプラットフォームの秘密鍵と、連結アカウントの通知の署名の鍵。サーバーの秘密にだけ置く。
    // 置いていなければ（パッケージ版）Connect は使えず、店が鍵を貼る形だけになる
    'stripe_platform_secret' => env('STRIPE_PLATFORM_SECRET_KEY'),
    'stripe_connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),

    // Stripe の送り先。ブラウザのテストの偽物（tests/e2e/fake-stripe.php）のためだけ。本番では使えない
    'stripe_api_base' => env('STRIPE_API_BASE'),
];
