<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Core\Event\Outbox\EventReceiverFailed;
use App\Core\Trace\TraceId;
use App\Core\UserFacingException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\ViewException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * 壊れたことの記録（ADR 0043）。中央の表 incidents に「どこで・何の種類が・何回」だけを書く。
 *
 * - 書く口は 3 つ: 例外の報告の口（HTTP の処理の中の 500。bootstrap/app.php）・裏の仕事の失敗（JobFailed）・定期の処理の失敗（ScheduledTaskFailed）
 * - 置くのは種類・例外のクラス・場所（アプリの中のいちばん深いフレームの相対パスと行）・店の ID・回数・時刻と、最後に起きた時の処理の ID（ADR 0087）だけ。
 *   例外のメッセージ・スタックの全体・リクエストの中身・URL・利用者は置かない（エラーの文にはお客さんの名前や SQL の値が混ざりうる。ADR 0010）
 * - いつも中央の接続で書く（店の文脈の中でも）。指紋の行に ON CONFLICT で回数を足す 1 文だけ
 * - 記録が失敗しても（中央の DB が止まっている・表が無い）元の処理とログの報告を邪魔しない。記録の中で起きた例外は記録しない
 * - 4xx（見つからない・断った・入力の誤り）と見せてよい文の例外（UserFacingException）は記録しない。壊れていないので
 */
final class Incidents
{
    public const string HTTP_ERROR = 'http_error';

    public const string JOB_FAILED = 'job_failed';

    public const string COMMAND_FAILED = 'command_failed';

    /** 記録の受け手（注文の知らせ・App）が落ちた・止めた（ADR 0089） */
    public const string EVENT_FAILED = 'event_failed';

    /** 中央の接続がトランザクションの途中の時に書く、別の接続の名前（中央と同じ設定） */
    private const string WRITER = 'central_incidents';

    /**
     * アプリのコードとみなす場所（基準のディレクトリからの相対パス）。vendor・storage・入口のファイル（artisan・public/index.php）は外す。
     * tests/ は本番のイメージには無く、テストの中で起こした例外の場所を見分けるため
     */
    private const array APP_DIRECTORIES = ['app/', 'plugins/', 'routes/', 'bootstrap/app.php', 'bootstrap/providers.php', 'config/', 'database/', 'resources/', 'tests/'];

    /** 記録の中にいる（記録の中で起きた例外を、もう一度記録しに来ない） */
    private static bool $recording = false;

    /**
     * 例外の報告の口から（bootstrap/app.php の withExceptions）。HTTP の処理の中で起きた、壊れたことだけを記録する。
     * 裏の仕事と定期の処理の中の報告は、それぞれ JobFailed・ScheduledTaskFailed で 1 回だけ数える（同じ失敗を 2 回数えない）
     */
    public static function reported(Throwable $e): void
    {
        try {
            if (! self::handlingHttpRequest() || ! self::isBreakage($e)) {
                return;
            }

            self::record(self::HTTP_ERROR, $e);
        } catch (Throwable) {
            // 報告の口を止めない
        }
    }

    /**
     * 裏の仕事の失敗（試し直しを使い切った後の 1 回だけ）。アプリの中に場所が無ければ（vendor の中だけで落ちた）、仕事のクラスの名前
     */
    public static function jobFailed(JobFailed $event): void
    {
        try {
            $name = $event->job->resolveName();
        } catch (Throwable) {
            $name = 'unknown';
        }

        self::record(self::JOB_FAILED, $event->exception, fallback: 'job '.self::clean($name));
    }

    /**
     * 定期の処理の失敗（例外か、0 でない終わりの番号）。コマンドは別のプロセスで動き、ここに来る例外は終わりの番号を知らせるだけのものなので、
     * 場所はコマンドの名前と引数（例 artisan operations:collect --verify）。同じプロセスで動く処理（関数・裏の仕事）は、例外の場所
     */
    public static function taskFailed(ScheduledTaskFailed $event): void
    {
        $command = (string) ($event->task->command ?? '');

        if ($command === '') {
            self::record(self::COMMAND_FAILED, $event->exception, fallback: 'schedule '.self::clean((string) ($event->task->description ?? 'callback')));

            return;
        }

        self::record(self::COMMAND_FAILED, $event->exception, location: self::taskName($command));
    }

    /**
     * 記録の受け手が落ちた（場所は例外の場所。アプリの中に無ければ受け手の名前）・何度も落ちたので止めた（場所は受け手の名前。受け手ごとに 1 行）
     */
    public static function receiverFailed(EventReceiverFailed $event): void
    {
        $name = 'receiver '.self::clean($event->receiver);
        self::record(self::EVENT_FAILED, $event->error, location: $event->stopped ? $name : null, fallback: $name);
    }

