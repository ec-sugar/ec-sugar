<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads\Console;

use App\Core\Plugin\PluginTenants;
use EcSugar\PluginDownloads\DownloadGrants;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\SendDownloadMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 見回り（10 分ごと）: 払われたのにダウンロードのリンクが無い注文に作り直し、ご案内のメールを送る（払われた知らせの処理が落ちた時の取りこぼし）。
 * App を止めた店でも、ダウンロードの商品を売ったことがあれば回る（払ったお客さんに渡す約束のため。作る注文が無ければ何もしない）
 */
final class GrantDownloadsCommand extends Command
{
    protected $signature = 'downloads:grant';

    protected $description = '払われたのにダウンロードのリンクが無い注文に作り直す（App「ダウンロード販売」）';

    public function handle(PluginTenants $tenants): int
    {
        $total = 0;
        $tenants->each('downloads', function () use (&$total): void {
            // 1 店が落ちても、後ろの店は続ける
            try {
                foreach (app(DownloadGrants::class)->catchUp() as $orderId) {
                    SendDownloadMail::dispatch($orderId);
                    $total++;
                }
            } catch (Throwable $error) {
                report($error);
            }
        }, static fn (): bool => Schema::hasTable('downloads_files') && DownloadFile::query()->exists());

        $this->info("リンクを作った注文: {$total}");

        return self::SUCCESS;
    }
}
