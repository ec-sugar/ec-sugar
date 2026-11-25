<?php

declare(strict_types=1);

use App\Http\TrustedProxies;

return [
    // 信じる前段の代理。TRUSTED_PROXIES に書いたものだけ、既定は無し（App\Http\TrustedProxies を参照）。
    // 必ず配列にする: null だと Laravel は Host が .on-forge.com などで終わる要求で「誰でも信じる」に切り替える（Host は送る側が書ける）
    'proxies' => TrustedProxies::parse((string) env('TRUSTED_PROXIES', '')),
];
