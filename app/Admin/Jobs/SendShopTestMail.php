<?php

declare(strict_types=1);

namespace App\Admin\Jobs;

use App\Admin\Mail\ShopTestMail;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * 試しのメールを、ログイン中の運営のアドレスへ送る（ADR 0095）。店の文脈の中で積み、お客さんへのメールと同じキューを通る
 * （店の文脈と処理の ID は stancl/tenancy と Laravel が戻す）。
 *
 * - 積んだ中身（宛先のメール）はアプリの鍵で暗号化する（ShouldBeEncrypted。OperatorSecurityMail と同じ）
 * - 1 回だけ試す。送れなかったことはメールの記録に理由つきで残るので、仕事は失敗にしない（壊れたことの知らせを、店の人の試しで鳴らさない）
 */
final class SendShopTestMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $email,
        public readonly string $when,
    ) {}

    public function handle(): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            Mail::to($this->email)->send(new ShopTestMail((string) $tenant->getAttribute('name'), $this->when));
        } catch (TransportExceptionInterface $e) {
            // 理由はメールの記録に残っている（例外の文には宛先が入ることがあるので、ログには種類だけ）
            Log::warning('テストメールを送れませんでした', ['exception' => $e::class]);
        }
    }
}
