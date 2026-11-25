<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use App\Core\Order\Services\SaleOrderService;
use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminAttention;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminCsv;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminField;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\AdminTable;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\AdminPage\FieldType;
use App\Core\Plugin\Contracts\AdminExportHandler;
use App\Core\Plugin\Contracts\AdminImportHandler;
use App\Core\Plugin\Contracts\AdminPageHandler;
use Carbon\CarbonImmutable;
use EcSugar\PluginShippingCsv\Models\ShippingImport;
use EcSugar\PluginShippingCsv\Models\ShippingImportRow;

/**
 * 管理画面の「配送の CSV」（ADR 0051 の App の画面と で足した書き出し・取り込みの口）。
 * 一覧は取り込んだ CSV（確かめ待ち・発送にした・取りやめた）。1 件の画面が確かめの画面で、発送にする注文と飛ばす行（理由）を並べ、
 * 「発送にする」を押すまで注文は変えない。書き出しは発送を待つ注文を運送会社の形の CSV に。
 * 一覧と 1 件には届け先（名前・住所・電話）を出さない。注文はリンクで核の注文の画面へ。送り状番号は末尾の 4 文字だけ出す（核の一覧でも出さない値なので）
 */
final class ShippingCsvAdminPage implements AdminExportHandler, AdminImportHandler, AdminPageHandler
{
    private const array FILTERS = ['pending' => '確認待ち', 'applied' => '発送にした', 'discarded' => '取りやめた', 'all' => 'すべて'];

    private const string ZONE = 'Asia/Tokyo';

    public function title(): string
    {
        return '配送の CSV';
    }

