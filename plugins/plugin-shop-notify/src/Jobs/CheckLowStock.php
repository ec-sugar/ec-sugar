<?php

declare(strict_types=1);

namespace EcSugar\PluginShopNotify\Jobs;

use EcSugar\PluginShopNotify\LowStock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * 在庫が少なくなったかを見る（キュー）。注文や在庫の操作の要求を待たせないため、記録の知らせからはここへ回す。載せるのは種類の ID だけ
 */
final class CheckLowStock implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(public readonly array $variantIds) {}

    public function handle(LowStock $stock): void
    {
        $stock->check($this->variantIds);
    }
}
