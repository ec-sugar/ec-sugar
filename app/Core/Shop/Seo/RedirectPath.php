<?php

declare(strict_types=1);

namespace App\Core\Shop\Seo;

use Illuminate\Validation\ValidationException;

/**
 * 転送（ADR 0061 の 10）の元と行き先の形。
 *
 * - 元（from）: このショップの古い URL の道筋（/ で始まる）と、あれば ? の後ろ。前のショップの URL をそのまま貼れるよう、
 *   http(s):// で始まる URL はホストを捨てて道筋だけを使う（前のドメインをこのショップへ向けた店のため）。% の書き方は戻し（日本語のまま持つ）、
 *   末尾の / は外す（/item/1/ と /item/1 を同じに）。比べるときは、届いた要求も同じ形にそろえる（key）
 * - 行き先（to）: **同じショップの中の道筋だけ**。/ で始まり、// と /\ で始まらない、見える ASCII だけ（日本語などは % の形に直す）。
 *   スキームとホストを持てないので、外のサイトへ飛ばせない（開いたリダイレクトを作らない）
 */
final class RedirectPath
{
    public const int MAX = 500;

    /**
     * 店の人が入れた元を、持つ形にする。形が違えば ValidationException（欄の名前 from）
     */
    public static function from(mixed $input): string
    {
        $value = is_string($input) ? trim($input) : '';

        if (preg_match('#\Ahttps?://#i', $value) === 1) {
            $parts = parse_url($value);
            $value = is_array($parts) ? ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '') : '';
        }

        if (! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            throw ValidationException::withMessages(['from' => '古い URL は / から始まるパスで入力してください（例: /item/123）。']);
        }

        $key = self::key($value);

        if ($key === null) {
            throw ValidationException::withMessages(['from' => '古い URL に使えない文字（空白・改行・\ など）が入っています。']);
        }

        if ($key === '/') {
            throw ValidationException::withMessages(['from' => 'トップのページは転送できません。']);
        }

        if (mb_strlen($key) > self::MAX) {
            throw ValidationException::withMessages(['from' => '古い URL は '.self::MAX.' 文字までです。']);
        }

        return $key;
    }

    /**
     * 店の人が入れた行き先を、持つ形にする。同じショップの中の道筋だけ（欄の名前 to）
     */
    public static function to(mixed $input): string
    {
        $value = is_string($input) ? trim($input) : '';

        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/\p{Cc}/u', $value) === 1) {
            throw ValidationException::withMessages(['to' => '新しい URL に使えない文字（改行・タブなど）が入っています。']);
        }

        if (! str_starts_with($value, '/') || preg_match('#\A/[/\\\\]#', $value) === 1 || str_contains($value, '\\')) {
            throw ValidationException::withMessages(['to' => '新しい URL は、このショップ内のパスを / から入力してください（例: /products/…）。ほかのサイトへは転送できません。']);
        }

        // 日本語や空白は % の形に（見える ASCII だけにそろえる）
        $value = (string) preg_replace_callback('/[^\x21-\x7E]/u', static fn (array $m): string => rawurlencode($m[0]), $value);

        if (preg_match('/\A\/[\x21-\x7E]*\z/', $value) !== 1 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw ValidationException::withMessages(['to' => '新しい URL に使えない文字が入っています。']);
        }

        if (strlen($value) > self::MAX) {
            throw ValidationException::withMessages(['to' => '新しい URL は '.self::MAX.' 文字までです。']);
        }

        return $value;
    }

    /**
     * 比べる形（元の持ち方と同じ）: # の後ろを捨て、道筋と ? の後ろの % の書き方を戻し、末尾の / を外す。
     * 使えない文字（空白・制御文字・\・壊れた文字）があれば null
     */
    public static function key(string $pathAndQuery): ?string
    {
        $value = explode('#', $pathAndQuery, 2)[0];
        [$path, $query] = array_pad(explode('?', $value, 2), 2, null);
        $path = rawurldecode($path);
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $path = $path === '' ? '/' : $path;
        $query = $query === null || $query === '' ? null : rawurldecode($query);
        $key = $query === null ? $path : "{$path}?{$query}";

        if (! mb_check_encoding($key, 'UTF-8') || preg_match('/[\x00-\x20\x7F\\\\]|\p{Cc}/u', $key) === 1 || str_starts_with($key, '//')) {
            return null;
        }

        return $key;
    }
}
