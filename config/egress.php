<?php

declare(strict_types=1);

/*
 * 外への通信の出口の代理（ADR 0102。本番の compose の egress、docker/egress/proxy.php）
 *
 * - proxy: アプリが外への https に使う代理（compose が EC_SUGAR_EGRESS_PROXY で渡す。HTTPS_PROXY と同じ値だが、開発機の HTTPS_PROXY を
 *   本番の代理と取り違えないよう専用の印にする。開発の形とテストでは空 = 代理を使わない）。
 *   Laravel の Http（Guzzle）と Stripe の部品（libcurl）は HTTPS_PROXY を自分で読むので、ここを読むのは自分で代理を決める所と運営の様子だけ
 *   （写真の取り込み App\Core\Media\RemoteImages。代理が無い時は名前を自分で引いて内向きの住所を断る）
 * - state: 代理が書く数のファイル（この 24 時間の数。メモリの上の置き場を読み取り専用で差し込む）。運営の様子が読む（App\Admin\Services\EgressWatch）
 */
return [
    'proxy' => env('EC_SUGAR_EGRESS_PROXY'),
    'state' => env('EC_SUGAR_EGRESS_STATE', '/run/ec-sugar-egress/state.json'),
];
