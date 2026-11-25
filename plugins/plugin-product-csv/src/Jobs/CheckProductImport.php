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
 * 商品の CSV の確かめ。2,000 行で 40 秒ほどかかるので、要求の中でなくキューで（画面は記録を読み直して待つ）。
 * キューに載せるのは記録の ID だけ（店の文脈は stancl/tenancy がつなぐ）。送り直さない（もう一度はファイルを選び直す）
 */
final class CheckProductImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75; // 走らせ役の --timeout と同じ。送り直し（retry_after 90 秒）より短く。長いファイルは仕事を区切って続ける

    public function __construct(public readonly string $importId) {}

    public function handle(ProductImports $imports): void
    {
        $imports->runCheck($this->importId);
    }

    public function failed(?Throwable $e): void
    {
        app(ProductImports::class)->failed($this->importId, 'check');
    }
}
