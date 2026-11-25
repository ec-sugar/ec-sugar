<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use Illuminate\Validation\ValidationException;

/**
 * 取り込んだ CSV を読む。文字コードは UTF-8（BOM があれば外す）か Shift_JIS（CP932）。
 * 1 行目の項目名から注文番号と送り状番号の列を探す（運送会社の発行済みのデータの書き出し・この App の簡単な形）。
 * 項目名が見つからなければ「注文番号, 送り状番号」の 2 列（項目名の行なし）として読む。中身の行は MAX_ROWS 行まで
 */
final class ImportReader
{
    public const int MAX_ROWS = 1000;

    // 注文番号の列の項目名（空白・点を除き、半角・小文字にして比べる）
    private const array REFERENCE_HEADERS = ['注文番号', 'ご注文番号', 'お客様管理番号', 'お客様管理ナンバー', 'お客様側管理番号', 'お客様管理no', '管理番号', 'ordernumber', 'order'];

    // 送り状番号の列の項目名
    private const array TRACKING_HEADERS = ['送り状番号', '伝票番号', 'お問合せ送り状no', 'お問い合わせ送り状no', 'お問い合わせ番号', 'お問合せ番号', '問合せ番号', '問い合わせ番号', '追跡番号', 'trackingnumber', 'tracking'];

    /**
     * @return list<array{line: int, reference: ?string, tracking: ?string}> line はファイルの何件目か（1 から。項目名の行も数える）
     *
     * @throws ValidationException 読めない・行が無い・多すぎる（file）
     */
    public static function read(string $content): array
    {
        $records = self::records(self::decode($content));
        [$referenceAt, $trackingAt, $skip] = self::columns($records[0] ?? []);
        $rows = [];

        foreach ($records as $index => $cells) {
            if ($index < $skip || implode('', $cells) === '') {
                continue;
            }

            $rows[] = ['line' => $index + 1, 'reference' => self::cell($cells, $referenceAt), 'tracking' => self::cell($cells, $trackingAt)];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'CSV に取り込む行がありません。']);
        }

        return $rows;
    }

    private static function decode(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $text = mb_convert_encoding($content, 'UTF-8', 'CP932');

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'このファイルを読み取れません（文字コードは UTF-8 か Shift_JIS にしてください）。']);
        }

        return $text;
    }

    /**
     * @return list<list<string>>
     */
    private static function records(string $text): array
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fwrite($handle, $text);
        rewind($handle);
        $records = [];

        try {
            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                // 前後の空白（全角も）を除く。trim() の文字の並びはバイトで比べるので、全角の空白を渡すと「お」などの頭のバイトまで削る
                $records[] = array_map(static fn (?string $cell): string => (string) preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', (string) $cell), $cells);

                if (count($records) > self::MAX_ROWS + 1) {
                    throw ValidationException::withMessages(['file' => 'CSV の行が多すぎます（'.self::MAX_ROWS.' 行まで）。分けて取り込んでください。']);
                }
            }
        } finally {
            fclose($handle);
        }

        return $records;
    }

    /**
     * 注文番号と送り状番号の列の位置と、読み飛ばす行の数（項目名の行）
     *
     * @param  list<string>  $first
     * @return array{0: int, 1: int, 2: int}
     */
    private static function columns(array $first): array
    {
        $names = array_map(self::name(...), $first);
        $reference = self::find($names, self::REFERENCE_HEADERS);
        $tracking = self::find($names, self::TRACKING_HEADERS);

        return $reference !== null && $tracking !== null ? [$reference, $tracking, 1] : [0, 1, 0];
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $candidates
     */
    private static function find(array $names, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $at = array_search(self::name($candidate), $names, true);

            if ($at !== false) {
                return $at;
            }
        }

        return null;
    }

    private static function name(string $cell): string
    {
        return mb_strtolower((string) preg_replace('/[\s\x{3000}.．]/u', '', mb_convert_kana($cell, 'as')));
    }

    /**
     * @param  list<string>  $cells
     */
    private static function cell(array $cells, int $at): ?string
    {
        $value = $cells[$at] ?? '';

        return $value === '' ? null : mb_substr($value, 0, 200);
    }
}
