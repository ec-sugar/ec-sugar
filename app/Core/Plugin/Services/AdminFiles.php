<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\AdminPage\AdminCsv;
use App\Core\Plugin\AdminPage\AdminField;
use App\Core\Plugin\AdminPage\AdminFileThrottled;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\CsvEncoding;
use App\Core\Plugin\AdminPage\FieldType;
use App\Core\Plugin\Contracts\AdminExportHandler;
use App\Core\Plugin\Contracts\AdminImportHandler;
use App\Core\Plugin\PluginDefinition;
use App\Core\RateLimit\Throttle;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * App の画面の CSV の書き出しとファイルの取り込み（「配送の CSV」で ADR 0051 の口に足した）。店の文脈の中だけで使う。
 * 出すのは、この店で使っている App のうち、画面の係が AdminExportHandler・AdminImportHandler も実装しているものだけ（ほかは同じ 404）。
 *
 * 核が受け持つのは:
 * - 欄の宣言と値の確かめ（AdminForms と同じ決まり）
 * - 書き出し: App の渡した文字の表を CSV にする。表計算のソフトで式として読まれる値を無害にし（頭に '）、改行と制御文字を空白に替え、
 *   引用符は要る値だけに付け、行の終わりは CRLF、文字コードを変える。行と列の数・値の長さ・ファイルの名前の形を確かめる
 * - 取り込み: ファイルの大きさ（IMPORT_MAX_BYTES まで）・空でないこと・NUL の文字が無いこと。中身はそのまま係に渡す
 * - 回数: 店 × App × 人ごとに、書き出しと取り込みを合わせて 1 時間に FILES_PER_HOUR 回まで（届け先の入ったファイルを続けて持ち出させない）
 *
 * 書き出したことの監査ログ（中央）は、運営の誰かを知っている管理画面の受け口が残す（AppPageController）
 */
final class AdminFiles
{
    public const int IMPORT_MAX_BYTES = 1_048_576;

    public const int EXPORT_MAX_ROWS = 2000;

    public const int FILES_PER_HOUR = 30;

    private const int MAX_COLUMNS = 200;

    private const int CELL_MAX = 1000;

    // 表計算のソフトが式として読む頭の文字（= + - @ とタブ・改行。全角の ＝＋－＠ も半角に直して式にすることがある。前の空白は読み飛ばされるので、空白を除いた頭で見る。CsvCells と同じ決まり）
    private const string FORMULA_HEAD = '/\A[\s\x{3000}]*[=+\-@\t\r＝＋－＠]/u';

    // 値の中で空白に替える文字: 制御文字（改行・タブを含む）と、文字の向きを変える印
    private const string CELL_UNSAFE = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    // Shift_JIS（CP932）に移すときに、JIS の形の文字を CP932 の文字へ寄せる（〜・−・‖ などがそのままでは表せないため）
    private const array CP932_FIX = [
        "\u{301C}" => "\u{FF5E}", // 〜 → ～
        "\u{2212}" => "\u{FF0D}", // − → －
        "\u{2016}" => "\u{2225}", // ‖ → ∥
        "\u{00A2}" => "\u{FFE0}", // ¢ → ￠
        "\u{00A3}" => "\u{FFE1}", // £ → ￡
        "\u{00AC}" => "\u{FFE2}", // ¬ → ￢
        "\u{2014}" => "\u{2015}", // — → ―
    ];

    public function __construct(
        private readonly AdminPages $pages,
        private readonly AdminForms $forms,
    ) {}

    /**
     * 書き出しの欄（最初の値つき）。書き出しの口の無い App は null
     *
     * @return array{label: string, fields: list<array<string, mixed>>, values: array<string, bool|string|null>}|null
     */
    public function exportForm(string $plugin): ?array
    {
        [$definition, $handler] = $this->exporter($plugin);

        return $handler === null ? null : $this->forms->blankOf($definition, $handler->exportLabel(), $handler->exportFields());
    }

    /**
     * 取り込みの欄（最初の値つき）。取り込みの口の無い App は null
     *
     * @return array{label: string, fields: list<array<string, mixed>>, values: array<string, bool|string|null>}|null
     */
    public function importForm(string $plugin): ?array
    {
        [$definition, $handler] = $this->importer($plugin);

        return $handler === null ? null : $this->forms->blankOf($definition, $handler->importLabel(), self::importFields($definition, $handler));
    }

    /**
     * 書き出す。ファイルの名前・文字コード・中身（バイト列）と、中身の行の数（監査ログに残す数）を返す
     *
     * @param  array<mixed>  $input  欄の名前 => 値
     * @return array{filename: string, charset: string, body: string, rows: int}
     *
     * @throws AdminRecordNotFound 書き出しの口の無い App
     * @throws ValidationException 欄に合わない値・書き出すものが無い
     * @throws AdminFileThrottled 続けて書き出した
     */
    public function export(string $plugin, array $input, string $actor): array
    {
        [$definition, $handler] = $this->exporter($plugin);

        if ($handler === null) {
            throw new AdminRecordNotFound;
        }

        $values = $this->forms->valuesOf($definition, $handler->exportFields(), $input);
        $this->hit($plugin, $actor);
        $csv = AdminForms::forward(static fn (): AdminCsv => $handler->export($values, $actor));

        return [
            'filename' => self::filename($definition, $csv->filename),
            'charset' => $csv->encoding->charset(),
            'body' => self::render($definition, $csv),
            'rows' => count($csv->rows),
        ];
    }

