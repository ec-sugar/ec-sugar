<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\ImageProcessor;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の商品の写真。受け取ったファイルは作り直してから、テナントごとの置き場に保存する。
 */
final class ListingImageAdminTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use OperatorSession;
    use WithTenants;

    /** @var list<string> */
    private array $files = [];

    private Operator $operator;

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->disk = FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_an_uploaded_photo_is_rebuilt_stored_per_tenant_and_shown_to_the_operator(): void
    {
        $this->tenant('pia');
        $this->signIn();
        $listingId = $this->listing('pia');

        // 位置情報の代わりの目印と、後ろに付けた PHP を持つ、向き 6 のスマホの写真
        $marker = 'SECRET-GPS-35.6895-139.6917';
        $bytes = $this->withExif($this->jpeg(400, 200), $marker, 6).'<?php system($_GET["c"]); ?>';
        $response = $this->upload('pia', $listingId, $bytes, ['alt' => '  白いシャツの正面  '])->assertCreated()
            ->assertJsonPath('image.position', 0)
            ->assertJsonPath('image.alt', '白いシャツの正面')
            ->assertJsonPath('image.width', 200)
            ->assertJsonPath('image.height', 400)
            ->assertJsonCount(1, 'listing.images');
        $imageId = (string) $response->json('image.id');

        // 置き場はテナントと商品で分かれ、名前はこちらで決める（送られたファイル名は使わない）
        $this->assertSame([
            "tenants/pia/listings/{$listingId}/{$imageId}-large.webp",
            "tenants/pia/listings/{$listingId}/{$imageId}-medium.webp",
        ], $this->allFiles());

        foreach ($this->allFiles() as $file) {
            $stored = (string) $this->disk->get($file);
            $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($stored));
            $this->assertStringNotContainsString($marker, $stored);
            $this->assertStringNotContainsString('<?php', $stored);
        }

        $this->inside('pia', function () use ($imageId, $listingId): void {
            $image = ListingImage::query()->findOrFail($imageId);
            $this->assertSame(hash('sha256', (string) $this->disk->get("tenants/pia/listings/{$listingId}/{$imageId}-large.webp")), $image->checksum);
            $event = DomainEvent::query()->where('type', 'listing.image_added')->sole();
            $this->assertEquals(['image_id' => $imageId, 'position' => 0, 'width' => 200, 'height' => 400], $event->payload);
            $this->assertSame('operator:'.$this->operator->id, $event->actor);
        });

        // 商品の詳細にも並ぶ
        $this->send('GET', "/api/tenants/pia/listings/{$listingId}")->assertOk()->assertJsonPath('listing.images.0.id', $imageId);

        // 管理画面で見る。ブラウザに長く残さない
        $shown = $this->send('GET', "/api/tenants/pia/listings/{$listingId}/images/{$imageId}/medium")->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $shown->headers->get('Cache-Control'));
        $this->assertSame([200, 400], array_slice((array) getimagesizefromstring((string) $shown->getContent()), 0, 2));
        $this->send('GET', "/api/tenants/pia/listings/{$listingId}/images/{$imageId}/original")->assertNotFound();
        $this->send('GET', "/api/tenants/pia/listings/{$listingId}/images/{$imageId}/..%2F..%2Fkey")->assertNotFound();
    }

    public function test_files_that_are_not_allowed_photos_are_refused_without_leaving_anything(): void
    {
        $this->tenant('pib');
        $this->signIn();
        $listingId = $this->listing('pib');

        $cases = [
            'SVG（スクリプトを含められる）' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
            'HTML' => '<!doctype html><script>alert(1)</script>',
            'PHP' => '<?php system($_GET["c"]);',
            'GIF' => $this->encode(imagecreatetruecolor(4, 4), 'gif'),
            '縦横を巨大に偽った PNG' => $this->pngHeader(6000, 6000),
        ];

        foreach ($cases as $name => $bytes) {
            $this->upload('pib', $listingId, $bytes)->assertStatus(422)->assertJsonValidationErrors('image');
            $this->assertSame([], $this->allFiles(), $name);
        }

        // 送られたファイルの種類（Content-Type）や拡張子を偽っても、中身で判定する
        $this->upload('pib', $listingId, '<svg/>', [], 'photo.jpg', 'image/jpeg')->assertStatus(422);
        // 10MB を超えるもの、ファイルでないもの、無いもの
        $this->upload('pib', $listingId, $this->jpeg(10, 10).str_repeat("\0", ImageProcessor::MAX_BYTES))->assertStatus(422)->assertJsonValidationErrors('image');
        $this->send('POST', "/api/tenants/pib/listings/{$listingId}/images", ['image' => 'data:image/png;base64,AAAA'])->assertStatus(422)->assertJsonValidationErrors('image');
        $this->send('POST', "/api/tenants/pib/listings/{$listingId}/images")->assertStatus(422)->assertJsonValidationErrors('image');
        // 代わりの文字の長さと、壊れた文字
        $this->upload('pib', $listingId, $this->jpeg(10, 10), ['alt' => str_repeat('あ', 201)])->assertStatus(422)->assertJsonValidationErrors('alt');
        $this->upload('pib', $listingId, $this->jpeg(10, 10), ['alt' => "a\0b"])->assertStatus(422);

        $this->assertSame([], $this->allFiles());
        $this->inside('pib', function (): void {
            $this->assertSame(0, ListingImage::query()->count());
            $this->assertSame(0, DomainEvent::query()->where('type', 'like', 'listing.image%')->count());
        });
    }

    public function test_a_listing_holds_at_most_ten_photos(): void
    {
        $this->tenant('pic');
        $this->signIn();
        $listingId = $this->listing('pic');

        for ($i = 0; $i < ListingImageService::MAX_PER_LISTING; $i++) {
            $this->upload('pic', $listingId, $this->jpeg(20, 20))->assertCreated()->assertJsonPath('image.position', $i);
        }

        $this->upload('pic', $listingId, $this->jpeg(20, 20))->assertStatus(422)->assertJsonValidationErrors('image');
        // 断った分のファイルは残らない
        $this->assertCount(ListingImageService::MAX_PER_LISTING * 2, $this->allFiles());
    }

    public function test_alt_text_order_and_removal(): void
    {
        $this->tenant('pid');
        $this->signIn();
        $listingId = $this->listing('pid');
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (string) $this->upload('pid', $listingId, $this->jpeg(30 + $i, 20))->assertCreated()->json('image.id');
        }
        $base = "/api/tenants/pid/listings/{$listingId}/images";

        // 代わりの文字。空にすると「無し」に戻る
        $this->send('PATCH', "{$base}/{$ids[1]}", ['alt' => '後ろ姿'])->assertOk()->assertJsonPath('image.alt', '後ろ姿');
        $this->send('PATCH', "{$base}/{$ids[1]}", ['alt' => '   '])->assertOk()->assertJsonPath('image.alt', null);
        $this->send('PATCH', "{$base}/{$ids[1]}", [])->assertStatus(422)->assertJsonValidationErrors('alt');
        // 改行・制御文字・文の向きを入れ替える印は入れない（ショップの写真と同じ。第 2 回）
        foreach (["1行目\n2行目", "逆\u{202E}gnp.exe", "C1\u{0085}の途中"] as $bad) {
            $this->send('PATCH', "{$base}/{$ids[1]}", ['alt' => $bad])->assertStatus(422)->assertJsonValidationErrors('alt');
        }

        // 並べ替えは、この商品の写真をすべて 1 回ずつ
        $reversed = array_reverse($ids);
        $this->send('POST', "{$base}/order", ['ids' => $reversed])->assertOk()->assertJsonPath('listing.images.*.id', $reversed);
        foreach ([
            '足りない' => [$ids[0], $ids[1]],
            '重なり' => [$ids[0], $ids[0], $ids[1]],
            '知らない写真' => [$ids[0], $ids[1], strtolower((string) Str::ulid())],
            '多すぎる' => array_fill(0, 11, $ids[0]),
            'ULID でない' => [$ids[0], $ids[1], '1 or 1=1'],
        ] as $name => $order) {
            $response = $this->send('POST', "{$base}/order", ['ids' => $order]);
            $this->assertSame(422, $response->status(), $name);
            $this->assertNotEmpty(array_filter(array_keys((array) $response->json('errors')), static fn (string $key): bool => str_starts_with($key, 'ids')), $name);
        }

        // 消すと、残りが詰めて並び、ファイルも消える
        $this->send('DELETE', "{$base}/{$ids[1]}")->assertOk()->assertJsonPath('listing.images.*.id', [$ids[2], $ids[0]])
            ->assertJsonPath('listing.images.*.position', [0, 1]);
        $this->assertCount(4, $this->allFiles());
        $this->assertSame([], array_filter($this->allFiles(), static fn (string $file): bool => str_contains($file, $ids[1])));
        $this->send('DELETE', "{$base}/{$ids[1]}")->assertNotFound();

        // 同じ写真を 2 つの要求がほぼ同時に消した（どちらもロックの前に写真を読んだ）: 消した記録は 1 回だけ
        $this->inside('pid', function () use ($ids): void {
            $image = ListingImage::query()->findOrFail($ids[0]);
            app(ListingImageService::class)->remove($image, 'test');
            app(ListingImageService::class)->remove($image, 'test');
        });

        $this->inside('pid', function (): void {
            $this->assertSame(
                ['listing.image_added', 'listing.image_added', 'listing.image_added', 'listing.image_updated', 'listing.image_updated', 'listing.images_reordered', 'listing.image_removed', 'listing.image_removed'],
                DomainEvent::query()->where('type', 'like', 'listing.image%')->orderBy('sequence')->pluck('type')->all(),
            );
        });
    }

    public function test_photos_stay_inside_their_listing_and_tenant(): void
    {
        $this->tenant('pie');
        $this->tenant('pif');
        $this->signIn();
        $listingA = $this->listing('pie');
        $listingB = $this->listing('pie');
        $other = $this->listing('pif');
        $imageId = (string) $this->upload('pie', $listingA, $this->jpeg(20, 20))->assertCreated()->json('image.id');

        // 別の商品の経路から、この写真は触れない
        $this->send('GET', "/api/tenants/pie/listings/{$listingB}/images/{$imageId}/large")->assertNotFound();
        $this->send('PATCH', "/api/tenants/pie/listings/{$listingB}/images/{$imageId}", ['alt' => 'x'])->assertNotFound();
        $this->send('DELETE', "/api/tenants/pie/listings/{$listingB}/images/{$imageId}")->assertNotFound();
        $this->send('POST', "/api/tenants/pie/listings/{$listingB}/images/order", ['ids' => [$imageId]])->assertStatus(422);

        // 別のテナントからも見えない（ID を知っていても、そのテナントの DB には無い）
        $this->send('GET', "/api/tenants/pif/listings/{$other}/images/{$imageId}/large")->assertNotFound();
        $this->upload('pif', $listingA, $this->jpeg(20, 20))->assertNotFound();

        $this->inside('pie', fn () => $this->assertSame(1, ListingImage::query()->count()));
        $this->assertCount(2, $this->allFiles());
    }

    public function test_deleting_a_tenant_also_deletes_its_photos(): void
    {
        $this->tenant('pij');
        $this->tenant('pik');
        $this->signIn();
        $this->upload('pij', $this->listing('pij'), $this->jpeg(20, 20))->assertCreated();
        $this->upload('pik', $this->listing('pik'), $this->jpeg(20, 20))->assertCreated();

        Tenant::query()->findOrFail('pij')->delete();
        $this->tenants = array_values(array_filter($this->tenants, static fn (Tenant $tenant): bool => $tenant->id !== 'pij'));

        // 消したテナントの置き場だけが消える
        $this->assertSame([], $this->disk->allFiles('tenants/pij'));
        $this->assertCount(2, $this->disk->allFiles('tenants/pik'));
    }

    public function test_strangers_and_stopped_operators_cannot_touch_photos(): void
    {
        $this->tenant('pig');
        $this->signIn();
        $listingId = $this->listing('pig');
        $imageId = (string) $this->upload('pig', $listingId, $this->jpeg(20, 20))->assertCreated()->json('image.id');

        $this->operator->update(['disabled_at' => now()]);
        $this->upload('pig', $listingId, $this->jpeg(20, 20))->assertUnauthorized();
        $this->send('GET', "/api/tenants/pig/listings/{$listingId}/images/{$imageId}/large")->assertUnauthorized();

        $this->sessionId = null;
        $this->send('GET', "/api/tenants/pig/listings/{$listingId}/images/{$imageId}/large")->assertUnauthorized();
        $this->assertCount(2, $this->allFiles());
    }

    public function test_uploads_are_rate_limited_per_operator(): void
    {
        $this->tenant('pih');
        $this->signIn();
        $listingId = $this->listing('pih');

        // 中身の検査より前に数える（壊れたファイルを大量に送って作り直しの処理を回させない）
        for ($i = 0; $i < 30; $i++) {
            $this->upload('pih', $listingId, 'not an image')->assertStatus(422);
        }

        $this->upload('pih', $listingId, $this->jpeg(20, 20))->assertStatus(429);
    }

    /**
     * @param  array<string, string>  $fields
     * @return TestResponse<Response>
     */
    private function upload(string $tenant, string $listingId, string $bytes, array $fields = [], string $name = 'photo.jpg', ?string $type = null): TestResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        if ($this->sessionId !== null) {
            $this->withCookie((string) config('admin.session_cookie'), $this->sessionId);
        }

        return $this->withHeaders(['Accept' => 'application/json'])
            ->post("{$this->adminHost}/api/tenants/{$tenant}/listings/{$listingId}/images", $fields + ['image' => new UploadedFile($path, $name, $type, null, true)]);
    }

    /**
     * @return list<string>
     */
    private function allFiles(): array
    {
        $files = $this->disk->allFiles();
        sort($files);

        return $files;
    }

    private function listing(string $tenant): string
    {
        $sellerId = $this->inside($tenant, fn (): string => (string) Seller::query()->where('slug', 'default')->value('id'));

        return (string) $this->send('POST', "/api/tenants/{$tenant}/listings", ['seller_id' => $sellerId, 'kind' => 'product', 'title' => 'シャツ', 'unit_amount' => 4900])
            ->assertCreated()->json('listing.id');
    }

    private function signIn(): void
    {
        [$this->operator] = $this->enrolledOperator();
    }
}
