<?php

declare(strict_types=1);

return [
    // 店の数の形（ADR 0031）。single（1 システム・1 店舗。入口の Caddy は管理画面とショップのホストを起動のときに取り、その場では取らない）か
    // multi（複数の店。店のホストは初めて来たときにその場で取り、取る前に問い合わせの経路に聞く）。
    // 本番とステージングは env ファイルの EC_SUGAR_SHOPS（install.sh が答えから書く。名前を移す前のサーバーは旧名 SUGAR_EC_SHOPS）。分からなければ single とみなす（問い合わせの経路は何も許さない）
    'shops' => (env('EC_SUGAR_SHOPS') ?? env('SUGAR_EC_SHOPS')) === 'multi' ? 'multi' : 'single',

    // ショップのホストの親（例 example.jp → 店は <店>.example.jp）。入口の Caddy が証明書をその場で取ってよい名前の範囲（multi のときだけ使う）。
    // 本番とステージングは env ファイルの EC_SUGAR_SHOP_DOMAIN（旧名 SUGAR_EC_SHOP_DOMAIN も読む。入口の nginx・Caddy と同じ値。install.sh が答えから書く）。
    // 空なら、取ってよいのは管理画面のホストだけ（開発では使わない）
    'shop_domain' => env('EC_SUGAR_SHOP_DOMAIN') ?: env('SUGAR_EC_SHOP_DOMAIN') ?: null,
];
