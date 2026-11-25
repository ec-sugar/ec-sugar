<?php

declare(strict_types=1);

namespace App\Storefront\Console;

use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\StripeConnectService;
use App\Models\StripeConnectedAccount;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * つないだ店の登録の状態を Stripe に問い合わせ直す（ADR 0022）。routes/console.php で、
 * 登録が済んでいない店は 10 分ごと（--pending）、済んだ店も含めて 1 時間ごと（止められたことに気づく）。
 * Stripe に届かない店は飛ばして次の回へ（ほかの店は続ける）
 */
final class RefreshStripeConnectCommand extends Command
{
    protected $signature = 'storefront:refresh-stripe-connect {--pending : 登録が済んでいない店だけ}';

    protected $description = '接続した店の Stripe の登録の状態を問い合わせ直す';

    public function handle(StripeConnectService $connect): int
    {
        if (! $connect->available()) {
            $this->info('運営の Stripe の鍵が無いので、何もしません');

            return self::SUCCESS;
        }

        $counts = ['checked' => 0, 'failed' => 0];
        $tenants = Tenant::query()->whereIn('id', StripeConnectedAccount::query()->select('tenant_id'))->cursor();

        tenancy()->runForMultiple($tenants, function () use ($connect, &$counts): void {
            if ($this->option('pending') && PaymentGateway::query()->where('provider', 'stripe_connect')->where('connect_status', 'active')->exists()) {
                return;
            }

            try {
                $connect->refresh('system:connect');
                $counts['checked']++;
            } catch (PaymentUnavailableException) {
                $counts['failed']++;
            }
        });

        $this->info("問い合わせ {$counts['checked']} 店、届かず次の回へ {$counts['failed']} 店");

        return self::SUCCESS;
    }
}
