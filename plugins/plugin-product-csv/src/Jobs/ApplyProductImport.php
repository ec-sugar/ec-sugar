<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv\Jobs;

use EcSugar\PluginProductCsv\ProductImports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * 商品の CSV の反映。商品 1 つずつ短いトランザクションで書く（ProductImports::runApply）。
 * 送り直さない（途中まで入ったものを自動でやり直さず、止まった行と理由を記録に残して、店の人が「もう一度取り込む」で残りを入れる）
 */
final class ApplyProductImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75; // 走らせ役の --timeout と同じ。送り直し（retry_after 90 秒）より短く。1 回の仕事は約 45 秒で区切って続ける

    public function __construct(public readonly string $importId) {}

    public function handle(ProductImports $imports): void
    {
        $imports->runApply($this->importId);
    }

    public function failed(?Throwable $e): void
    {
        app(ProductImports::class)->failed($this->importId, 'apply');
    }
}
