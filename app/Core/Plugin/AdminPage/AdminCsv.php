<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * App が書き出す CSV の中身（ADR 0051 の口の続き）。App は文字の表だけを渡し、ファイルにするのは核（AdminFiles）。
 * 核が受け持つのは、表計算のソフトで式として読まれる値（= + - @ で始まる値）を無害にすること・改行と制御文字を空白に替えること・
 * 引用符の付け方・行の終わり（CRLF）・文字コードの変換・大きさの上限。
 * 名前は英小文字・数字・_・- で、.csv で終わる（60 文字まで。日付などは App が入れる）
 */
final readonly class AdminCsv
{
    /**
     * @param  list<string>|null  $header  1 行目の項目名（運送会社の形に項目名の行が無ければ null）
     * @param  list<list<string>>  $rows  中身の行（1 行 = 1 件。値はどれも文字）
     */
    public function __construct(
        public string $filename,
        public CsvEncoding $encoding,
        public ?array $header,
        public array $rows,
    ) {}
}
