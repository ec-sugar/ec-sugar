<?php

declare(strict_types=1);

namespace App\Core\Event;

use InvalidArgumentException;

/**
 * ハッシュ連鎖の本文の正準化。テナントのドメインイベント（EventStore）と中央の監査ログ（AuditLog）で共有する。
 * 同じ内容なら DB を往復しても必ず同じバイト列になること（検証が通ること）を保証する。
 */
final class ChainCodec
{
    /**
     * @param  array<string, mixed>  $body
     */
    public static function encode(array $body): string
    {
        return json_encode(self::canonical($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 入れてよいのは整数・文字列・真偽値・null と、その配列だけ（浮動小数は jsonb の往復で表現が変わる）。
     *
     * @param  array<mixed>  $value
     */
    public static function assertPlain(array $value): void
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                self::assertPlain($item);
            } elseif (! is_int($item) && ! is_string($item) && ! is_bool($item) && $item !== null) {
                throw new InvalidArgumentException('連鎖に入れられるのは整数・文字列・真偽値・null とその配列だけです');
            }
        }
    }

    /**
     * jsonb はキーの順序を保たないので、連想配列はキー順に並べ直す（リストの順序は保つ）。
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }
}
