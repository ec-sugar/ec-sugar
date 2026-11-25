<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use App\Core\Plugin\Contracts\PluginDataPurger;
use App\Core\Plugin\Services\ListingFileUploads;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use EcSugar\PluginDownloads\Models\DownloadListing;

/**
 * 店で「ダウンロード販売」のデータを消す（ADR 0092。核の口 PluginDataPurger の、ファイルを持つ App の見本）。
 * 核が店の文脈の中・連鎖のロックの中で、App を止めた店でだけ呼ぶ。
 *
 * - 払ったお客さんがまだ受け取れるリンク（止めていない・期限の前・回数が残る）と、払われたのにまだ渡していない注文があるうちは断る
 *   （App を止めても、払ったお客さんは受け取れる約束。見直し）
 * - 行はリンク → 商品ごとの期限と回数 → ファイルの順に消す（外部キーの向き）。商品そのもの（核の listings）は消さない
 * - ファイルはコミットの後に、この店の置き場（tenants/<店>/files）のうち、どの行も指さないものを消す（落ちて残っても、もう一度消すと拾う）
 */
final class DownloadDataPurger implements PluginDataPurger
{
    public function refusal(): ?string
    {
        $now = CarbonImmutable::now();
        $open = DownloadGrant::query()->whereNull('stopped_at')->where('expires_at', '>', $now)->whereColumn('count', '<', 'max_count')->count();

        if ($open > 0) {
            return "お客さんがまだダウンロードできるリンクが {$open} 件あります。期限が過ぎるのを待つか、注文ごとにダウンロードを止めてから削除してください。";
        }

        if (DownloadGrants::waiting()->exists()) {
            return 'まだダウンロードのリンクを送っていない注文があります。リンクを送り終えるか、注文を取り消してから削除してください。';
        }

        return null;
    }

    public function purgeRows(): array
    {
        return [
            'downloads_grants' => DownloadGrant::query()->delete(),
            'downloads_listings' => DownloadListing::query()->delete(),
            'downloads_files' => DownloadFile::query()->delete(),
        ];
    }

    public function purgeFiles(): int
    {
        $disk = ListingFileUploads::disk();
        $kept = array_flip(DownloadFile::query()->pluck('path')->map(static fn (mixed $path): string => (string) $path)->all());
        $gone = array_values(array_filter($disk->allFiles(DownloadFiles::folder()), static fn (string $path): bool => ! isset($kept[$path])));

        if ($gone !== []) {
            $disk->delete($gone);
        }

        return count($gone);
    }
}
