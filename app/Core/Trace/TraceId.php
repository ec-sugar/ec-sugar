<?php

declare(strict_types=1);

namespace App\Core\Trace;

use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * 処理を追う ID（trace ID。ADR 0087）。1 つの要求・1 つの定期の処理・1 つの CLI のコマンドごとに 1 つ決め、
 * そこから積んだ裏の仕事（キュー）・残したイベント・監査ログ・送ったメールの記録・壊れたことの記録へ持ち越す。
 *
 * - 置き場は Laravel の Context（キー trace_id）。ログの各行に自動で付き（ContextLogProcessor）、キューの仕事の中身にも自動で載って、
 *   処理する時に戻る（ContextServiceProvider。戻した後に無ければ、ここで新しく決める。CoreServiceProvider）
 * - 値はこちらで作る ULID（小文字の 26 文字）だけ。外から来る X-Request-Id などは信じない（AssignTraceId）。
 *   中身は時刻と乱数だけで、店・お客さん・注文の情報を含まない
 * - 記録の表には「参考の印」として写す。ハッシュ連鎖（ADR 0012）の本文には入れない（無い頃の記録の検証を変えない）
 */
final class TraceId
{
    /** Context のキー（ログの extra の名前にもなる） */
    public const string KEY = 'trace_id';

    /** 店の ID を置く Context のキー（ログの extra。店の文脈に入っている間だけ。CoreServiceProvider） */
    public const string TENANT_KEY = 'tenant_id';

    /**
     * 新しい ID を決めて、今の処理の ID にする（前の ID は捨てる）
     */
    public static function start(): string
    {
        $id = strtolower((string) Str::ulid());
        Context::add(self::KEY, $id);

        return $id;
    }

    /**
     * 今の処理の ID（無い・形の違う値なら null）
     */
    public static function current(): ?string
    {
        $id = Context::get(self::KEY);

        return is_string($id) && self::valid($id) ? strtolower($id) : null;
    }

    /**
     * 今の処理の ID。無ければ新しく決める（裏の仕事を戻した後・CLI のコマンドの始まり）
     */
    public static function ensure(): string
    {
        return self::current() ?? self::start();
    }

    /**
     * 決まった ID の下で動かし、終わったら前の ID に戻す（あとで積んだものを送り出す処理〔Outbox〕が、積んだ時の ID で動くための口）
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function within(string $id, Closure $work): mixed
    {
        $id = strtolower($id);

        if (! self::valid($id)) {
            $id = strtolower((string) Str::ulid());
        }

        $previous = Context::get(self::KEY);
        Context::add(self::KEY, $id);

        try {
            return $work();
        } finally {
            $previous === null ? Context::forget(self::KEY) : Context::add(self::KEY, $previous);
        }
    }

    /**
     * ULID の形か（Crockford の base32 の 26 文字。先頭は 0〜7。大文字・小文字は問わない）
     */
    public static function valid(string $id): bool
    {
        return preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/i', $id) === 1;
    }
}