    /**
     * 取り込む。係が作った確かめの記録の ID を返す
     *
     * @param  array<mixed>  $input  欄の名前 => 値
     *
     * @throws AdminRecordNotFound 取り込みの口の無い App
     * @throws ValidationException 大きすぎる・空・読めないファイル、欄に合わない値
     * @throws AdminFileThrottled 続けて取り込んだ
     */
    public function import(string $plugin, string $content, array $input, string $actor): string
    {
        [$definition, $handler] = $this->importer($plugin);

        if ($handler === null) {
            throw new AdminRecordNotFound;
        }

        $values = $this->forms->valuesOf($definition, self::importFields($definition, $handler), $input);

        $problem = match (true) {
            $content === '' => 'ファイルが空です。',
            strlen($content) > self::IMPORT_MAX_BYTES => 'ファイルが大きすぎます（'.intdiv(self::IMPORT_MAX_BYTES, 1024).' KB まで）。分けて取り込んでください。',
            str_contains($content, "\0") => 'このファイルは読み込めません（テキストのファイルではありません）。',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['file' => $problem]);
        }

        $this->hit($plugin, $actor);
        $id = AdminForms::forward(static fn (): string => $handler->import($content, $values, $actor));

        if (preg_match('/\A[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\z/', $id) !== 1) {
            throw new LogicException("App {$definition->name}: 取り込みの記録の ID は ULID");
        }

        return $id;
    }

    /**
     * 値 1 つを CSV の欄にする（式の無害化・制御文字を空白に・要るときだけ引用符）。テストからも使う
     */
    public static function cell(string $value): string
    {
        $value = (string) preg_replace(self::CELL_UNSAFE, ' ', $value);

        if (preg_match(self::FORMULA_HEAD, $value) === 1) {
            $value = "'".$value;
        }

        return preg_match('/[",]|\A\s|\s\z/u', $value) === 1 ? '"'.str_replace('"', '""', $value).'"' : $value;
    }

    /**
     * @return array{PluginDefinition, ?AdminExportHandler}
     */
    private function exporter(string $plugin): array
    {
        $definition = $this->pages->definition($plugin);

        return [$definition, $definition->adminPage instanceof AdminExportHandler ? $definition->adminPage : null];
    }

    /**
     * @return array{PluginDefinition, ?AdminImportHandler}
     */
    private function importer(string $plugin): array
    {
        $definition = $this->pages->definition($plugin);

        return [$definition, $definition->adminPage instanceof AdminImportHandler ? $definition->adminPage : null];
    }

    /**
     * 取り込みの欄（ファイルと一緒に multipart で送るので、真偽の値を持つ切り替えは使えない）
     *
     * @return list<AdminField>
     */
    private static function importFields(PluginDefinition $plugin, AdminImportHandler $handler): array
    {
        $fields = $handler->importFields();

        foreach ($fields as $field) {
            if ($field->type === FieldType::Toggle) {
                throw new LogicException("App {$plugin->name}: 取り込みの欄に切り替えは使えない（{$field->key}）");
            }
        }

        return $fields;
    }

    /**
     * 書き出しと取り込みを合わせた回数（足した後の数で比べる。同時に押しても上限を超えない）
     */
    private function hit(string $plugin, string $actor): void
    {
        $key = 'admin-page-file:'.(string) tenant()?->getTenantKey().':'.$plugin.':'.$actor;

        if (! Throttle::allow($key, self::FILES_PER_HOUR, 3600)) {
            throw new AdminFileThrottled;
        }
    }

    private static function filename(PluginDefinition $plugin, string $name): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,55}\.csv\z/', $name) !== 1) {
            throw new LogicException("App {$plugin->name}: 書き出すファイルの名前は英小文字・数字・_・- で、.csv で終わる 60 文字まで");
        }

        return $name;
    }

    private static function render(PluginDefinition $plugin, AdminCsv $csv): string
    {
        if (count($csv->rows) > self::EXPORT_MAX_ROWS) {
            throw new LogicException("App {$plugin->name}: 書き出す行は ".self::EXPORT_MAX_ROWS.' 行まで');
        }

        $lines = [];

        foreach ($csv->header === null ? $csv->rows : [$csv->header, ...$csv->rows] as $row) {
            if ($row === [] || count($row) > self::MAX_COLUMNS) {
                throw new LogicException("App {$plugin->name}: CSV の列は 1〜".self::MAX_COLUMNS.' 個');
            }

            $cells = [];

            foreach ($row as $value) {
                if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > self::CELL_MAX) {
                    throw new LogicException("App {$plugin->name}: CSV の値は ".self::CELL_MAX.' 文字までの文字');
                }

                $cells[] = self::cell($value);
            }

            $lines[] = implode(',', $cells)."\r\n";
        }

        $body = implode('', $lines);

        return match ($csv->encoding) {
            CsvEncoding::Utf8 => $body,
            CsvEncoding::Utf8Bom => "\u{FEFF}".$body,
            CsvEncoding::ShiftJis => self::toShiftJis($body),
        };
    }

    /**
     * CP932 へ。表せない文字は「〓」（げた）に替える（取り込む側で見つけられる印。黙って消さない）
     */
    private static function toShiftJis(string $body): string
    {
        $before = mb_substitute_character();
        mb_substitute_character(0x3013);

        try {
            return mb_convert_encoding(strtr($body, self::CP932_FIX), 'CP932', 'UTF-8');
        } finally {
            mb_substitute_character($before);
        }
    }
}
