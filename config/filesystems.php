<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        // 商品の写真など、作り直した画像の置き場所。外から直接は見えない。配るのはアプリの経路（/media/…）だけ。
        // 本番で複数のアプリを並べるときは S3 互換（RustFS など）に切り替える（MEDIA_DISK）
        // 置き場は環境ごとに分ける: 開発と本番は既定の media、PHP のテストは media-testing（phpunit.xml）、
        // ブラウザのテストは media-e2e（tests/e2e/env.js）。店の ID が重なっても、別の環境の写真を消さない
        'media' => [
            'driver' => 'local',
            'root' => storage_path('app/'.env('MEDIA_DIRECTORY', 'media')),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        // 売るファイル（App「ダウンロード販売」など。ADR 0084）の置き場。写真と別の場所で、外から直接は見えない（配るのは署名と短い期限の経路だけ）。
        // 場所は写真の置き場の名前に -downloads を付けたもの（環境ごとに分かれる。テストは media-testing-downloads など）。
        // 上げる途中のファイルを足していく（チャンク）ので、今はローカルのディスクだけ（S3 互換へ移すのは後で。docs/install.md）
        'downloads' => [
            'driver' => 'local',
            'root' => storage_path('app/'.env('MEDIA_DIRECTORY', 'media').'-downloads'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // ローカルディスクを Web から配信・受信するルート（storage/{path}）を作らない。
            // ファイルは S3 互換から配る（CONCEPT 7.2）。使わない入口は開けない（で発見）
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    // 写真の置き場の既定の場所（開発と本番）。テストの環境ではここの店の写真を消させない（App\Core\Media\MediaRoot）
    'media_default_root' => storage_path('app/media'),

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
