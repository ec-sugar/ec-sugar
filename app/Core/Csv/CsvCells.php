<?php

declare(strict_types=1);

namespace App\Core\Csv;

/**
 * CSV の欄の差し込み（CSV injection）よけ。
 *
 * 表計算ソフトは「=」「+」「-」「@」などで始まる欄を式として動かす（外のサイトへ値を送る式も書ける）。
 * 書き出すときは、そうした文字で始まる文字の欄の頭に「'」を付けて、ただの文字として開かせる。
 * 取り込むときは、書き出しで付けた「'」（その後が式の頭の文字のとき）だけを外して、元の値に戻す。
 * 全角の「＝＋－＠」も、表計算ソフトが半角に直して式にすることがあるので同じに扱う。
 */
final class CsvCells
{
    private const string FORMULA_HEAD = '/\A[=+\-@\t\r＝＋－＠]/u';

    public static function neutralize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return preg_match(self::FORMULA_HEAD, $value) === 1 ? "'".$value : $value;
    }

    public static function restore(string $value): string
    {
        return str_starts_with($value, "'") && preg_match(self::FORMULA_HEAD, substr($value, 1)) === 1 ? substr($value, 1) : $value;
    }

    /**
     * 誤りの文に写す短い形（長い値や改行を、知らせの文に丸ごと入れない）
     */
    public static function preview(string $value): string
    {
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);

        return mb_strlen($value) > 30 ? mb_substr($value, 0, 30).'…' : $value;
    }
}
