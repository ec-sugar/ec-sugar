<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 防御ヘッダ（App\Http\Middleware\SecurityHeaders）
|--------------------------------------------------------------------------
| 既定は「自分のオリジンだけ」。店頭テーマや管理画面が外部の CDN・画像・決済代行の
| スクリプトを使うときは、プラグイン側のフィルタで CSP を広げる（核の既定は緩めない）。
*/

return [
    'csp' => implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "object-src 'none'",
        "img-src 'self' data: https:",
        "font-src 'self' data:",
        "style-src 'self' 'unsafe-inline'", // Vite / Tailwind のインライン style。nonce 化は管理画面 SPA と同時に
        "script-src 'self'",
        "connect-src 'self'",
    ]),

    // 管理画面（AdminSecurityHeaders）。SPA はビルド済みの自分のファイルだけを読み、通信は自分のオリジンの API だけ。
    // インラインの script / style・data: URI・外部の CDN を一切許さない（開発でも同じ。Vite の開発サーバーは使わない。ADR 0015）。
    // Trusted Types: DOM に HTML を書き込めるのは Vue の 'vue' ポリシーだけ（画面のコードで v-html / innerHTML を使わないことは
    // tests/Feature/Admin/AdminShellTest が検査する）
    'admin_csp' => implode('; ', [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self'",
        "font-src 'self'",
        "connect-src 'self'",
        "manifest-src 'self'",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-ancestors 'none'",
        "require-trusted-types-for 'script'",
        'trusted-types vue',
    ]),

    'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',

    // 管理画面だけ、自分のオリジンのカメラを許す（App「デジタルチケット」の受付で QR を読む）。
    // 使うのは店の人が「カメラで読む」を押した時だけで、ブラウザが許すかを聞く。枠（iframe）の中のほかのオリジンには許さない
    'admin_permissions_policy' => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()',

    // HTTPS で受けたときだけ HSTS を付ける
    'hsts' => (bool) env('SECURITY_HSTS', true),
];
