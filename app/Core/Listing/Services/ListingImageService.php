<?php

declare(strict_types=1);

namespace App\Core\Listing\Services;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Media\ImageProcessor;
use App\Core\Media\InvalidImageException;
use App\Core\Media\Services\MediaQuota;
use App\Core\Media\Services\ShopMediaService;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * 商品の写真の追加・削除・並べ替え・代替テキスト。写真の変更はここを通し、イベントを残す。
 *
 * - 受け取ったファイルはそのまま保存せず、必ず ImageProcessor で作り直す
 * - 書き込みは EventStore::lock() → 商品の行のロックの順（ADR 0012）。枚数の上限と並び順はこのロックの中で守る
 * - 途中で失敗したら、書いたファイルを消す。削除したファイルは、DB の変更が確定してから消す
 * - 元のファイルの中身のハッシュ（と、取りに行った URL のハッシュ）を写真に残す。CSV のまとめての取り込みが同じ写真を二重に作らないため
 */
final class ListingImageService
{
    public const int MAX_PER_LISTING = 10;

    public function __construct(
        private readonly EventStore $events,
        private readonly ImageProcessor $processor,
        private readonly MediaQuota $quota,
    ) {}

    /**
     * @param  string|null  $sourceUrl  取りに行った URL（CSV の取り込み。ハッシュだけを残す）
     */
    public function add(Listing $listing, string $path, ?string $alt = null, ?string $actor = null, ?string $sourceUrl = null): ListingImage
    {
        $tenant = $this->tenantId();
        $source = @hash_file('sha256', $path);
        // 作り直し（重い）の前に、1 分の回数とその日の量。作り直しはロックの外で先に済ませる
        $this->quota->admit();
        $variants = $this->processor->process($path);
        $disk = $this->disk();
        $written = [];

        try {
            return DB::transaction(function () use ($listing, $alt, $actor, $tenant, $variants, $disk, $source, $sourceUrl, &$written): ListingImage {
                $this->events->lock();
                Listing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();

                $count = ListingImage::query()->where('listing_id', $listing->id)->count();

                if ($count >= self::MAX_PER_LISTING) {
                    throw new InvalidImageException('写真は 1 つの商品に '.self::MAX_PER_LISTING.' 枚までです。');
                }

                $bytes = array_sum(array_map(static fn ($variant): int => strlen($variant->bytes), $variants));
                $this->quota->reserve($bytes);
                $large = $variants['large'];
                $image = ListingImage::create([
                    'listing_id' => $listing->id,
                    'position' => $count,
                    'alt' => self::cleanAlt($alt),
                    'width' => $large->width,
                    'height' => $large->height,
                    'checksum' => hash('sha256', $large->bytes),
                    'bytes' => $bytes,
                    'source_digest' => is_string($source) ? $source : null,
                    'source_url_digest' => $sourceUrl === null ? null : hash('sha256', $sourceUrl),
                ]);

                foreach ($variants as $name => $variant) {
                    $file = ListingImage::path($tenant, $listing->id, $image->id, $name);
                    $disk->put($file, $variant->bytes);
                    $written[] = $file;
                }

                $this->events->append('listing.image_added', 'listing', $listing->id, [
                    'image_id' => $image->id,
                    'position' => $image->position,
                    'width' => $image->width,
                    'height' => $image->height,
                ], $actor);

                return $image;
            });
        } catch (Throwable $e) {
            foreach ($written as $file) {
                $disk->delete($file);
            }

            throw $e;
        }
    }

    public function remove(ListingImage $image, ?string $actor = null): void
    {
        $tenant = $this->tenantId();

        DB::transaction(function () use ($image, $actor, $tenant): void {
            $this->events->lock();
            Listing::query()->whereKey($image->listing_id)->lockForUpdate()->firstOrFail();

            // ロックの中で消えた行を数える（同時に 2 回消しても、記録は 1 回だけ）
            if (ListingImage::query()->whereKey($image->id)->where('listing_id', $image->listing_id)->delete() === 0) {
                return;
            }

            $this->renumber($image->listing_id);

            $this->events->append('listing.image_removed', 'listing', $image->listing_id, ['image_id' => $image->id], $actor);

            // ファイルは DB の変更が確定してから消す（取り消されたら残す）
            DB::afterCommit(function () use ($image, $tenant): void {
                foreach (array_keys(ImageProcessor::VARIANTS) as $name) {
                    $this->disk()->delete(ListingImage::path($tenant, $image->listing_id, $image->id, $name));
                }
            });
        });
    }

    /**
     * @param  list<string>  $ids  この商品の写真の ID を、並べたい順にすべて
     */
    public function reorder(Listing $listing, array $ids, ?string $actor = null): void
    {
        DB::transaction(function () use ($listing, $ids, $actor): void {
            $this->events->lock();
            Listing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();

            $current = ListingImage::query()->where('listing_id', $listing->id)->pluck('id')->all();
            $given = $ids;
            sort($current);
            sort($given);

            if ($current !== $given || count(array_unique($ids)) !== count($ids)) {
                throw new InvalidArgumentException('並べ替えには、この商品の写真をすべて 1 回ずつ指定してください。');
            }

            foreach ($ids as $position => $id) {
                ListingImage::query()->whereKey($id)->update(['position' => $position]);
            }

            $this->events->append('listing.images_reordered', 'listing', $listing->id, ['order' => $ids], $actor);
        });
    }

    public function updateAlt(ListingImage $image, ?string $alt, ?string $actor = null): ListingImage
    {
        return DB::transaction(function () use ($image, $alt, $actor): ListingImage {
            $this->events->lock();
            $locked = ListingImage::query()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            $locked->alt = self::cleanAlt($alt);

            if ($locked->isDirty('alt')) {
                $locked->save();
                $this->events->append('listing.image_updated', 'listing', $locked->listing_id, ['image_id' => $locked->id, 'changed' => ['alt']], $actor);
            }

            return $locked;
        });
    }

    public function disk(): Filesystem
    {
        return Storage::disk('media');
    }

    private function renumber(string $listingId): void
    {
        $ids = ListingImage::query()->where('listing_id', $listingId)->orderBy('position')->orderBy('id')->pluck('id');

        foreach ($ids as $position => $id) {
            ListingImage::query()->whereKey($id)->update(['position' => $position]);
        }
    }

    private function tenantId(): string
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('商品の写真はテナント文脈の中でだけ扱う');
        }

        return (string) $tenant->getTenantKey();
    }

    /**
     * 代替テキストは 1 行の文だけ（改行・制御文字・文の向きを入れ替える印を断る。ショップの写真と同じ決まり。第 2 回）
     */
    private static function cleanAlt(?string $alt): ?string
    {
        $alt = $alt === null ? null : trim($alt);
        $alt = $alt === '' ? null : $alt;

        Validator::make(['alt' => $alt], [
            'alt' => ['nullable', 'string', 'max:200', 'not_regex:'.ShopMediaService::ALT_FORBIDDEN],
        ], ['alt.not_regex' => '代替テキストは改行や制御文字を含まない 1 行で書いてください。'], ['alt' => '代替テキスト'])->validate();

        return $alt;
    }
}
