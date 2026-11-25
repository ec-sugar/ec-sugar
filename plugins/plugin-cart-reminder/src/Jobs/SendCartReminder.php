<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder\Jobs;

use EcSugar\PluginCartReminder\ReminderSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * カゴ落ちのお知らせを 1 通送る（キュー。店の文脈は stancl/tenancy がつなぐ）。仕事に載せるのは記録の ID だけで、
 * 宛先は仕事の中で注文の買う人の暗号化された値から読む（キューにも失敗の記録にも平文のメールを残さない。ADR 0010）。
 * 試し直さない（送れたのに断りに見えた時に 2 通にしない。キューから消えた仕事は毎時の処理が積み直す）
 */
final class SendCartReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $reminderId) {}

    public function handle(ReminderSender $sender): void
    {
        $sender->send($this->reminderId);
    }
}