    /**
     * 1 回分を記録する。何が起きても投げない
     */
    public static function record(string $kind, Throwable $e, ?string $location = null, ?string $fallback = null): void
    {
        if (self::$recording) {
            return;
        }

        self::$recording = true;

        try {
            $e = self::unwrap($e);
            $location = mb_substr($location ?? self::locate($e) ?? $fallback ?? 'unknown', 0, 255);
            // 無名のクラスの名前は NUL 文字とファイルのパスを含む（DB が断る）ので、「…@anonymous」までにする
            $class = mb_substr((string) preg_replace('/@anonymous.*\z/s', '@anonymous', $e::class), 0, 255);
            $tenant = tenant();
            $tenantId = $tenant === null ? null : (string) $tenant->getTenantKey();
            $fingerprint = hash('sha256', implode("\n", [$kind, $class, $location, $tenantId ?? '']));
            $now = CarbonImmutable::now()->utc();

            DB::connection(self::connection())->statement(<<<'SQL'
                INSERT INTO incidents (fingerprint, kind, exception, location, tenant_id, count, first_at, last_at, last_trace_id)
                VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
                ON CONFLICT (fingerprint) DO UPDATE SET count = incidents.count + 1, last_at = GREATEST(incidents.last_at, EXCLUDED.last_at),
                    last_trace_id = COALESCE(EXCLUDED.last_trace_id, incidents.last_trace_id)
                SQL, [$fingerprint, $kind, $class, $location, $tenantId, $now, $now, TraceId::current()]);
        } catch (Throwable $failure) {
            // 記録できなくても、元の処理とログの報告は続ける。ここで report() すると、また記録しに来るので、ログに種類だけを書く
            try {
                Log::warning('壊れたことの記録（incidents）を書けませんでした', ['exception' => $failure::class]);
            } catch (Throwable) {
                // ログも書けなければ諦める
            }
        } finally {
            self::$recording = false;
        }
    }

    /**
     * アプリのコードの中でいちばん深いフレーム（基準のディレクトリからの相対パス:行）。vendor の中で起きたなら、そこを呼んだアプリのフレーム。
     * 描いた表示（storage の中の写し）は、元の Blade のファイル（行は写しと元で違うので付けない）
     */
    public static function locate(Throwable $e): ?string
    {
        $base = self::base();
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            $relative = self::relative($file, $base);

            if ($relative === null) {
                continue;
            }

            if (str_starts_with($relative, 'storage/framework/views/')) {
                $source = self::viewSource($file, $base);

                if ($source !== null) {
                    return $source;
                }

                continue;
            }

            if (self::inApp($relative)) {
                return $relative.':'.(int) ($frame['line'] ?? 0);
            }
        }

        return null;
    }

    /**
     * 壊れたことか。4xx・見せてよい文の例外は壊れていない
     */
    private static function isBreakage(Throwable $e): bool
    {
        if ($e instanceof UserFacingException) {
            return false;
        }

        return ! ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500);
    }

    /**
     * HTTP の処理の途中か（処理の始まりの時刻があり、まだ終わっていない）。裏の仕事の処理役と定期の処理は HTTP のカーネルを使わない
     */
    private static function handlingHttpRequest(): bool
    {
        $app = app();

        if (! $app->resolved(HttpKernelContract::class)) {
            return false;
        }

        return $app->make(HttpKernelContract::class)->requestStartedAt() !== null;
    }

    /**
     * 中央の接続の名前。中央の接続がトランザクションの途中なら（読むだけのトランザクション・失敗して止まったトランザクションもある）、
     * 同じ設定の別の接続で書く（巻き戻しで記録が消えない・相手のトランザクションを壊さない）
     */
    private static function connection(): string
    {
        $central = (string) config('tenancy.database.central_connection');

        if (DB::connection($central)->transactionLevel() === 0) {
            return $central;
        }

        config(['database.connections.'.self::WRITER => config("database.connections.{$central}")]);

        return self::WRITER;
    }

    /**
     * 表示の中で起きた例外は、表示の例外に包まれて届く。中の例外の種類と場所を使う
     */
    private static function unwrap(Throwable $e): Throwable
    {
        while ($e instanceof ViewException && $e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }

    private static function taskName(string $command): string
    {
        // 'php' 'artisan' operations:collect --verify → artisan operations:collect --verify
        if (preg_match("/(?:^|\\s)'?artisan'?\\s+(.+)\\z/", $command, $match) === 1) {
            return 'artisan '.self::clean($match[1]);
        }

        return 'schedule '.self::clean($command);
    }

    /**
     * 場所に入れてよい文字だけにする（クラスの名前・コマンドの名前と引数）
     */
    private static function clean(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[^A-Za-z0-9:_\-=.\\\\\/ ]+/', '', $value)), 0, 200);
    }

    private static function base(): string
    {
        $base = base_path();

        return rtrim(realpath($base) ?: $base, '/').'/';
    }

    private static function relative(string $file, string $base): ?string
    {
        $real = realpath($file) ?: $file;

        return str_starts_with($real, $base) ? substr($real, strlen($base)) : null;
    }

    private static function inApp(string $relative): bool
    {
        foreach (self::APP_DIRECTORIES as $directory) {
            if (str_starts_with($relative, $directory)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 描いた表示の写しの末尾にある元のファイルの印（BladeCompiler が付ける「PATH 元のパス ENDPATH」のコメント）から、元の Blade のファイル
     */
    private static function viewSource(string $file, string $base): ?string
    {
        $size = @filesize($file);

        if ($size === false) {
            return null;
        }

        $tail = @file_get_contents($file, false, null, max(0, $size - 1024));

        if (! is_string($tail) || preg_match('#/\*\*PATH (.+?) ENDPATH\*\*/#', $tail, $match) !== 1) {
            return null;
        }

        $relative = self::relative($match[1], $base);

        return $relative !== null && self::inApp($relative) ? $relative : null;
    }
}
