<?php

use App\Core\Payment\Stripe\StripeServiceProvider;
use App\Providers\AdminServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\StorefrontServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    AdminServiceProvider::class,
    StorefrontServiceProvider::class,
    TenancyServiceProvider::class,
    // 決済代行のドライバ（ADR 0107 の段 3）。核の外へ出すときは、この行を App の composer.json の providers へ移す
    StripeServiceProvider::class,
];
