<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingFilesHandler;
use App\Core\Plugin\Contracts\ListingPanelScope;
use App\Core\Plugin\ListingFile;
use App\Core\Plugin\ListingFileUpload;
use App\Core\Plugin\ListingPanelNotFound;
use App\Core\Plugin\Services\ListingFileUploads;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * 商品の画面の App の欄の売るファイル（ADR 0084。核の口 ListingFilesHandler）。受け取り・形の確かめは核、ここは置く場所と表。
 *
 * - 置き場は downloads（写真と別。外から直接は見えない）の tenants/<店>/files/<ID>.<拡張子>（名前は作り直す。店の人の名前は表示とお客さんが受け取る時の名前だけ）
 * - 1 つの商品に 5 つまで、1 つ 500 MB まで（核の上限 config('downloads.max_file_mb') が小さければそちら）。店の合計は核の上限（config('downloads.shop_quota_mb')）
 * - 消しても、もう渡したリンクの期限までは置いておく（新しい注文には渡さない）。リンクの無いファイルはすぐ置き場から消す。残りは毎日の downloads:purge
 * - 書き込みは連鎖のロックの中で、記録（download.file_added・download.file_removed。ID・大きさ・拡張子だけ）と同じトランザクションで
 */
final class DownloadFiles implements ListingFilesHandler, ListingPanelScope
{
    public const int MAX_FILES = 5;

    public const int MAX_MB = 500;

