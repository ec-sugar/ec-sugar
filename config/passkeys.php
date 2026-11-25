<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| パスキー（laravel/passkeys、ADR 0013）
|--------------------------------------------------------------------------
| 管理画面の運営アカウント専用。ルートはパッケージに作らせず routes/admin.php で登録する
| （App\Providers\AdminServiceProvider が Passkeys::ignoreRoutes() を呼ぶ）。
*/

return [
    // パスキーは管理画面のホスト名に結びつく。ホスト名を変えると登録済みのパスキーは使えなくなる
    'relying_party_id' => env('ADMIN_DOMAIN', 'admin.localhost'),

    'allowed_origins' => [
        rtrim((string) env('ADMIN_URL', 'http://admin.localhost:8080'), '/'),
    ],

    // ユーザーハンドル（認証器に渡す不透明な ID）の鍵。APP_KEY を回すときは先にこれを固定しておく
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', env('APP_KEY')),

    'timeout' => 60000,

    'guard' => 'operator',

    // 以下はパッケージのルートを使わないので参照されない（routes/admin.php が自前で指定する）
    'middleware' => [],
    'management_middleware' => [],
    'throttle' => null,
    'redirect' => '/',
];
