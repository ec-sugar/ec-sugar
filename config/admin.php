<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 運営の管理画面（ADR 0013）
|--------------------------------------------------------------------------
| 管理画面は店頭・中央とは別のホスト名で受ける（CONCEPT 7.2）。ログインはパスキーだけ。
| このホストはテナントとして解決しない（config/tenancy.php の central_domains に自動で入る）。
*/

return [
    // 管理画面のホスト名と、ブラウザから見えるオリジン（パスキーはこのオリジンでしか使えない）
    'domain' => env('ADMIN_DOMAIN', 'admin.localhost'),
    'url' => rtrim((string) env('ADMIN_URL', 'http://admin.localhost:8080'), '/'),

    // 接続元の制限（カンマ区切りの IP / CIDR）。空なら制限しない。本番では必ず設定する
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_ALLOWED_IPS', ''))))),

    // セッション。店頭とは別の Cookie 名で、他サイトからの遷移には送らない（SameSite=Strict）
    'session_cookie' => env('ADMIN_SESSION_COOKIE', 'sugar_admin_session'),
    'session_idle_minutes' => (int) env('ADMIN_SESSION_IDLE_MINUTES', 30),
    'session_max_hours' => (int) env('ADMIN_SESSION_MAX_HOURS', 12),

    // 重要な操作（パスキーの追加・削除など）の前に求める再確認の有効時間
    'confirm_seconds' => (int) env('ADMIN_CONFIRM_SECONDS', 300),

    // CLI が発行する登録トークンの有効時間
    'enrollment_minutes' => (int) env('ADMIN_ENROLLMENT_MINUTES', 30),
    // まだ誰もパスキーを持っていない最初の登録だけは長く（入れた直後に DNS の反映を待てるように。ADR 0031）
    'first_enrollment_minutes' => (int) env('ADMIN_FIRST_ENROLLMENT_MINUTES', 1440),

    // おかしな動きを数えるか（ADR 0103）。いつも数える。環境変数では切れない（盗んだ人に .env 1 行で黙らせない）。
    // 切るのはテストだけ（監査ログは追記だけでテストの間で消えないので、ほかのテストの記録を数えて知らせのメールの中身が揺れる。tests/TestCase.php）
    'detect_anomalies' => true,
];