    public function filters(): array
    {
        $counts = ShippingImport::query()->toBase()->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(static fn (mixed $n): int => (int) $n);
        $counts['all'] = (int) $counts->sum();

        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], (int) ($counts[$key] ?? 0)), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [new AdminAttention('pending', '確認待ちの送り状番号', '取り込んだ CSV の中身を確認して、「発送にする」を押してください。', ShippingImport::query()->where('status', 'pending')->count(), 'warn')];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('received', '取り込んだ日時', CellType::DateTime),
            new AdminColumn('carrier', '運送会社', CellType::Text),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('ready', '発送する・した注文', CellType::Number),
            new AdminColumn('skipped', '発送にしない行', CellType::Number),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = ShippingImport::query()->when($filter !== 'all', static fn ($q) => $q->where('status', $filter));
        $total = (clone $query)->count();
        $imports = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return new AdminRows($imports->map(static fn (ShippingImport $import): AdminRow => new AdminRow($import->id, [
            'received' => $import->created_at,
            'carrier' => self::carrier($import->carrier),
            'status' => self::status($import),
            'ready' => $import->ready_count,
            'skipped' => $import->skipped_count,
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $import = ShippingImport::query()->find(strtolower($id));

        if ($import === null) {
            return null;
        }

        $pending = $import->status === 'pending';
        $same = ShippingImport::query()->where('file_hash', $import->file_hash)->whereKeyNot($import->id)->where('created_at', '<=', $import->created_at)->orderByDesc('created_at')->first();
        $facts = [
            new AdminFact('状態', CellType::Status, self::status($import)),
            new AdminFact('運送会社', CellType::Text, self::carrier($import->carrier)),
            new AdminFact('行の数', CellType::Number, $import->rows_count),
            new AdminFact($pending ? '発送にする注文' : '発送にした注文', CellType::Number, $pending ? $import->ready_count : $import->shipped_count),
            new AdminFact('発送にしない行', CellType::Number, $import->skipped_count),
            new AdminFact('同じファイル', CellType::Text, $same === null ? null : self::time($same->created_at).' に取り込んだファイルと同じです（発送済みの注文は発送にしません）'),
            new AdminFact('取り込んだ日時', CellType::DateTime, $import->created_at),
            new AdminFact($import->status === 'discarded' ? '取りやめた日時' : '発送にした日時', CellType::DateTime, $import->decided_at),
        ];

        $actions = [];

        if ($pending && $import->ready_count > 0) {
            $actions[] = new AdminAction('apply', "{$import->ready_count} 件を発送にする", "{$import->ready_count} 件の注文を、送り状番号を付けて発送済みにします。お客さんに発送のお知らせのメールが届きます。元に戻せません。");
        }

        if ($pending) {
            $actions[] = new AdminAction('discard', '取りやめる', 'この取り込みを取りやめます（注文はそのままです）。');
        }

        return new AdminRecord('取り込み '.self::time($import->created_at), $facts, self::tables($import), $actions);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'apply' => app(Imports::class)->apply($id, $actor),
            'discard' => app(Imports::class)->discard($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    public function exportLabel(): string
    {
        return 'CSV を書き出す';
    }

    public function exportFields(): array
    {
        $formats = [];

        foreach (CsvFormat::cases() as $format) {
            $formats[$format->value] = $format->label();
        }

        $new = Parcels::waiting(true);
        $all = Parcels::waiting(false);
        $cod = Parcels::cashOnDelivery();
        // 代金引換の注文は書き出さない（この CSV の送り状は発払い。ADR 0074）
        $codNote = $cod > 0 ? " 代引きの注文 {$cod} 件は、運送会社の画面で代引きの送り状を作ってください（この CSV には入りません）。" : '';

        return [
            new AdminField('format', FieldType::Choice, '運送会社の形式', help: '使っている送り状のソフトを選びます。文字コードは Shift_JIS です。', required: true, options: $formats, default: CsvFormat::YamatoB2->value),
            new AdminField('target', FieldType::Choice, '書き出す注文', help: '発送待ちの注文とは、入金済みでまだ発送していない注文です（古い順に '.Parcels::MAX.' 件まで）。'.$codNote, required: true, options: [
                'new' => "まだ書き出していない発送待ちの注文（{$new} 件）",
                'all' => "発送待ちの注文すべて（{$all} 件）",
                'numbers' => '下に書いた注文番号だけ',
            ], default: 'new'),
            new AdminField('numbers', FieldType::Textarea, '注文番号', help: '「下に書いた注文番号だけ」のとき。1 行に 1 つ（'.Exports::MAX_NUMBERS.' 個まで）。', max: FieldType::TEXTAREA_MAX),
            new AdminField('ship_date', FieldType::Text, '出荷予定日', help: '例: '.CarbonImmutable::now(self::ZONE)->format('Y-m-d').'。空なら今日の日付にします。', max: 10),
        ];
    }

    public function export(array $values, string $actor): AdminCsv
    {
        return app(Exports::class)->export($values, $actor);
    }

    public function importLabel(): string
    {
        return 'CSV を取り込む';
    }

    public function importFields(): array
    {
        return [
            new AdminField('carrier', FieldType::Choice, '運送会社', help: '発送のお知らせのメールと注文の画面に出す運送会社です。', required: true, options: SaleOrderService::CARRIERS),
        ];
    }

    public function import(string $content, array $values, string $actor): string
    {
        return app(Imports::class)->create($content, (string) ($values['carrier'] ?? ''), $actor);
    }

    /**
     * 発送にする（した）注文の表と、飛ばす行の表（どちらも先頭の 200 行）
     *
     * @return list<AdminTable>
     */
    private static function tables(ShippingImport $import): array
    {
        $limit = 200;
        $go = $import->rows()->whereIn('status', ['ready', 'shipped'])->orderBy('line')->limit($limit)->get();
        $skipped = $import->rows()->where('status', 'skipped')->orderBy('line')->limit($limit)->get();
        $tables = [];
        $goCount = $import->status === 'pending' ? $import->ready_count : $import->shipped_count;

        if ($go->isNotEmpty()) {
            $tables[] = new AdminTable(($import->status === 'pending' ? '発送にする注文' : '発送にした注文').($goCount > $limit ? "（先頭の {$limit} 行）" : ''), [
                new AdminColumn('line', '行', CellType::Number),
                new AdminColumn('order', '注文', CellType::Link),
                new AdminColumn('tracking', '送り状番号', CellType::Text),
            ], $go->map(static fn (ShippingImportRow $row): AdminRow => new AdminRow(null, [
                'line' => $row->line,
                'order' => self::orderLink($row),
                'tracking' => self::masked($row->tracking_number),
            ]))->values()->all());
        }

        if ($skipped->isNotEmpty()) {
            $tables[] = new AdminTable('発送にしない行'.($import->skipped_count > $limit ? "（先頭の {$limit} 行）" : ''), [
                new AdminColumn('line', '行', CellType::Number),
                new AdminColumn('order', '注文', CellType::Link),
                new AdminColumn('reference', '注文番号', CellType::Text),
                new AdminColumn('reason', '理由', CellType::Text),
            ], $skipped->map(static fn (ShippingImportRow $row): AdminRow => new AdminRow(null, [
                'line' => $row->line,
                'order' => self::orderLink($row),
                'reference' => $row->reference,
                'reason' => RowReason::tryFrom((string) $row->reason)?->label(),
            ]))->values()->all());
        }

        return $tables;
    }

    /**
     * @return array{label: string, kind: string, id: string}|null
     */
    private static function orderLink(ShippingImportRow $row): ?array
    {
        return $row->transaction_id === null || $row->reference === null ? null : ['label' => $row->reference, 'kind' => 'transaction', 'id' => $row->transaction_id];
    }

    /**
     * 送り状番号は末尾の 4 文字だけ（運送会社のソフトの番号と照らすのに足りる）
     */
    private static function masked(?string $tracking): ?string
    {
        return $tracking === null ? null : '…'.substr($tracking, -4);
    }

    private static function carrier(string $carrier): string
    {
        return SaleOrderService::CARRIERS[$carrier] ?? $carrier;
    }

    private static function time(?CarbonImmutable $at): string
    {
        return $at === null ? '' : $at->setTimezone(self::ZONE)->format('Y/m/d H:i');
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function status(ShippingImport $import): array
    {
        return match ($import->status) {
            'pending' => ['label' => '確認待ち', 'tone' => 'warn'],
            'applied' => ['label' => '発送にした', 'tone' => 'good'],
            default => ['label' => '取りやめた', 'tone' => 'muted'],
        };
    }
}
