<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\ListingFileUpload;
use App\Core\Plugin\Services\ListingFileUploads;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PlaceOrder;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\DownloadFiles;
use EcSugar\PluginDownloads\DownloadGrants;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeDownloads;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「ダウンロード販売」の売るファイルを上げる（ADR 0084。核の口 ListingFilesHandler と切れ端の受け取り ListingFileUploads）。
 * 切れ端に分けて受け、拡張子と中身の型の両方を確かめ、実行できる形・HTML・SVG を断る。名前は作り直して、店の頭の下の外から見えない置き場へ。
 * 数・1 つの大きさ・店の合計・順番・ほかの店の商品・ダウンロードでない商品
 */
final class DownloadsUploadTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private FilesystemAdapter $disk;

    private string $ebook;

    private string $mug;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        $this->disk = FakeDownloads::fake();
        FakeStripe::install();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Asia/Tokyo'));
        $this->configureAdminHost();
        $this->tenant('dlu');
        [$this->ebook, $this->mug] = $this->inside('dlu', function (): array {
            app(PluginStates::class)->set('downloads', true, 'test');
            $seller = Seller::query()->value('id');

            return [
                Listing::create(['seller_id' => $seller, 'kind' => 'download', 'title' => '写真集（PDF）', 'unit_amount' => 1500, 'status' => 'active'])->id,
                Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'マグカップ', 'unit_amount' => 2000, 'status' => 'active'])->id,
            ];
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_owner_uploads_a_pdf_in_chunks_and_it_is_kept_privately_under_the_shop_with_a_new_name(): void
    {
        $this->enrolledOperator();
        config(['downloads.chunk_mb' => 1]);
        // 1 MB の切れ端 2 つに分かれる大きさ
        $content = self::PDF.str_repeat("% padding\n", 150_000);

        $panel = $this->send('GET', "/api/tenants/dlu/listings/{$this->ebook}/apps")->assertOk();
        $panel->assertJsonPath('apps.0.plugin', 'downloads')->assertJsonPath('apps.0.files.max_files', 5)->assertJsonPath('apps.0.files.max_bytes', 500 * 1024 * 1024)
            ->assertJsonPath('apps.0.files.items', []);
        $this->assertContains('pdf', (array) $panel->json('apps.0.files.accept'));

        $app = $this->upload('../写真集 2026.pdf', $content);
        $app->assertOk()->assertJsonPath('app.files.items.0.name', '写真集 2026.pdf')->assertJsonPath('app.files.items.0.bytes', strlen($content));

        $this->inside('dlu', function () use ($content): void {
            $file = DownloadFile::query()->sole();
            // 置き場の名前は作り直したもの（店の頭の下・ID と拡張子だけ）。元の名前は表示用に
            $this->assertMatchesRegularExpression('#\Atenants/dlu/files/[0-9a-z]{26}\.pdf\z#', $file->path);
            $this->assertSame(['写真集 2026.pdf', 'application/pdf', hash('sha256', $content)], [$file->name, $file->mime, $file->sha256]);
            $this->assertSame($content, $this->disk->get($file->path));
            // 上げる途中のファイルは残さない
            $this->assertSame([], $this->disk->files('uploads/dlu'));
            $event = DomainEvent::query()->where('type', 'download.file_added')->sole();
            $this->assertSame(['file' => $file->id, 'bytes' => strlen($content), 'extension' => 'pdf'], $event->payload);
        });

        // 消すと並びから外れる（渡したリンクが無いので置き場からもすぐ消える）
        $id = (string) $app->json('app.files.items.0.id');
        $this->send('DELETE', "/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/files/{$id}")->assertOk()->assertJsonPath('app.files.items', []);
        $this->inside('dlu', fn () => $this->assertFalse($this->disk->exists(DownloadFile::query()->sole()->path)));
        $this->send('DELETE', "/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/files/{$id}")->assertNotFound();
    }

    public function test_executable_html_svg_and_disguised_files_are_refused(): void
    {
        $this->enrolledOperator();
        $start = fn (string $name, int $size = 100): TestResponse => $this->send('POST', "/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/uploads", ['name' => $name, 'size' => $size]);

        // 拡張子で断る（App の宣言に無い形・核が断る形・拡張子の無い名前・点で始まる名前）
        foreach (['shell.php', 'page.html', 'logo.svg', 'setup.exe', 'run.sh', 'README', '.htaccess', 'photo.pdf.php'] as $name) {
            $start($name)->assertUnprocessable()->assertJsonValidationErrors(['file']);
        }

        // 中身で断る: PDF と名乗る PHP、TXT と名乗る HTML、PNG と名乗る SVG、拡張子と違う形（PDF と名乗る ZIP）
        $disguised = [
            'invoice.pdf' => '<?php system($_GET["c"]); ?>',
            'notes.txt' => '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>',
            'logo.png' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'book.pdf' => "PK\x03\x04".str_repeat("\0", 60),
        ];

        foreach ($disguised as $name => $content) {
            $this->upload($name, $content)->assertUnprocessable()->assertJsonValidationErrors(['file']);
        }

        $this->inside('dlu', function (): void {
            $this->assertSame(0, DownloadFile::query()->count());
            $this->assertSame([], $this->disk->allFiles());
        });
    }

    public function test_chunks_must_come_in_order_from_the_operator_who_started_and_a_finished_upload_cannot_be_reused(): void
    {
        $this->enrolledOperator();
        $base = "/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/uploads";
        $upload = (string) $this->send('POST', $base, ['name' => 'book.pdf', 'size' => strlen(self::PDF)])->assertCreated()->json('upload');

        // 順番の違う切れ端・大きすぎる切れ端・知らない上げる記録
        $this->chunk($upload, '5', self::PDF)->assertUnprocessable()->assertJsonValidationErrors(['offset']);
        $this->chunk($upload, '0', self::PDF.'extra')->assertUnprocessable()->assertJsonValidationErrors(['chunk']);
        $this->chunk('01k8z3x5y7a9b1c3d5e7f9g1h3', '0', self::PDF)->assertNotFound();
        // まだそろっていないのに終える
        $this->send('POST', "{$base}/{$upload}/finish")->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->chunk($upload, '0', self::PDF)->assertOk()->assertJsonPath('received', strlen(self::PDF));
        $this->send('POST', "{$base}/{$upload}/finish")->assertOk();
        // 終えた上げる記録には足せない・もう一度は終えられない
        $this->chunk($upload, (string) strlen(self::PDF), 'x')->assertNotFound();
        $this->send('POST', "{$base}/{$upload}/finish")->assertNotFound();

        // ほかの運営が始めた上げる記録には足せない
        $other = (string) $this->send('POST', $base, ['name' => 'other.pdf', 'size' => strlen(self::PDF)])->assertCreated()->json('upload');
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->enrolledOperator('second@example.com');
        $this->chunk($other, '0', self::PDF)->assertNotFound();
    }

    public function test_the_number_size_and_shop_total_are_limited(): void
    {
        $this->enrolledOperator();
        $base = "/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/uploads";

        // 1 つの大きさ（500 MB まで）
        $this->send('POST', $base, ['name' => 'movie.mp4', 'size' => 500 * 1024 * 1024 + 1])->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->send('POST', $base, ['name' => 'empty.pdf', 'size' => 0])->assertUnprocessable();

        // 1 つの商品に 5 つまで
        for ($i = 1; $i <= 5; $i++) {
            $this->upload("part-{$i}.pdf", self::PDF)->assertOk();
        }
        $this->send('POST', $base, ['name' => 'part-6.pdf', 'size' => strlen(self::PDF)])->assertUnprocessable()->assertJsonValidationErrors(['file']);

        // 店の合計（核の上限）。上げる時と、置く錠の中の両方で
        config(['downloads.shop_quota_mb' => 1]);
        $this->inside('dlu', fn () => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'download', 'title' => '音源', 'unit_amount' => 800, 'status' => 'active']));
        $music = $this->inside('dlu', fn () => (string) Listing::query()->where('title', '音源')->value('id'));
        $this->send('POST', "/api/tenants/dlu/listings/{$music}/apps/downloads/uploads", ['name' => 'big.pdf', 'size' => 1024 * 1024])->assertUnprocessable()->assertJsonValidationErrors(['file']);

        // 同時に上げられる数（上げる途中のファイルでディスクを埋めない）
        config(['downloads.shop_quota_mb' => 100, 'downloads.uploads_at_once' => 2]);
        $this->send('POST', "/api/tenants/dlu/listings/{$music}/apps/downloads/uploads", ['name' => 'a.pdf', 'size' => 10])->assertCreated();
        $this->send('POST', "/api/tenants/dlu/listings/{$music}/apps/downloads/uploads", ['name' => 'b.pdf', 'size' => 10])->assertCreated();
        $this->send('POST', "/api/tenants/dlu/listings/{$music}/apps/downloads/uploads", ['name' => 'c.pdf', 'size' => 10])->assertUnprocessable();
    }

    public function test_files_are_only_for_download_products_of_this_shop_and_the_app_in_use(): void
    {
        $this->enrolledOperator();
        $this->tenant('dlv');
        $this->inside('dlv', fn () => app(PluginStates::class)->set('downloads', true, 'test'));

        // ふつうの商品には欄もファイルも無い
        $this->send('GET', "/api/tenants/dlu/listings/{$this->mug}/apps")->assertOk()->assertJsonPath('apps', []);
        $this->send('POST', "/api/tenants/dlu/listings/{$this->mug}/apps/downloads/uploads", ['name' => 'a.pdf', 'size' => 10])->assertNotFound();
        // ほかの店の経路からは、この店の商品は無いのと同じ
        $this->send('POST', "/api/tenants/dlv/listings/{$this->ebook}/apps/downloads/uploads", ['name' => 'a.pdf', 'size' => 10])->assertNotFound();
        // App を使っていない店では 404
        $this->inside('dlu', fn () => app(PluginStates::class)->set('downloads', false, 'test'));
        $this->send('POST', "/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/uploads", ['name' => 'a.pdf', 'size' => 10])->assertNotFound();
    }

    public function test_a_chunk_is_refused_while_another_one_holds_the_upload_and_starting_waits_for_the_shop_lock(): void
    {
        [$owner] = $this->enrolledOperator();
        $upload = (string) $this->send('POST', "/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/uploads", ['name' => 'book.pdf', 'size' => strlen(self::PDF)])->assertCreated()->json('upload');

        // ほかの要求が上げる途中のファイルの錠を持っている間は、足さずに断る（同じ切れ端を 2 つ同時に送っても二重に足さない）
        $handle = fopen($this->disk->path("uploads/dlu/{$upload}.part"), 'r+b');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX));

        try {
            $this->chunk($upload, '0', self::PDF)->assertUnprocessable()->assertJsonValidationErrors(['chunk']);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame(0, $this->disk->size("uploads/dlu/{$upload}.part"));
        // 記録（.json）を消された上げる途中のファイルには足さない（作り直しもしない）
        $this->disk->delete("uploads/dlu/{$upload}.json");
        $this->chunk($upload, '0', self::PDF)->assertNotFound();

        // 同時に上げる数を数えて作るまでは店ごとの錠の中（ほかの要求が持つ間は待つ）
        $this->inside('dlu', function () use ($owner): void {
            config(['database.connections.tenant_other' => config('database.connections.tenant')]);
            $other = DB::connection('tenant_other');
            $other->beginTransaction();

            try {
                $other->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['listing-file-uploads']);
                DB::statement("SET lock_timeout = '300ms'");

                try {
                    app(ListingFileUploads::class)->start('downloads', Listing::query()->findOrFail($this->ebook), 'a.pdf', 10, $owner->actor());
                    $this->fail('錠を待たずに上げ始めた');
                } catch (QueryException $e) {
                    $this->assertSame('55P03', $e->getCode());
                }
            } finally {
                $other->rollBack();
                DB::statement('SET lock_timeout = 0');
                DB::purge('tenant_other');
            }
        });
    }

    public function test_the_daily_purge_also_throws_away_uploads_left_for_a_day(): void
    {
        $this->enrolledOperator();
        $base = "/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/uploads";
        $fresh = (string) $this->send('POST', $base, ['name' => 'fresh.pdf', 'size' => 10])->assertCreated()->json('upload');
        // 画面を閉じたまま 25 時間たった上げる途中のファイル
        $old = '01k8z3x5y7a9b1c3d5e7f9g1h3';
        $this->disk->put("uploads/dlu/{$old}.part", '');
        $this->disk->put("uploads/dlu/{$old}.json", (string) json_encode(['plugin' => 'downloads', 'listing' => $this->ebook, 'name' => 'old.pdf', 'extension' => 'pdf', 'bytes' => 10, 'operator' => 'x', 'created' => CarbonImmutable::now()->subHours(25)->getTimestamp()]));

        $this->artisan('downloads:purge')->assertSuccessful();

        $this->assertSame(["uploads/dlu/{$fresh}.json", "uploads/dlu/{$fresh}.part"], collect($this->disk->files('uploads/dlu'))->sort()->values()->all());
    }

    public function test_a_failed_store_after_the_move_does_not_leave_the_file_behind(): void
    {
        $this->inside('dlu', function (): void {
            // 記録を書く所で落ちる（移した後）
            DB::unprepared("CREATE FUNCTION test_refuse_file_added() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.type = 'download.file_added' THEN RAISE EXCEPTION 'refused'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER test_refuse_file_added BEFORE INSERT ON domain_events FOR EACH ROW EXECUTE FUNCTION test_refuse_file_added();");
            $this->disk->put('uploads/dlu/a.part', self::PDF);

            try {
                app(DownloadFiles::class)->store(Listing::query()->findOrFail($this->ebook), new ListingFileUpload('uploads/dlu/a.part', 'book.pdf', 'pdf', 'application/pdf', strlen(self::PDF), hash('sha256', self::PDF)), 'test');
                $this->fail('記録を書けないのに置いた');
            } catch (QueryException) {
            }

            $this->assertSame(0, DownloadFile::query()->count());
            $this->assertSame([], $this->disk->allFiles('tenants'));
        });
    }

    public function test_the_order_page_shows_the_counts_and_the_app_page_resets_extends_and_stops_with_records(): void
    {
        [$owner, $key] = $this->enrolledOperator();
        $this->upload('book.pdf', self::PDF)->assertOk();
        $order = $this->inside('dlu', function (): string {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->ebook)->value('id'), 1);
            $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
            $this->assertNotNull($input);
            $transaction = app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), CheckoutSummary::fingerprintOf($carts->view($cart)));
            app(PaymentService::class)->startCheckout($transaction, 'x', 'y');
            $ref = (string) PaymentAttempt::query()->where('transaction_id', $transaction->id)->value('provider_ref');
            app(PaymentNotices::class)->confirmPaid('stripe', 'evt_1', 'checkout.session.completed', $ref, $transaction->id, (int) $transaction->total_amount, 'jpy', 'pi_1');
            event(new PaymentConfirmed($transaction->id));
            app(DownloadGrants::class)->fetch((string) DownloadGrant::query()->where('transaction_id', $transaction->id)->value('id'), CarbonImmutable::now());

            return $transaction->id;
        });

        // 注文の画面: ファイルごとの回数・最後の日時・期限（個人情報は出さない）
        $panel = $this->send('GET', "/api/tenants/dlu/transactions/{$order}/apps")->assertOk();
        $panel->assertJsonPath('apps.0.plugin', 'downloads')->assertJsonPath('apps.0.id', $order)->assertJsonPath('apps.0.tables.0.rows.0.cells.count', '1 / 5 回')
            ->assertJsonPath('apps.0.tables.0.rows.0.cells.name', 'book.pdf');
        $this->assertStringNotContainsString('hanako', (string) $panel->getContent());

        // App の画面: 注文番号で探す（大文字・ハイフン・全角も読み替える）。無い番号は断る
        $this->send('POST', '/api/tenants/dlu/apps/downloads/lookup', ['input' => strtoupper($order)])->assertOk()->assertJsonPath('id', $order);
        $this->send('POST', '/api/tenants/dlu/apps/downloads/lookup', ['input' => '01K8Z3X5Y7A9B1C3D5E7F9G1H3'])->assertStatus(409);
        $this->send('GET', '/api/tenants/dlu/apps/downloads/records?filter=active')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('rows.0.cells.downloads', 1);

        // 回数を戻す・期限を延ばす（記録に残す）。止めるは取り消せない操作の印なので、直前の再確認の後
        $base = "/api/tenants/dlu/apps/downloads/records/{$order}/actions";
        $this->send('POST', "{$base}/reset")->assertOk();
        $this->send('POST', "{$base}/extend")->assertOk();
        $this->send('POST', "{$base}/stop")->assertStatus(423);
        $this->reconfirm($owner, $key);
        $this->send('POST', "{$base}/stop")->assertOk()->assertJsonPath('record.facts.1.value.label', '停止（お店による停止）');
        $this->send('POST', "{$base}/resume")->assertOk();
        $this->inside('dlu', function () use ($order, $owner): void {
            $grant = DownloadGrant::query()->where('transaction_id', $order)->sole();
            $this->assertSame([0, null], [$grant->count, $grant->stopped_at]);
            $this->assertTrue($grant->expires_at->equalTo(CarbonImmutable::now()->addDays(60)));
            $this->assertSame(['download.reset', 'download.extended', 'download.stopped', 'download.resumed'],
                DomainEvent::query()->where('actor', $owner->actor())->whereIn('type', ['download.reset', 'download.extended', 'download.stopped', 'download.resumed'])->orderBy('id')->pluck('type')->all());
        });

        // 使っていない店では注文の画面に出さない
        $this->inside('dlu', fn () => app(PluginStates::class)->set('downloads', false, 'test'));
        $this->send('GET', "/api/tenants/dlu/transactions/{$order}/apps")->assertOk()->assertJsonPath('apps', []);
    }

    /**
     * 始めて、1 つの切れ端で送り、終える
     *
     * @return TestResponse<Response>
     */
    private function upload(string $name, string $content): TestResponse
    {
        $base = "/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/uploads";
        $upload = (string) $this->send('POST', $base, ['name' => $name, 'size' => strlen($content)])->assertCreated()->json('upload');
        $chunk = (int) config('downloads.chunk_mb') * 1024 * 1024;

        for ($offset = 0; $offset < strlen($content); $offset += $chunk) {
            $this->chunk($upload, (string) $offset, substr($content, $offset, $chunk))->assertOk();
        }

        return $this->send('POST', "{$base}/{$upload}/finish");
    }

    /**
     * 切れ端を 1 つ送る（ファイルの送信なので JSON でなく multipart で）
     *
     * @return TestResponse<Response>
     */
    private function chunk(string $upload, string $offset, string $bytes): TestResponse
    {
        $cookie = (string) config('admin.session_cookie');

        if ($this->sessionId !== null) {
            $this->withCookie($cookie, $this->sessionId);
        }

        $response = $this->post($this->adminHost."/api/tenants/dlu/listings/{$this->ebook}/apps/downloads/uploads/{$upload}",
            ['offset' => $offset, 'chunk' => UploadedFile::fake()->createWithContent('chunk', $bytes)], ['Accept' => 'application/json']);
        $next = $response->getCookie($cookie);

        if ($next !== null) {
            $this->sessionId = $next->getValue();
        }

        return $response;
    }
}