    /** 受ける形（拡張子 => 中身の型）。実行できる形・HTML・SVG は核が断る */
    public const array ACCEPTS = [
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'epub' => ['application/epub+zip'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'mp4' => ['video/mp4'],
        'm4v' => ['video/x-m4v', 'video/mp4'],
        'mov' => ['video/quicktime'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    ];

    public const string ACTOR_SYSTEM = 'system:downloads';

    public function appliesTo(Listing $listing): bool
    {
        return $listing->kind === DownloadListingPanel::KIND;
    }

    public function maxFiles(): int
    {
        return self::MAX_FILES;
    }

    public function maxBytes(): int
    {
        return self::MAX_MB * 1024 * 1024;
    }

    public function usedBytes(): int
    {
        return (int) DownloadFile::query()->whereNull('purged_at')->sum('bytes');
    }

    public function accepts(): array
    {
        return self::ACCEPTS;
    }

    public function files(Listing $listing): array
    {
        return DownloadFile::query()->where('listing_id', $listing->id)->whereNull('removed_at')->orderBy('created_at')->orderBy('id')->get()
            ->map(static fn (DownloadFile $f): ListingFile => new ListingFile($f->id, $f->name, $f->bytes, $f->extension, $f->created_at))->all();
    }

    public function store(Listing $listing, ListingFileUpload $upload, string $actor): void
    {
        $disk = ListingFileUploads::disk();
        $moved = null;

        try {
            $this->place($listing, $upload, $actor, $disk, $moved);
        } catch (Throwable $error) {
            // 移した後にトランザクションが落ちた（記録や行が残らなかった）: 移した先のファイルも消す（表に無いファイルを置き場に残さない）
            if ($moved !== null && $disk->exists($moved) && ! DownloadFile::query()->where('path', $moved)->exists()) {
                $disk->delete($moved);
            }

            throw $error;
        }
    }

    /**
     * 錠の中で数と合計を確かめ、行を作ってファイルを移し、記録を残す。移した先を $moved に入れる
     */
    private function place(Listing $listing, ListingFileUpload $upload, string $actor, Filesystem $disk, ?string &$moved): void
    {
        DB::transaction(function () use ($listing, $upload, $actor, $disk, &$moved): void {
            $events = app(EventStore::class);
            $events->lock();
            // 同時に上げても、数と店の合計を越えない（錠の中で数え直す）
            Listing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();

            if (DownloadFile::query()->where('listing_id', $listing->id)->whereNull('removed_at')->count() >= self::MAX_FILES) {
                throw ValidationException::withMessages(['file' => 'この商品に登録できるファイルは '.self::MAX_FILES.' つまでです。']);
            }

            if ($this->usedBytes() + $upload->bytes > ListingFileUploads::quotaBytes()) {
                throw ValidationException::withMessages(['file' => 'ファイルの保存先の容量がいっぱいです。使わないファイルを削除してからアップロードしてください。']);
            }

            $id = strtolower((string) Str::ulid());
            $path = self::folder().'/'.$id.'.'.$upload->extension;
            DownloadFile::create([
                'id' => $id, 'listing_id' => $listing->id, 'name' => $upload->name, 'extension' => $upload->extension, 'mime' => $upload->mime,
                'bytes' => $upload->bytes, 'sha256' => $upload->sha256, 'path' => $path,
            ]);

            if (! $disk->move($upload->path, $path)) {
                throw new LogicException('売るファイルを保存先へ移せませんでした');
            }

            $moved = $path;

            $events->append('download.file_added', 'listing', $listing->id, ['file' => $id, 'bytes' => $upload->bytes, 'extension' => $upload->extension], $actor);
        });
    }

    public function delete(Listing $listing, string $fileId, string $actor): void
    {
        $purge = DB::transaction(function () use ($listing, $fileId, $actor): ?string {
            $events = app(EventStore::class);
            $events->lock();
            $file = DownloadFile::query()->whereKey($fileId)->where('listing_id', $listing->id)->whereNull('removed_at')->lockForUpdate()->first();

            if ($file === null) {
                throw new ListingPanelNotFound;
            }

            $file->removed_at = CarbonImmutable::now();
            $linked = DownloadGrant::query()->where('file_id', $file->id)->exists();

            // リンクを渡していないファイルは、置き場からもすぐ消す（渡していれば、リンクの期限まで置いておく）
            if (! $linked) {
                $file->purged_at = CarbonImmutable::now();
            }

            $file->save();
            $events->append('download.file_removed', 'listing', $listing->id, ['file' => $file->id, 'kept' => $linked], $actor);

            return $linked ? null : $file->path;
        });

        if ($purge !== null) {
            ListingFileUploads::disk()->delete($purge);
        }
    }

    /**
     * 消したファイルのうち、渡したリンクがみな使えなくなった（期限切れ・止めた）ものを置き場から消す（毎日の downloads:purge）。消した数
     */
    public function purge(CarbonImmutable $now): int
    {
        $purged = 0;
        $candidates = DownloadFile::query()->whereNotNull('removed_at')->whereNull('purged_at')
            ->whereNotIn('id', DownloadGrant::query()->select('file_id')->whereNull('stopped_at')->where('expires_at', '>', $now))
            ->limit(500)->pluck('id');

        foreach ($candidates as $id) {
            $path = DB::transaction(function () use ($id, $now): ?string {
                $events = app(EventStore::class);
                $events->lock();
                $file = DownloadFile::query()->whereKey($id)->whereNull('purged_at')->lockForUpdate()->first();

                // 錠の中で確かめ直す（店の人が期限を延ばした・再開した後なら消さない）
                if ($file === null || DownloadGrant::query()->where('file_id', $file->id)->whereNull('stopped_at')->where('expires_at', '>', $now)->exists()) {
                    return null;
                }

                $file->purged_at = $now;
                $file->save();
                $events->append('download.file_purged', 'listing', $file->listing_id, ['file' => $file->id], self::ACTOR_SYSTEM);

                return $file->path;
            });

            if ($path !== null) {
                ListingFileUploads::disk()->delete($path);
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * 店の売るファイルの場所（店の ID が頭。店の境界）
     */
    public static function folder(): string
    {
        $shop = tenant()?->getTenantKey();

        if (! is_string($shop) || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $shop) !== 1) {
            throw new LogicException('売るファイルは店の文脈の中でだけ扱う');
        }

        return "tenants/{$shop}/files";
    }
}
