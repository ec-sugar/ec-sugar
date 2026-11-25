<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Crypto\TenantCipher;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 前のメールアドレスへ「変わりました」を送る（キュー。店の文脈は stancl/tenancy がつなぐ）。
 * 仕事に載せる前のアドレスは店の鍵で暗号化したもの（キューにも失敗の記録にも平文を残さない。ADR 0010）。
 * 会員が App のログインの手段（LINE など）をつないでいれば、そのことも載せる（メールを変えた人が LINE もつないでいたら、本当の持ち主が気づけるように。ADR 0070）
 */
final class SendEmailChangedNotice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $sealedEmail,
        public readonly ?string $accountId = null,
    ) {}

    public static function for(string $previousEmail, ?string $accountId = null): self
    {
        return new self(app(TenantCipher::class)->encrypt($previousEmail), $accountId);
    }

    public function handle(TenantCipher $cipher): void
    {
        $tenant = tenant();
        $email = $cipher->decrypt($this->sealedEmail);

        if (! $tenant instanceof Tenant || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $linked = $this->accountId === null ? [] : app(LoginEntries::class)->linkedLabels($this->accountId);
        Mail::to($email)->send(new EmailChangedMail((string) $tenant->getAttribute('name'), $linked));
    }
}
