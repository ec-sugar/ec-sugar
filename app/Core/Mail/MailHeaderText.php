<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * メールのヘッダー（差出人の名前・件名）に入れる文を 1 行にする。
 * 改行やタブをそのまま渡すと、ヘッダーをもう 1 本書き足されてしまう（ヘッダーの注入）ので、空白 1 つに畳んで前後を落とす。
 *
 * 店の名前・商品名・配信の件名のように、店やお客さんが書いた文をヘッダーへ入れる所では必ず通す。
 * メールのクラス（Mailable）からは RecordsMail の line() で呼べる（同じ式が 28 か所に写っていたのを 1 つにした）
 */
final class MailHeaderText
{
    public static function line(string $text): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $text));
    }
}
