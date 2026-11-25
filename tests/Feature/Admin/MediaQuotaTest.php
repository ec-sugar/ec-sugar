<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\InvalidImageException;
use App\Core\Media\Models\ShopMedia;
use App\Core\Media\Services\MediaQuota;
use App\Core\Media\Services\ShopMediaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 写真の置き場の上限（config/media.php）。私たちがホストして貸すので、1 つの店・1 つの盗まれたログインが
 * ディスクと CPU を使い切らない。店の合計・1 日の量・1 分の回数。超えたら作らず、ファイルもイベントも残さない
 */
final class MediaQuotaTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    /** @var list<string> */
    private array $files = [];

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = FakeMedia::fake();
        Cache::store()->flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Asia/Tokyo'));
        $this->tenant('mqa');
        $this->tenant('mqb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_photo_records_its_stored_size_and_a_full_shop_refuses_more(): void
    {
        config(['media.shop_quota_mb' => 1]);

        $this->inside('mqa', function (): void {
            $media = app(ShopMediaService::class)->add($this->photo());
            $listing = Listing::create(['seller_id' => Seller::query()->firstOrFail()->id, 'kind' => 'product', 'title' => 'カップ', 'unit_amount' => 1000, 'status' => 'active']);
            $image = app(ListingImageService::class)->add($listing, $this->photo());

            // 記録する大きさは、置いた大と中の WebP の合計
            $this->assertSame($this->sizeOf('tenants/mqa/shop/media/'.$media->id), $media->bytes);
            $this->assertSame($this->sizeOf("tenants/mqa/listings/{$listing->id}/{$image->id}"), $image->bytes);
            $this->assertGreaterThan(0, $image->bytes);
            $this->assertSame($media->bytes + $image->bytes, MediaQuota::used());

            // 置き場をほぼ使い切った形にする（上限 1 MB）
            DB::table('shop_media')->where('id', $media->id)->update(['bytes' => 1024 * 1024 - $image->bytes - 10]);
            $files = $this->disk->allFiles();
            $events = DomainEvent::query()->count();

            foreach ([fn () => app(ShopMediaService::class)->add($this->photo()), fn () => app(ListingImageService::class)->add($listing, $this->photo())] as $add) {
                try {
                    $add();
                    $this->fail('上限を超えて写真を置いた');
                } catch (InvalidImageException $e) {
                    $this->assertStringContainsString('写真の保存容量がいっぱいです（上限 1 MB）', $e->getMessage());
                }
            }

            // 行もファイルもイベントも増えない
            $this->assertSame(1, ShopMedia::query()->count());
            $this->assertSame(1, ListingImage::query()->count());
            $this->assertSame($files, $this->disk->allFiles());
            $this->assertSame($events, DomainEvent::query()->count());

            // 使っていない写真を消せば、また上げられる
            app(ShopMediaService::class)->remove($media->id);
            app(ShopMediaService::class)->add($this->photo());
        });

        // ほかの店の量は数えない
        $this->inside('mqb', fn () => app(ShopMediaService::class)->add($this->photo()));
    }

    public function test_the_daily_amount_does_not_come_back_by_deleting_and_resets_the_next_day(): void
    {
        config(['media.shop_quota_mb' => 100, 'media.daily_upload_mb' => 1]);

        $this->inside('mqa', function (): void {
            $quota = app(MediaQuota::class);
            $service = app(ShopMediaService::class);
            $first = $service->add($this->photo());
            // 今日の残りを、次の写真が入らないところまで使う
            $quota->reserve(1024 * 1024 - $first->bytes - 10);
            // 消しても今日の量は戻らない
            $service->remove($first->id);

            try {
                $service->add($this->photo());
                $this->fail('1 日の量を超えて写真を置いた');
            } catch (InvalidImageException $e) {
                $this->assertStringContainsString('今日アップロードできる写真の量（1 MB）を超えます', $e->getMessage());
            }

            $this->assertSame(0, ShopMedia::query()->count());

            // 使い切ったら、作り直す前に断る（作り直しの CPU を使わせない）。壊れたファイルを渡し、作り直しまで進めば別の断りになることで見分ける
            $quota->reserve(10);
            $broken = (string) tempnam(sys_get_temp_dir(), 'quota');
            file_put_contents($broken, 'これは写真ではない');
            $this->files[] = $broken;

            try {
                $service->add($broken);
                $this->fail('1 日の量を使い切っても受け付けた');
            } catch (InvalidImageException $e) {
                $this->assertStringContainsString('今日アップロードできる写真の量', $e->getMessage());
            }
        });

        // 日本の日付が替われば、また上げられる。ほかの店は初めから数えない
        $this->inside('mqb', fn () => app(ShopMediaService::class)->add($this->photo()));
        $this->travelTo(CarbonImmutable::parse('2026-11-05 00:01', 'Asia/Tokyo'));
        $this->inside('mqa', fn () => app(ShopMediaService::class)->add($this->photo()));
    }

    public function test_photos_per_minute_are_limited_per_shop_before_rebuilding(): void
    {
        config(['media.uploads_per_minute' => 2]);

        $this->inside('mqa', function (): void {
            $service = app(ShopMediaService::class);
            $service->add($this->photo());
            $service->add($this->photo());

            try {
                $service->add($this->photo());
                $this->fail('1 分の回数を超えて受け付けた');
            } catch (InvalidImageException $e) {
                $this->assertStringContainsString('1 分ほど待ってから', $e->getMessage());
            }

            $this->assertSame(2, ShopMedia::query()->count());
        });

        $this->inside('mqb', fn () => app(ShopMediaService::class)->add($this->photo()));
        $this->travel(61)->seconds();
        $this->inside('mqa', fn () => app(ShopMediaService::class)->add($this->photo()));
    }

    private function photo(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'quota');
        file_put_contents($path, $this->jpeg(400, 200));
        $this->files[] = $path;

        return $path;
    }

    /**
     * 名前の頭が同じファイル（大と中）の合計
     */
    private function sizeOf(string $prefix): int
    {
        return array_sum(array_map(fn (string $file): int => $this->disk->size($file), array_values(array_filter($this->disk->allFiles(), static fn (string $f): bool => str_starts_with($f, $prefix)))));
    }
}
