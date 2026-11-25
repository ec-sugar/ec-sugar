<?php

declare(strict_types=1);

namespace EcSugar\PluginLine\Jobs;

use EcSugar\PluginLine\LineNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * 注文の知らせを LINE でも送る仕事（キュー。店の文脈は stancl/tenancy がつなぐ。ADR 0070）。
 * 仕事に載せるのは知らせの種類と注文・受渡の ID だけ（宛先の ID もトークンも載せない）。送り直さない（1 回だけ）
 */
final class SendLineMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $kind,
        public readonly string $transactionId,
        public readonly ?string $fulfillmentId = null,
    ) {}

    public function handle(LineNotifier $notifier): void
    {
        $notifier->send($this->kind, $this->transactionId, $this->fulfillmentId);
    }
}
