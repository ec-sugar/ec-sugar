<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock\Jobs;

use EcSugar\PluginRestock\RestockNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * 種類が入荷したかを見て、待っている登録に知らせる（キュー。店の文脈は stancl/tenancy がつなぐ）。載せるのは種類の ID だけ。
 * 在庫を変えた要求を待たせないため、在庫の操作の知らせからはここへ回す。何度動いても、1 つの登録に送るのは 1 回だけ（RestockNotifier::claim）
 */
final class NotifyRestock implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(public readonly string $variantId) {}

    public function handle(RestockNotifier $notifier): void
    {
        $notifier->notify($this->variantId);
    }
}
