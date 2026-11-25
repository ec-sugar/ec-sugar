<?php

declare(strict_types=1);

namespace EcSugar\PluginShopNotify\Jobs;

use EcSugar\PluginShopNotify\ShopNotices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * 店への知らせを送る（キュー。店の文脈は stancl/tenancy がつなぐ）。載せるのは種類と相手の ID だけで、宛先も中身も載せない（送る時に店の中で読む）。
 * やり直すと届くかもしれない失敗（Slack の 5xx・つながらない・メールの送り手の誤り）は投げて、時間をおいてやり直す。送れた宛先へは 2 回送らない
 */
final class SendShopNotice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  list<'slack'|'mail'>|null  $only
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $subjectId,
        public readonly string $dedupeKey,
        public readonly ?array $only = null,
    ) {}

    public function handle(ShopNotices $notices): void
    {
        if ($notices->deliver($this->kind, $this->subjectId, $this->dedupeKey, $this->only)) {
            throw new RuntimeException('店への通知を送れなかった宛先がある（時間をおいてやり直す）');
        }
    }
}
