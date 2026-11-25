<?php

declare(strict_types=1);

namespace App\Core\RateLimit;

use Illuminate\Support\Facades\RateLimiter;

/**
 * 回数の上限を数える核の部品（ADR 0081）。受け口・サービス・App の回数の上限は、どれもここを通す。
 *
 * - **先に数えてから比べる**: 1 回の原子的な足し算（キャッシュの increment）の戻り値で上限と比べる。
 *   「上限に達しているか見てから（tooManyAttempts）数える（hit）」と、上限の手前で同時に来た要求がどれも「まだ」と見て通り、上限を越える
 *   （でお問い合わせとメルマガがこれで越えられた。手本はレビューの書き込み）。足し算の戻り値は要求ごとに違う数なので、
 *   通るのは窓の中で上限の数までになる
 * - **断った回も数える**: 断った要求も 1 回に数える（窓は最初の 1 回から数え、延びない）
 * - **いくつかの上限は狭いものから**: firstOver は渡した順に数え、越えた所で止める（後ろの広い上限は数えない）。
 *   接続元 × メール → 接続元 → メール → 店、の順に渡す。1 つの接続元が断られ続けても、メールごと・店ごとの上限を使い切らせない
 * - **店ごとに分ける**: 店の中で数える上限のキーは shopKey で作る（店の ID が必ず入る。キャッシュの置き場を店で分けていなくても混ざらない）
 *
 * - **足し算そのものも原子的に**: 数えるのは AtomicRateLimiter（Laravel の RateLimiter と差し替えてある）。Laravel の数え方は窓の始まりに
 *   同時に来ると数えを落とす（ほかの要求が足した数を 1 に置き直す）ので、Redis では Lua 1 つで、ほかの置き場では錠の中で数える
 *
 * ここの外で RateLimiter の数える操作（hit・increment・decrement・attempt・tooManyAttempts）を使うと、テストが落ちる（SharedGuardsTest）。
 * 経路ごとの回数の上限（throttle ミドルウェア）は Laravel が同じ形（足してから比べる）で、同じ AtomicRateLimiter で数えるので、そのまま使う
 */
final class Throttle
{
    /**
     * 1 つの上限。数えた後の数が上限以内なら真（通す）
     *
     * @param  int  $max  窓の中で通す回数
     * @param  int  $seconds  窓の長さ（最初の 1 回から）
     */
    public static function allow(string $key, int $max, int $seconds): bool
    {
        self::check($key, $max, $seconds);

        return self::hit($key, $seconds) <= $max;
    }

    /**
     * 1 つの上限を数え、数えた後の数を返す（上限で断る前に、決まった数に達したことを知らせる時など）
     */
    public static function hit(string $key, int $seconds): int
    {
        self::check($key, 1, $seconds);

        return RateLimiter::increment($key, $seconds);
    }

    /**
     * いくつかの上限を渡した順に数え、越えた最初のキーを返す（どれも越えなければ null で、通す）。
     * 越えた所で止め、後ろの上限は数えない。狭い上限（接続元 × メール・接続元）から先に渡す
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $limits  [キー, 上限, 窓の秒]
     */
    public static function firstOver(array $limits): ?string
    {
        foreach ($limits as [$key, $max, $seconds]) {
            if (! self::allow($key, $max, $seconds)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * 数えた 1 回を戻す（数えた後で、その要求を数えないと分かった時。例: 合い言葉が合った・混み合いで比べなかった）。
     * 戻すのは自分が数えた分だけにする（0 より下にはならない）
     */
    public static function refund(string $key, int $seconds): void
    {
        self::check($key, 1, $seconds);

        if (RateLimiter::attempts($key) > 0) {
            RateLimiter::decrement($key, $seconds);
        }
    }

    /**
     * 窓が明けて、また通るまでの秒
     */
    public static function availableIn(string $key): int
    {
        return RateLimiter::availableIn($key);
    }

    /**
     * 窓を空にする（合い言葉が合った後に、接続元の間違いの数を消す時など）
     */
    public static function clear(string $key): void
    {
        RateLimiter::clear($key);
    }

    /**
     * 店の中で数える上限のキー（名前|店の ID|部分…）。店の文脈の外では使えない（どの店のものか分からないキーを作らない）
     */
    public static function shopKey(string $name, string ...$parts): string
    {
        $shop = tenant()?->getTenantKey();

        if (! is_string($shop) || $shop === '') {
            throw new \LogicException('店の文脈の外で、店ごとの回数の上限のキーは作れません');
        }

        return implode('|', [$name, $shop, ...$parts]);
    }

    private static function check(string $key, int $max, int $seconds): void
    {
        if ($key === '' || $max < 1 || $seconds < 1) {
            throw new \InvalidArgumentException('回数の上限のキー・上限・窓の秒が正しくありません');
        }
    }
}
