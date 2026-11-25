<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * JSON の本文は、読み解く前に大きさを見る（システム全体の見直しで見つけた）。
 *
 * Laravel は要求を作る所（public/index.php の Request::capture() → createFromBase）で、Content-Type が JSON なら本文を丸ごと読み解く。
 * ミドルウェア・ログイン・回数の制限より前なので、nginx の上限いっぱい（管理画面の API は 12m、商品の CSV の口は 100m）の JSON を送るだけで、
 * 読み解いた配列でメモリを使い切らせて 500 にできた。ここは public/index.php で Request::capture() の前に呼び、
 * Content-Length で超えると分かれば本文を読まずに、見出しの無い送り方（chunked）は上限 + 1 バイトまでだけ読んで、413 で断る（Laravel を起こさない）。
 * JSON でない本文（フォーム・ファイル）は見ない（PHP が読み、nginx の上限と post_max_size・max_input_vars が効く）。
 * 見分けは Laravel の Request::isJson（Content-Type に /json か +json）より広く、大文字と小文字を区別しない
 */
final class JsonBodyLimit
{
    /**
     * JSON の本文の上限（バイト）。いちばん大きい正しい JSON は管理画面のテーマの中身（ThemeContentService::MAX_BYTES、256 KB）で、その 8 倍。
     * 写真・CSV はフォームの形（multipart）で送るので、この上限に掛からない。ショップの nginx の上限（1m）はこれより小さい
     */
    public const int MAX_BYTES = 2 * 1024 * 1024;

    public const string MESSAGE = '送信した内容が大きすぎます。';

    /**
     * 断るか。$read は本文を先頭から最大 n バイト読む（見出しで決まる時は呼ばない）
     *
     * @param  array<array-key, mixed>  $server
     * @param  Closure(int): string  $read
     */
    public static function refuses(array $server, Closure $read): bool
    {
        $type = strtolower(self::text($server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? ''));

        if (! str_contains($type, '/json') && ! str_contains($type, '+json')) {
            return false;
        }

        $declared = trim(self::text($server['CONTENT_LENGTH'] ?? $server['HTTP_CONTENT_LENGTH'] ?? ''));

        if ($declared !== '') {
            // 数でない・負の・桁の多すぎる見出しも、上限を超えたものとして断る
            return preg_match('/\A[0-9]{1,10}\z/', $declared) !== 1 || (int) $declared > self::MAX_BYTES;
        }

        return strlen($read(self::MAX_BYTES + 1)) > self::MAX_BYTES;
    }

    /**
     * public/index.php から: 断るなら 413 を返して終える（Laravel を起こさないので、防御ヘッダはここで付ける）
     */
    public static function guard(): void
    {
        if (! self::refuses($_SERVER, static fn (int $bytes): string => (string) file_get_contents('php://input', false, null, 0, $bytes))) {
            return;
        }

        if (! headers_sent()) {
            http_response_code(413);
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo self::MESSAGE;

        exit;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
