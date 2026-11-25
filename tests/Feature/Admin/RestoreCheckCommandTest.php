<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 戻した後の確かめ（operations:restore-check。ADR 0082）。店ごとの数を出し、写真のファイルが欠けた店があれば失敗で終わる。読むだけ
 */
final class RestoreCheckCommandTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_counts_each_shop_and_fails_when_a_photo_file_is_missing(): void
    {
        $this->tenant('rca');
        $this->tenant('rcb');
        $image = $this->inside('rca', function (): ListingImage {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '白いシャツ', 'unit_amount' => 4900, 'status' => ListingStatus::Active]);
            $path = (string) tempnam(sys_get_temp_dir(), 'rcc');
            file_put_contents($path, $this->jpeg(400, 300));
            $this->files[] = $path;

            return app(ListingImageService::class)->add($listing, $path);
        });

        $this->assertSame(0, Artisan::call('operations:restore-check', ['--verify' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('店 2', $out);
        $this->assertMatchesRegularExpression('/\|\s*rca\s*\|\s*0\s*\|\s*0\s*\|\s*0\s*\|\s*0\s*\|\s*\d+（無傷）\s*\|\s*1\s*\|\s*0\s*\|/u', $out);
        $this->assertStringContainsString('写真のファイルがそろっています', $out);
        // 商品名（店の中身）は出さない
        $this->assertStringNotContainsString('白いシャツ', $out);

        // 写真のファイルが 1 つ欠けると、その店を数えて失敗で終わる
        Storage::disk('media')->delete(ListingImage::path('rca', $image->listing_id, $image->id, 'medium'));
        $this->assertSame(1, Artisan::call('operations:restore-check'));
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/\|\s*rca\s*\|.*\|\s*1\s*\|\s*1\s*\|/u', $out);
        $this->assertStringContainsString('確認が要る店が 1', $out);
    }
}
