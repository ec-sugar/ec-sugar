<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Party\Services\CustomerLogin;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 会員のログインのコードを送る（キュー。店の文脈は stancl/tenancy がつなぐ）。仕事に載せるのはコードの行の ID だけで、
 * 宛先とコードは店の DB の行から読んで消す（キューにも失敗の記録 failed_jobs にも、平文のメールとコードを残さない。ADR 0010）。
 * 一度読んだら消えるので、送れなかった時はやり直さない（お客さんが「送り直す」を押す）
 */
final class SendLoginCode implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  ?string  $linking  App のログインの手段をつなぐためのコードなら、その手段の名前（「LINE」）
     */
    public function __construct(
        public readonly string $codeId,
        public readonly ?string $linking = null,
    ) {}

    public function handle(CustomerLogin $login): void
    {
        $tenant = tenant();
        $sending = $login->takeForSending($this->codeId);

        if ($sending === null || ! $tenant instanceof Tenant || filter_var($sending['email'], FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        Mail::to($sending['email'])->send(new LoginCodeMail((string) $tenant->getAttribute('name'), $sending['code'], CustomerLogin::CODE_MINUTES, $this->linking));
    }
}
