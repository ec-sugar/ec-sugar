<?php

declare(strict_types=1);

namespace App\Core\Payment\Stripe;

use App\Core\Payment\PaymentDrivers;
use Illuminate\Support\ServiceProvider;

/**
 * Stripe のドライバを決済代行の登録簿に載せる（ADR 0026・ADR 0107 の段 3）。
 * 核はこのファイルだけから Stripe の名前を知り、口（PaymentDriver・PaymentWebhookReceiver）は登録簿から引く。
 * 別の決済代行を App として足すときも、その App のサービスプロバイダが同じ形で register を呼ぶ
 */
final class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentDrivers::class);

        $this->callAfterResolving(PaymentDrivers::class, static function (PaymentDrivers $drivers): void {
            $drivers->register('stripe', StripeDriver::class, StripeWebhook::class);
        });
    }
}
