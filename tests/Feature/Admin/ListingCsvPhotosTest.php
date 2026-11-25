<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\HostResolver;
use App\Core\Media\Services\ShopMediaService;
use App\Storefront\View\StorefrontUrls;
use EcSugar\PluginProductCsv\Jobs\ApplyProductImport;
use EcSugar\PluginProductCsv\Models\ProductImport;
use EcSugar\PluginProductCsv\PhotoArchive;
use EcSugar\PluginProductCsv\ProductImports;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;
use ZipArchive;

/**
 * 商品の CSV の写真の列（写真1〜写真10）と、一緒に上げる写真の ZIP（docs/design/product-csv.md の「写真」）。
 * 外への要求はすべて偽物（Http::fake と、名前を引く部品の差し替え）で、本物の外へは出ない。
 * 確かめでは取りに行かず、反映で商品を全部入れた後に写真を 1 枚ずつ入れる。1 枚が取れなくても止めず、置き場の上限で止まる
 */
final class ListingCsvPhotosTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use OperatorSession;
    use WithTenants;

    private Operator $operator;

    private Filesystem $disk;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->disk = FakeMedia::fake();
        Cache::store()->flush();
        Http::preventStrayRequests();
        config(['media.uploads_per_minute' => 1000]);
        // 名前を引く部品の偽物（本物の DNS を引かない）。決めていない名前は外の住所
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'intranet.example.com' => ['10.0.0.5'],
                    // 外と内の両方を返す名前（1 つでも内向きなら断る）
                    'rebind.example.com' => ['93.184.216.35', '192.168.1.10'],
                    'metadata.example.com' => ['169.254.169.254'],
                    'v6.example.com' => ['fd00::1'],
                    default => ['93.184.216.34'],
                };
            }
        });
    }

    protected function tearDown(): void
    {
        ProductImports::$jobSeconds = 45.0;
        PhotoArchive::$maxTotal = 300 * 1024 * 1024;
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_photos_from_urls_and_a_zip_are_added_in_csv_order_and_importing_again_makes_no_duplicates(): void
    {
        $this->tenant('cpa');
        [$this->operator] = $this->enrolledOperator();
        [$a, $b, $c] = [$this->jpeg(400, 300), $this->jpeg(300, 400), $this->png(200, 200)];
        Http::fake([
            'https://cdn.example.com/a.jpg' => Http::response($a, 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/c.png' => Http::response($c, 200, ['Content-Type' => 'image/png']),
        ]);
        // 品番で今の商品に合わせる（品番の無い行をもう一度上げると、新しい商品になる）
        $csv = $this->csv(['品番', '商品名', '価格', '写真1', '写真2', '写真3'], [['MUG', 'マグ', '1200', 'https://cdn.example.com/a.jpg', 'b.jpg', 'https://cdn.example.com/c.png']]);
        // ZIP の中はフォルダの下でもよい（フォルダを除いた名前が 1 つだけ当たれば、その写真）
        $zip = $this->zip(['mug/b.jpg' => $b]);

        $import = $this->upload('cpa', $csv, $zip)->assertStatus(202)->assertJsonPath('import.status', 'checked')->assertJsonPath('import.has_archive', true)->json('import');
        $this->assertSame(['add' => 3, 'keep' => 0, 'warnings_total' => 0, 'warnings' => []], $import['report']['photos']);
        // 確かめでは取りに行かない
        Http::assertNothingSent();

        $this->send('POST', "/api/tenants/cpa/listings-csv/imports/{$import['id']}/apply")->assertStatus(202)
            ->assertJsonPath('import.status', 'done')
            ->assertJsonPath('import.photos.added', 3)->assertJsonPath('import.photos.failed', 0)->assertJsonPath('import.has_archive', false);
        Http::assertSentCount(2);

        $this->inside('cpa', function () use ($a, $b, $c): void {
            $listing = Listing::query()->sole();
            // CSV の順（写真1 → 写真3）。元のファイルの中身のハッシュが残る
            $images = ListingImage::query()->where('listing_id', $listing->id)->orderBy('position')->get();
            $this->assertSame([hash('sha256', $a), hash('sha256', $b), hash('sha256', $c)], $images->pluck('source_digest')->all());
            $this->assertSame([hash('sha256', 'https://cdn.example.com/a.jpg'), null, hash('sha256', 'https://cdn.example.com/c.png')], $images->pluck('source_url_digest')->all());
            // 写真は核の写真のサービスを通る（作り直して置き、取り込んだ人の記録が残る）
            $added = DomainEvent::query()->where('type', 'listing.image_added')->get();
            $this->assertCount(3, $added);
            $this->assertSame(['operator:'.$this->operator->id], $added->pluck('actor')->unique()->values()->all());
            $this->assertTrue($this->disk->exists(ListingImage::path('cpa', $listing->id, $images[0]->id, 'large')));
        });
        // 済んだら ZIP の置き場は消える
        $this->assertSame([], $this->disk->allFiles('tenants/cpa/imports'));

        // 同じ CSV と ZIP をもう一度: 3 枚とも「付いている」で、足す写真も変わる行も無い（取りに行き直さない）
        $again = $this->upload('cpa', $csv, $this->zip(['mug/b.jpg' => $b]))->assertJsonPath('import.status', 'checked')->json('import');
        $this->assertSame([0, 3], [$again['report']['photos']['add'], $again['report']['photos']['keep']]);
        $this->send('POST', "/api/tenants/cpa/listings-csv/imports/{$again['id']}/apply")->assertStatus(409);
        Http::assertSentCount(2);

        // 書き出しは写真の公開の URL を並び順に。その CSV を取り込んでも、この店の写真と見分けて取りに行かない
        $exported = (string) $this->send('GET', '/api/tenants/cpa/listings-csv')->assertOk()->getContent();
        $ids = $this->inside('cpa', static fn (): array => ListingImage::query()->orderBy('position')->pluck('id')->all());
        $row = $this->parse($exported)[0];
        $this->assertSame(StorefrontUrls::base('cpa.localhost')."/media/listings/{$ids[0]}/large", $row['写真1']);
        $this->assertSame(['', ''], [$row['写真4'], $row['写真10']]);
        $roundTrip = $this->upload('cpa', $exported)->assertJsonPath('import.status', 'checked')->json('import');
        $this->assertSame([0, 3, 0], [$roundTrip['report']['photos']['add'], $roundTrip['report']['photos']['keep'], $roundTrip['report']['photos']['warnings_total']]);
        Http::assertSentCount(2);
    }

    public function test_urls_to_internal_addresses_plain_http_inward_redirects_and_bad_responses_are_left_out_without_stopping(): void
    {
        $this->tenant('cpb');
        [$this->operator] = $this->enrolledOperator();
        $ok = $this->jpeg(300, 200);
        Http::fake([
            // リダイレクトで内向きの住所（クラウドの設定の口）へ連れ出す
            'https://cdn.example.com/redirect.jpg' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data']),
            'https://cdn.example.com/redirect-name.jpg' => Http::response('', 301, ['Location' => 'https://metadata.example.com/x.jpg']),
            // 大きさの知らせが無くても、受け取った量で止める
            'https://cdn.example.com/big.jpg' => Http::response(str_repeat('a', 10 * 1024 * 1024 + 1), 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/page.jpg' => Http::response('<html><script>alert(1)</script></html>', 200, ['Content-Type' => 'text/html']),
            'https://cdn.example.com/fake.jpg' => Http::response('<?php echo 1;', 200, ['Content-Type' => 'image/jpeg']),
            // 時間切れ・つながらない
            'https://cdn.example.com/down.jpg' => Http::failedConnection(),
            'https://cdn.example.com/ok.jpg' => Http::response($ok, 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('', 500),
        ]);
        $urls = [
            'http://cdn.example.com/plain.jpg',
            'https://127.0.0.1/a.jpg',
            'https://intranet.example.com/a.jpg',
            'https://rebind.example.com/a.jpg',
            'https://cdn.example.com/redirect.jpg',
            'https://cdn.example.com/redirect-name.jpg',
            'https://cdn.example.com/big.jpg',
            'https://cdn.example.com/page.jpg',
            'https://cdn.example.com/fake.jpg',
            'https://cdn.example.com/ok.jpg',
        ];
        $headers = ['商品名', '価格', ...array_map(static fn (int $n): string => "写真{$n}", range(1, 10))];
        $csv = $this->csv($headers, [['マグ', '1200', ...$urls], ['皿', '900', 'https://cdn.example.com/down.jpg', 'https://v6.example.com/a.jpg', ...array_fill(0, 8, '')]]);

        // 確かめでは形だけを見る（http と内向きの住所そのものは、取りに行く前に分かる）。商品の取り込みは止めない
        $import = $this->upload('cpb', $csv)->assertJsonPath('import.status', 'checked')->json('import');
        $this->assertSame(['add' => 10, 'keep' => 0, 'warnings_total' => 2], array_intersect_key($import['report']['photos'], array_flip(['add', 'keep', 'warnings_total'])));
        $this->assertSame([['line' => 2, 'column' => '写真1'], ['line' => 2, 'column' => '写真2']], array_map(static fn (array $w): array => ['line' => $w['line'], 'column' => $w['column']], $import['report']['photos']['warnings']));
        $this->assertStringContainsString('https', $import['report']['photos']['warnings'][0]['message']);
        $this->assertStringContainsString('内向き', $import['report']['photos']['warnings'][1]['message']);

        $done = $this->send('POST', "/api/tenants/cpb/listings-csv/imports/{$import['id']}/apply")->assertStatus(202)->assertJsonPath('import.status', 'done')->json('import');
        $this->assertSame([12, 1, 11], [$done['photos']['total'], $done['photos']['added'], $done['photos']['failed']]);
        $reasons = [];

        foreach ($done['photos']['problems'] as $problem) {
            $reasons["{$problem['line']}:{$problem['column']}"] = $problem['message'];
        }

        $this->assertStringContainsString('内向き', $reasons['2:写真3']);
        $this->assertStringContainsString('内向き', $reasons['2:写真4']);
        $this->assertStringContainsString('内向き', $reasons['2:写真5']);
        $this->assertStringContainsString('内向き', $reasons['2:写真6']);
        $this->assertStringContainsString('大きすぎます', $reasons['2:写真7']);
        $this->assertStringContainsString('画像ではありません（text/html）', $reasons['2:写真8']);
        $this->assertStringContainsString('画像として読めません', $reasons['2:写真9']);
        $this->assertStringContainsString('接続できませんでした', $reasons['3:写真1']);
        $this->assertStringContainsString('内向き', $reasons['3:写真2']);

        // 内向きの行き先へは 1 回も要求を送らない（リダイレクトの先も）。http へも送らない
        Http::assertNotSent(static fn (Request $request): bool => preg_match('#\Ahttp://|//(127\.0\.0\.1|169\.254\.169\.254|intranet\.|rebind\.|metadata\.|v6\.)#', $request->url()) === 1);

        $this->inside('cpb', function () use ($ok): void {
            $this->assertSame(2, Listing::query()->count(), '写真が取れなくても商品は全部入る');
            $this->assertSame([hash('sha256', $ok)], ListingImage::query()->pluck('source_digest')->all());
        });
    }

    public function test_zip_entries_outside_the_folder_symlinks_encrypted_and_non_images_are_left_out(): void
    {
        $this->tenant('cpc');
        [$this->operator] = $this->enrolledOperator();
        $ok = $this->jpeg(320, 240);
        $zip = $this->zip([
            '../evil.jpg' => $ok,
            '/abs.jpg' => $this->jpeg(10, 10),
            'link.jpg' => '/etc/passwd',
            'note.jpg' => 'これは写真ではありません',
            'ok.jpg' => $ok,
            '__MACOSX/._ok.jpg' => 'macOS の付け足し',
            'big.jpg' => str_repeat("\0", 10 * 1024 * 1024 + 1),
            'secret.jpg' => $this->jpeg(20, 20),
        ], symlinks: ['link.jpg'], encrypted: ['secret.jpg']);
        // 写真1・写真2 はフォルダを除いた名前で ZIP の中の危ない名前のファイルに当たる（ZIP の側で断る）。写真9 は CSV に書いた名前そのものが道筋の外
        $csv = $this->csv(['商品名', '価格', '写真1', '写真2', '写真3', '写真4', '写真5', '写真6', '写真7', '写真8', '写真9'],
            [['マグ', '1200', 'evil.jpg', 'abs.jpg', 'link.jpg', 'note.jpg', 'missing.jpg', 'ok.jpg', 'big.jpg', 'secret.jpg', '../ok.jpg']]);

        $import = $this->upload('cpc', $csv, $zip)->assertJsonPath('import.status', 'checked')->json('import');
        $photos = $import['report']['photos'];
        $this->assertSame([1, 8], [$photos['add'], $photos['warnings_total']]);
        $messages = array_column($photos['warnings'], 'message', 'column');
        $this->assertStringContainsString('..', $messages['写真1']);
        $this->assertStringContainsString('/', $messages['写真2']);
        $this->assertStringContainsString('シンボリックリンク', $messages['写真3']);
        $this->assertStringContainsString('画像として読めません', $messages['写真4']);
        $this->assertStringContainsString('ZIP の中に見つかりません', $messages['写真5']);
        $this->assertStringContainsString('大きすぎます', $messages['写真7']);
        $this->assertStringContainsString('暗号化', $messages['写真8']);
        $this->assertStringContainsString('..', $messages['写真9']);

        // 置き場に置いたのは、こちらが決めた名前のファイルだけ（ZIP の中の名前の道筋を使わない）。店の置き場の外には何も無い
        $placed = $this->disk->allFiles();
        $this->assertNotSame([], $placed);

        foreach ($placed as $file) {
            $this->assertMatchesRegularExpression('#\Atenants/cpc/imports/[0-9a-z]{26}/[0-9]{5}\.img\z#', $file);
        }

        $this->send('POST', "/api/tenants/cpc/listings-csv/imports/{$import['id']}/apply")->assertStatus(202)->assertJsonPath('import.photos.added', 1);
        $this->inside('cpc', fn () => $this->assertSame([hash('sha256', $ok)], ListingImage::query()->pluck('source_digest')->all()));
        $this->assertSame([], $this->disk->allFiles('tenants/cpc/imports'));
    }

    public function test_broken_or_oversized_zips_and_zip_bombs_are_refused_without_a_record_or_files(): void
    {
        $this->tenant('cpd');
        [$this->operator] = $this->enrolledOperator();
        $csv = $this->csv(['商品名', '価格', '写真1'], [['マグ', '1200', 'a.jpg']]);

        // ZIP でないもの
        $broken = (string) tempnam(sys_get_temp_dir(), 'zip');
        $this->files[] = $broken;
        file_put_contents($broken, "PK\x03\x04 これは壊れた ZIP");
        $this->upload('cpd', $csv, $broken)->assertStatus(422)->assertJsonValidationErrors('photos');

        // 中のファイルが多すぎる
        $many = [];

        foreach (range(0, PhotoArchive::MAX_ENTRIES) as $n) {
            $many["f{$n}.jpg"] = 'x';
        }

        $this->upload('cpd', $csv, $this->zip($many))->assertStatus(422)->assertJsonPath('errors.photos.0', 'ZIP の中のファイルが多すぎます。1 回に 2,500 個までです。');

        // 展開すると大きすぎる（ZIP 爆弾。小さな ZIP が展開で膨らむ）。読みながら数えて止め、途中まで置いたファイルも消す
        PhotoArchive::$maxTotal = 1024 * 1024;
        $bomb = $this->zip(['a.jpg' => $this->jpeg(50, 50), 'b.jpg' => str_repeat("\0", 700 * 1024), 'c.jpg' => str_repeat("\0", 700 * 1024)]);
        $this->assertLessThan(20 * 1024, (int) filesize($bomb));
        $this->upload('cpd', $csv, $bomb)->assertStatus(422)->assertJsonPath('errors.photos.0', 'ZIP を展開すると大きすぎます（合計 1MB まで）。');
        PhotoArchive::$maxTotal = 300 * 1024 * 1024;

        // ZIP の大きさ（入口の検査が先に断る）
        $this->upload('cpd', $csv, UploadedFile::fake()->create('huge.zip', PhotoArchive::MAX_BYTES / 1024 + 1))->assertStatus(422)->assertJsonValidationErrors('photos');

        // CSV に写真の列が無いのに ZIP を上げた
        $this->upload('cpd', $this->csv(['商品名', '価格'], [['マグ', '1200']]), $this->zip(['a.jpg' => $this->jpeg(10, 10)]))
            ->assertStatus(422)->assertJsonValidationErrors('photos');

        // 写真が 1 回の上限（2,000 枚）を超える CSV
        $rows = array_fill(0, 201, ['マグ', '1200', ...array_fill(0, 10, 'a.jpg')]);
        $this->upload('cpd', $this->csv(['商品名', '価格', ...array_map(static fn (int $n): string => "写真{$n}", range(1, 10))], $rows))
            ->assertStatus(422)->assertJsonPath('errors.file.0', '写真が多すぎます。1 回に 2,000 枚までです。分けて取り込んでください。');

        $this->inside('cpd', fn () => $this->assertSame(0, ProductImport::query()->count()));
        $this->assertSame([], $this->disk->allFiles());
    }

    public function test_a_full_media_store_stops_the_photos_and_importing_again_adds_only_the_rest(): void
    {
        $this->tenant('cpe');
        [$this->operator] = $this->enrolledOperator();
        [$a, $b, $c] = [$this->jpeg(400, 300), $this->jpeg(300, 400), $this->jpeg(200, 200)];
        Http::fake([
            'https://cdn.example.com/a.jpg' => Http::response($a, 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/c.jpg' => Http::response($c, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        // 写真の置き場を使い切った店（上限 1 MB）
        config(['media.shop_quota_mb' => 1]);
        $mediaId = $this->inside('cpe', function (): string {
            $media = app(ShopMediaService::class)->add($this->photoFile($this->jpeg(40, 40)));
            DB::table('shop_media')->where('id', $media->id)->update(['bytes' => 1024 * 1024]);

            return $media->id;
        });
        $csv = $this->csv(['商品名', '価格', '写真1', '写真2'], [['マグ', '1200', 'https://cdn.example.com/a.jpg', 'b.jpg'], ['皿', '900', 'https://cdn.example.com/c.jpg', '']]);
        $import = $this->upload('cpe', $csv, $this->zip(['b.jpg' => $b]))->assertJsonPath('import.report.photos.add', 3)->json('import');

        // 商品は全部入り、写真の段の最初の 1 枚で止まる（店の置き場を使い切らない）
        $stopped = $this->send('POST', "/api/tenants/cpe/listings-csv/imports/{$import['id']}/apply")->assertStatus(202)->assertJsonPath('import.status', 'stopped')->json('import');
        $this->assertSame([2, true, 2], [$stopped['stopped_line'], $stopped['retryable'], $stopped['applied_rows']]);
        $this->assertStringContainsString('「写真1」: 写真の保存容量がいっぱいです', $stopped['stopped_reason']);
        $this->inside('cpe', function (): void {
            $this->assertSame(['マグ', '皿'], Listing::query()->orderBy('created_at')->pluck('title')->all());
            $this->assertSame(0, ListingImage::query()->count());
        });
        // 止まった取り込みの ZIP は、もう一度取り込むために残す
        $this->assertNotSame([], $this->disk->allFiles('tenants/cpe/imports'));

        // 使っていない写真を消して空けたので、もう一度取り込む。商品は「変わらない」で、写真だけが入る（ZIP も引き継ぐ）
        $this->inside('cpe', static fn () => DB::table('shop_media')->where('id', $mediaId)->update(['bytes' => 0]));
        $retry = $this->send('POST', "/api/tenants/cpe/listings-csv/imports/{$import['id']}/retry")->assertStatus(202)->json('import');
        $this->assertSame([2, 3], [$retry['report']['counts']['skip'], $retry['report']['photos']['add']]);
        $this->send('POST', "/api/tenants/cpe/listings-csv/imports/{$retry['id']}/apply")->assertStatus(202)
            ->assertJsonPath('import.status', 'done')->assertJsonPath('import.photos.added', 3);

        $this->inside('cpe', function () use ($a, $b, $import): void {
            $mug = Listing::query()->where('title', 'マグ')->sole();
            $this->assertSame([hash('sha256', $a), hash('sha256', $b)], ListingImage::query()->where('listing_id', $mug->id)->orderBy('position')->pluck('source_digest')->all());
            $this->assertSame(3, ListingImage::query()->count());
            $this->assertNull(ProductImport::query()->findOrFail($import['id'])->archive);
        });
        $this->assertSame([], $this->disk->allFiles('tenants/cpe/imports'));
    }

    public function test_the_per_minute_photo_limit_waits_with_a_delayed_job_instead_of_holding_the_worker(): void
    {
        $this->tenant('cpf');
        [$this->operator] = $this->enrolledOperator();
        config(['media.uploads_per_minute' => 1]);
        Http::fake([
            'https://cdn.example.com/a.jpg' => Http::response($this->jpeg(100, 100), 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/b.jpg' => Http::response($this->jpeg(120, 100), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $import = $this->upload('cpf', $this->csv(['商品名', '価格', '写真1', '写真2'], [['マグ', '1200', 'https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg']]))->json('import');

        Queue::fake();
        $this->send('POST', "/api/tenants/cpf/listings-csv/imports/{$import['id']}/apply")->assertStatus(202);
        $this->inside('cpf', static fn () => app(ProductImports::class)->runApply($import['id']));

        // 1 枚入れたところで 1 分の回数に触れたので、空くまで遅らせた続きの仕事を出す（走らせ役を眠らせない）
        Queue::assertPushed(ApplyProductImport::class, static fn (ApplyProductImport $job): bool => $job->importId === $import['id'] && $job->delay !== null);
        $this->send('GET', "/api/tenants/cpf/listings-csv/imports/{$import['id']}")
            ->assertJsonPath('import.status', 'running')->assertJsonPath('import.photo_phase', true)->assertJsonPath('import.photos_done', 1)->assertJsonPath('import.photos.added', 1);

        $this->travel(61)->seconds();
        $this->inside('cpf', static fn () => app(ProductImports::class)->runApply($import['id']));
        $this->send('GET', "/api/tenants/cpf/listings-csv/imports/{$import['id']}")->assertJsonPath('import.status', 'done')->assertJsonPath('import.photos.added', 2);
    }

    public function test_another_shops_photo_urls_are_not_copied_from_its_storage_and_viewers_cannot_upload_photos(): void
    {
        $this->tenant('cpg');
        $this->tenant('cph');
        [$this->operator] = $this->enrolledOperator();
        $theirs = $this->inside('cph', function (): string {
            $listing = Listing::create(['seller_id' => Seller::query()->firstOrFail()->id, 'kind' => 'product', 'title' => 'ほかの店のマグ', 'unit_amount' => 1000, 'status' => 'active']);

            return app(ListingImageService::class)->add($listing, $this->photoFile($this->jpeg(60, 60)))->id;
        });

        // ほかの店の写真の URL は、この店の写真ではない（ほかの店の置き場から写さない）。外の URL として扱い、http なので取りに行かない
        $import = $this->upload('cpg', $this->csv(['商品名', '価格', '写真1'], [['マグ', '1200', "http://cph.localhost/media/listings/{$theirs}/large"]]))->json('import');
        $this->assertSame([0, 1], [$import['report']['photos']['add'], $import['report']['photos']['warnings_total']]);
        $this->send('POST', "/api/tenants/cpg/listings-csv/imports/{$import['id']}/apply")->assertStatus(202)->assertJsonPath('import.photos.failed', 1);
        $this->inside('cpg', fn () => $this->assertSame(0, ListingImage::query()->count()));
        $this->inside('cph', fn () => $this->assertSame(1, ListingImage::query()->count()));
        Http::assertNothingSent();

        // 閲覧の段階の人は、ZIP を一緒に上げられない（置き場に何も残らない）
        $this->send('POST', '/auth/logout')->assertNoContent();
        [$staff] = $this->enrolledOperator('viewer@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'cpg', 'read', 'test');
        $this->upload('cpg', $this->csv(['商品名', '価格', '写真1'], [['皿', '1', 'a.jpg']]), $this->zip(['a.jpg' => $this->jpeg(10, 10)]))->assertForbidden();
        $this->assertSame([], $this->disk->allFiles('tenants/cpg/imports'));
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, intdiv($height, 2), (int) imagecolorallocate($image, 200, 60, 20));

        return $this->encode($image, 'png');
    }

    private function photoFile(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @param  array<string, string>  $entries  中の名前 => 中身
     * @param  list<string>  $symlinks  シンボリックリンクの印を付ける名前
     * @param  list<string>  $encrypted  暗号化する名前
     */
    private function zip(array $entries, array $symlinks = [], array $encrypted = []): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zip');
        $this->files[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));

        foreach ($entries as $name => $bytes) {
            $this->assertTrue($zip->addFromString($name, $bytes), "ZIP に {$name} を入れられない");
        }

        foreach ($symlinks as $name) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0o120777 << 16);
        }

        foreach ($encrypted as $name) {
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256, 'secret');
        }

        $zip->close();

        return $path;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+b');
        $this->assertIsResource($stream);
        fputcsv($stream, $headers, escape: '', eol: "\r\n");

        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '', eol: "\r\n");
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parse(string $csv): array
    {
        $stream = fopen('php://temp', 'r+b');
        $this->assertIsResource($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $headers = fgetcsv($stream, escape: '');
        $this->assertIsArray($headers);
        $rows = [];

        while (($cells = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = array_combine(array_map('strval', $headers), array_map('strval', $cells));
        }

        fclose($stream);

        return $rows;
    }

    /**
     * CSV（と写真の ZIP）を上げて確かめを始める（テストのキューは sync なので、答えの時には確かめが済んでいる）
     *
     * @return TestResponse<Response>
     */
    private function upload(string $tenant, string $bytes, string|UploadedFile|null $zip = null): TestResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $bytes);
        $this->files[] = $path;
        $cookie = (string) config('admin.session_cookie');

        if ($this->sessionId !== null) {
            $this->withCookie($cookie, $this->sessionId);
        }

        $data = ['file' => new UploadedFile($path, 'products.csv', 'text/csv', null, true)];

        if ($zip !== null) {
            $data['photos'] = $zip instanceof UploadedFile ? $zip : new UploadedFile($zip, 'photos.zip', 'application/zip', null, true);
        }

        $response = $this->withHeader('Accept', 'application/json')->post("{$this->adminHost}/api/tenants/{$tenant}/listings-csv/check", $data);
        $next = $response->getCookie($cookie);

        if ($next !== null) {
            $this->sessionId = $next->getValue();
        }

        return $response;
    }
}
