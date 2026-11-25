<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * App が書き出す CSV の文字コード（運送会社の取り込みの仕様に合わせて App が選ぶ）。変換は核（AdminFiles）がする。
 *
 * - utf-8: UTF-8（BOM なし）
 * - utf-8-bom: UTF-8 の頭に BOM（表計算のソフトで開いても文字が化けない）
 * - shift_jis: Windows の Shift_JIS（CP932。運送会社の取り込みの多くがこれ）。表せない文字は「〓」に替える
 */
enum CsvEncoding: string
{
    case Utf8 = 'utf-8';
    case Utf8Bom = 'utf-8-bom';
    case ShiftJis = 'shift_jis';

    /** 応答の Content-Type に付ける文字コードの名前 */
    public function charset(): string
    {
        return $this === self::ShiftJis ? 'Shift_JIS' : 'UTF-8';
    }
}
