<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads\Console;

use App\Core\Plugin\PluginTenants;
use App\Core\Plugin\Services\ListingFileUploads;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\DownloadFiles;
use EcSugar\PluginDownloads\Models\DownloadFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 毎日: 店の人が消したファイルのうち、渡したリンクがみな使えなくなった（期限切れ・止めた）ものを置き場から消し、24 時間を過ぎた上げる途中のファイルも捨てる。
 * App を止めた店でも、消し残しがあれば回る
 */
final class PurgeDownloadsCommand extends Command
{
    protected $signature = 'downloads:purge';

    protected $description = '消した売るファイルを、リンクの期限の後に保存先から消す（App「ダウンロード販売」）';

    public function handle(PluginTenants $tenants): int
    {
        $total = 0;
        $tenants->each('downloads', function () use (&$total): void {
            try {
                $total += app(DownloadFiles::class)->purge(CarbonImmutable::now());
                // 24 時間を過ぎた上げる途中のファイル（画面を閉じた・落ちた）も捨てる
                app(ListingFileUploads::class)->sweepShop();
            } catch (Throwable $error) {
                report($error);
            }
        }, static fn (): bool => (Schema::hasTable('downloads_files') && DownloadFile::query()->whereNotNull('removed_at')->whereNull('purged_at')->exists())
            || ListingFileUploads::disk()->files('uploads/'.tenant()?->getTenantKey()) !== []);

        $this->info("保存先から消したファイル: {$total}");

        return self::SUCCESS;
    }
}
