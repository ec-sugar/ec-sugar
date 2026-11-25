<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Services\RecoveryKit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 管理画面で作った復元のキットの、開ける鍵の公開の半分を出す（ADR 0111）。
 *
 * ホストの ec-sugar が、サーバーの外のバックアップを暗号化する相手がまだ無い時に聞く。出すのは公開してよい値だけ:
 * 1 行目に指紋、2 行目から公開鍵の ASCII の包み。まだ無ければ何も出さない（終わりの値は 0）。
 * ホストは受け取った鍵を gpg で確かめ直してから採る（1 つだけ・暗号化に使える・指紋が 1 行目と同じ。ここの答えを信じない）
 *
 *   php artisan operations:recovery-recipient [--host-fingerprint=<ホストがいま使っている公開鍵の指紋>]
 *
 * --host-fingerprint は、ホストがいま暗号化に使っている公開鍵の指紋（16 進の大文字）。画面が、キットの鍵と同じかを比べるために控える。
 * 形が違えば控えずに、終わりの値 2（公開鍵は出さない）
 */
final class RecoveryRecipientCommand extends Command
{
    protected $signature = 'operations:recovery-recipient {--host-fingerprint= : ホストがいまバックアップの暗号化に使っている公開鍵の指紋}';

    protected $description = '復元のキットの復号の鍵（公開鍵）を出す（ホストの ec-sugar が聞く）';

    public function handle(): int
    {
        $host = $this->option('host-fingerprint');

        if ($host !== null) {
            if (! RecoveryKit::reportHostFingerprint($host, CarbonImmutable::now())) {
                $this->error('--host-fingerprint の形が違います（16 進の大文字で 40〜64 字）');

                return self::INVALID;
            }

            return self::SUCCESS;
        }

        $recipient = RecoveryKit::recipient();

        if ($recipient !== null) {
            $this->output->write($recipient['fingerprint']."\n".$recipient['public_key']);
        }

        return self::SUCCESS;
    }
}
