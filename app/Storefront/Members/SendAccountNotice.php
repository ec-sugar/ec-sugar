<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Party\Models\CustomerAccount;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 会員への事後の知らせを送る（キュー。店の文脈は stancl/tenancy がつなぐ。AccountNotice が載せる）。
 * 宛先は送る時に台帳のメールから読む（会員の時だけ。退会した・止めた台帳には送らない）
 */
final class SendAccountNotice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $accountId,
        public readonly string $what,
        public readonly int $at,
    ) {}

    public function handle(): void
    {
        $tenant = tenant();
        $account = CustomerAccount::query()->whereKey($this->accountId)->whereNotNull('member_since')->whereNull('disabled_at')->first();

        if (! $tenant instanceof Tenant || $account === null || filter_var($account->email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $when = CarbonImmutable::createFromTimestamp($this->at)->timezone('Asia/Tokyo')->format('Y/m/d H:i');
        Mail::to($account->email)->send(new AccountNoticeMail((string) $tenant->getAttribute('name'), $this->what, $when));
    }
}
