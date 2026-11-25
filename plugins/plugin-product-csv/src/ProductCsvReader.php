<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Csv\CsvCells;
use Illuminate\Validation\ValidationException;

/**
 * 上げられた CSV を読む（ファイルの形だけ。値の中身は ProductCsvRows が見る）。
 *
 * - 大きさと行の数に上限（MAX_BYTES・MAX_ROWS）。超えたら読まずに断る
 * - 文字は UTF-8（BOM はあってもなくてもよい）。UTF-8 でなければ表計算ソフトの既定の Shift_JIS（CP932）として読む。どちらでもなければ断る
 * - NUL 文字のあるファイル（画像などの別のファイル）は断る
 * - 見出しの行から形（ProductCsvFormat）を決める。知らない列・重なった列・商品を決める列が 1 つも無い見出しは断る
 * - 誤りは ValidationException（file の欄。管理画面では 422）
 */
final class ProductCsvReader
{
    /** 1 回に上げられる大きさ（5MB） */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** 1 回に読む行の数（見出しを除く） */
    public const int MAX_ROWS = 2_000;

    /** 1 つの欄の長さ（説明の写しが長い商品も読めるよう、核の説明全体の上限に合わせる） */
    public const int MAX_CELL = 50_000;

    /**
     * columns は見出しにあった鍵（見出しの順）。rows の values は鍵 => 欄の文字（前後の空白を落とし、書き出しで付けた差し込みよけの ' を外した後）、
     * complete は列の数が見出しと同じか（違う行は ProductCsvRows が誤りにする）
     *
     * @return array{format: ProductCsvFormat, columns: list<string>, rows: list<array{line: int, values: array<string, string>, complete: bool}>}
     *
     * @throws ValidationException
     */
    public function read(string $bytes): array
    {
        $text = self::text($bytes);
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new \RuntimeException('一時保存先を開けません');
        }

        try {
            fwrite($stream, $text);
            rewind($stream);
            $header = fgetcsv($stream, escape: '');

            if ($header === false || $header === [null]) {
                throw self::reject('見出しの行がありません。1 行目に列の名前を並べてください。');
            }

            [$format, $columns] = self::formatOf($header);
            $rows = [];
            $line = 1;

            while (($cells = fgetcsv($stream, escape: '')) !== false) {
                $line++;

                // 空の行（表計算ソフトが末尾に付けることがある）は数えない
                if ($cells === [null] || array_filter($cells, static fn (?string $c): bool => $c !== null && trim($c) !== '') === []) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw self::reject('行が多すぎます。1 回に '.number_format(self::MAX_ROWS).' 行までです。分けて取り込んでください。');
                }

                $values = [];

                foreach ($columns as $index => $key) {
                    $values[$key] = CsvCells::restore(trim((string) ($cells[$index] ?? '')));
                }

                $rows[] = ['line' => $line, 'values' => $values, 'complete' => count($cells) === count($columns)];
            }
        } finally {
            fclose($stream);
        }

        if ($rows === []) {
            throw self::reject('商品の行がありません。');
        }

        return ['format' => $format, 'columns' => array_values($columns), 'rows' => $rows];
    }

    /**
     * ファイルを UTF-8 の文にする（大きさを見て、BOM を外すか Shift_JIS〔CP932〕を直す）。取り込みの記録にはこの文を残す（何度読んでも同じ文になる）
     *
     * @throws ValidationException
     */
    public static function text(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw self::reject('ファイルが大きすぎます。1 回に '.(self::MAX_BYTES / 1024 / 1024).'MB までです。分けて取り込んでください。');
        }

        if (str_contains($bytes, "\0")) {
            throw self::reject('CSV のファイルではないようです。表計算ソフトで「CSV」の形で保存したファイルを選んでください。');
        }

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        if (mb_check_encoding($bytes, 'CP932')) {
            $converted = mb_convert_encoding($bytes, 'UTF-8', 'CP932');

            if (mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        throw self::reject('文字コードを読み取れません。UTF-8 か Shift_JIS の CSV にしてください。');
    }

    /**
     * @param  list<string|null>  $header
     * @return array{0: ProductCsvFormat, 1: array<int, string>} 形と、列の番号 => 鍵
     */
    private static function formatOf(array $header): array
    {
        $names = array_map(static fn (?string $name): string => trim((string) $name), $header);

        foreach (ProductCsvFormat::formats() as $format) {
            $columns = [];
            $unknown = [];

            foreach ($names as $index => $name) {
                $key = $format->headers[$name] ?? null;

                if ($key === null) {
                    $unknown[] = $name === '' ? '（名前の無い列）' : CsvCells::preview($name);
                } else {
                    $columns[$index] = $key;
                }
            }

            if ($unknown !== []) {
                $error = '使えない列があります: '.implode('・', array_slice($unknown, 0, 5)).'。列の名前は、書き出した CSV と同じにしてください。';

                continue;
            }

            if (count(array_unique($columns)) !== count($columns)) {
                throw self::reject('同じ列が 2 つあります。列は 1 つずつにしてください。');
            }

            if (array_intersect($columns, ProductCsvFormat::IDENTIFYING) === []) {
                throw self::reject('「商品ID」「品番」「商品名」のうち、少なくとも 1 つの列が必要です。');
            }

            return [$format, $columns];
        }

        throw self::reject($error ?? '列の名前を読めません。');
    }

    private static function reject(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
