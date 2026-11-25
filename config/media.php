<?php

declare(strict_types=1);

/*
| 写真の置き場の上限（攻撃テスト第 2 回の残し）。私たちがホストして貸すので、1 つの店・1 つの盗まれたログインが
| サーバーのディスクと CPU を使い切らないようにする（2026-11-04、運営の判断。ホストするなら制限は要る）。
| 数えるのは作り直して置いた写真（大と中の WebP）の大きさ。元の写真は置かない
*/
return [
    // 店ごとの合計（MB）。超える写真は上げられない（使っていない写真を消せば上げられる）
    'shop_quota_mb' => (int) env('MEDIA_SHOP_QUOTA_MB', 2048),
    // 店ごとに 1 日（日本の日付）に増やせる量（MB）。消してから上げ直しても戻らない（作り直しの CPU も数える）
    'daily_upload_mb' => (int) env('MEDIA_DAILY_UPLOAD_MB', 500),
    // 店ごとに 1 分に作り直せる写真の数（作り直しは重いので、作り直す前に断る）
    'uploads_per_minute' => (int) env('MEDIA_UPLOADS_PER_MINUTE', 30),
];
