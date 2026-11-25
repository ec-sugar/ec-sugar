<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Crypto\BlindIndex;
use App\Core\Mail\Models\MailRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use WeakMap;

/**
 * 店が送ったメールの記録（ADR 0095）。送る直前に札を結び（attach）、送る口（RecordingMailer）が
 * 送っている → 送った・送れなかった を店の DB の mail_records に書く。
 *
 * - 記録するのは、店の文脈の中で、札（RecordsMail）の付いたメールだけ。運営へのメール（ログインの守り・壊れた知らせ）は店の記録に入れない
 * - デモの店のメールは送る直前で止まる（ADR 0086）ので、ここまで来ない（受け箱に置いたメールは「送った」にならない）
 * - 書くのは店の DB。送る所がトランザクションの中（注文の知らせは送った印の行のロックの中で送る）なら、同じ設定の別の接続で書く
 *   （送れずに巻き戻っても「送れなかった」が残る・相手のトランザクションを壊さない）。別の接続は店の文脈が終わる・変わるたびに捨てる（ほかの店へ持ち越さない）
 * - 送れなかった理由は決まった語と SMTP の応答の番号だけ。例外の文・SMTP の応答の文（宛先のアドレスが入ることがある）は残さない
 * - 同じキューの仕事のやり直し（同じ種類・同じ宛先）は同じ行を直し、試した回数を数える
 * - 記録が失敗しても送ることは止めない（例外を飲み、ログに例外の種類だけを書く）
 * - 状態でもお金と在庫でもなく、監査の源泉でもない「送ったか」の記録なので、連鎖のロックとイベントは使わない（conventions 3 の例外）
 */
final class MailRecords
{
    /** 札を置く、メールの中身の値の名前（RecordsMail が添える） */
    public const string DATA_KEY = '__sugar_mail_record';

    /** 送れなかった理由の語 */
    public const array REASONS = ['rejected', 'temporary', 'auth', 'connection', 'timeout', 'error'];

    /** 残す日数（古いものは毎晩消す。mail-records:prune） */
    public const int KEEP_DAYS = 90;

    /** 送っているのまま、この分を過ぎたら「送れたか分からない」とみなす（運営の様子の送りかけのメールと同じ 1 時間） */
    public const int STALE_MINUTES = 60;

    /** 店の接続がトランザクションの途中の時に書く、別の接続の名前（店の接続と同じ設定） */
    private const string WRITER = 'tenant_mail_records';

    /** stancl/tenancy が店の文脈で使う接続の名前 */
    private const string TENANT = 'tenant';

    /** 宛先として残す数の上限 */
    private const int ADDRESSES_MAX = 20;

    /** @var WeakMap<Email, array{tag: MailTag, tenant: string, id: string|null}>|null 送るメールごとの札と記録の行 */
    private static ?WeakMap $entries = null;

    /** @var list<string> 今処理しているキューの仕事の ID（入れ子の同期の仕事に備えて積む） */
    private static array $jobs = [];

    /** 別の接続を設定した店（違う店になったら捨てて作り直す） */
    private static ?string $writerFor = null;

    /**
     * 送る直前（MessageSending。デモの店の関所の後）。札があり、店の文脈の中なら、送るメールに結ぶ
     *
     * @param  array<string, mixed>  $data
     */
    public static function attach(Email $message, array $data): void
    {
        $tag = $data[self::DATA_KEY] ?? null;
        $tenant = self::tenantKey();

        if (! $tag instanceof MailTag || $tenant === null) {
            return;
        }

        if (! $tag->valid()) {
            Log::warning('メールの記録の札の形が違うので、記録しません');

            return;
        }

        self::entries()[$message] = ['tag' => $tag, 'tenant' => $tenant, 'id' => null];
    }

    /**
     * 送る前。送っている、の行を作る（一斉の配信は作らない）。同じ仕事のやり直しなら、前に送れなかった行を直す
     */
    public static function sending(Email $message): void
    {
        $entry = self::entry($message);

        if ($entry === null || $entry['tag']->bulk) {
            return;
        }

        try {
            [$addresses, $index] = self::recipients($message);
            $connection = self::connection();
            $job = self::$jobs === [] ? null : self::$jobs[array_key_last(self::$jobs)];
            $row = $job === null ? null : MailRecord::on($connection)->where('job_id', $job)->where('kind', $entry['tag']->kind)
                ->where('recipient_index', $index)->where('status', 'failed')->orderByDesc('created_at')->first();

            if ($row instanceof MailRecord) {
                $row->forceFill(['status' => 'sending', 'reason' => null, 'smtp_code' => null, 'smtp_status' => null, 'attempts' => $row->attempts + 1])->save();
            } else {
                $row = (new MailRecord)->setConnection($connection);
                $row->forceFill(self::fresh($message, $entry['tag'], $addresses, $index, $job, 'sending'))->save();
            }

            self::entries()[$message] = [...$entry, 'id' => $row->id];
        } catch (Throwable $e) {
            self::warn($e);
        }
    }

    /**
     * 送れた
     */
    public static function sent(Email $message): void
    {
        $entry = self::entry($message);

        if ($entry === null || $entry['id'] === null) {
            return;
        }

        try {
            MailRecord::on(self::connection())->whereKey($entry['id'])
                ->update(['status' => 'sent', 'sent_at' => CarbonImmutable::now(), 'reason' => null, 'smtp_code' => null, 'smtp_status' => null]);
        } catch (Throwable $e) {
            self::warn($e);
        }
    }

    /**
     * 送れなかった（送る所の例外）。理由は決まった語と SMTP の番号だけを残す
     */
    public static function failed(Email $message, Throwable $error): void
    {
        $entry = self::entry($message);

        if ($entry === null) {
            return;
        }

        [$reason, $code, $status] = self::reason($error);
        $failure = ['status' => 'failed', 'reason' => $reason, 'smtp_code' => $code, 'smtp_status' => $status, 'sent_at' => null];

        try {
            $connection = self::connection();

            if ($entry['id'] !== null) {
                MailRecord::on($connection)->whereKey($entry['id'])->update($failure);

                return;
            }

            // 一斉の配信（と、送る前の行を作れなかったメール）は、ここで初めて行を作る
            [$addresses, $index] = self::recipients($message);
            $job = self::$jobs === [] ? null : self::$jobs[array_key_last(self::$jobs)];
            $row = (new MailRecord)->setConnection($connection);
            $row->forceFill([...self::fresh($message, $entry['tag'], $addresses, $index, $job, 'failed'), ...$failure])->save();
            self::entries()[$message] = [...$entry, 'id' => $row->id];
        } catch (Throwable $e) {
            self::warn($e);
        }
    }

    /**
     * 送れなかった理由の語・SMTP の応答の番号・拡張の状態の番号。例外の文は読むだけで残さない
     *
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    public static function reason(Throwable $error): array
    {
        $code = null;
        $text = '';

        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if (! $e instanceof TransportExceptionInterface) {
                continue;
            }

            $number = (int) $e->getCode();

            if ($code === null && $number >= 200 && $number <= 599) {
                $code = $number;
            }

            $text .= ' '.$e->getMessage();
        }

        if ($code === null && preg_match('/got code "([2-5][0-9]{2})"/', $text, $m) === 1) {
            $code = (int) $m[1];
        }

        $status = preg_match('/(?<![0-9.])([245]\.[0-9]{1,3}\.[0-9]{1,3})(?![0-9.])/', $text, $m) === 1 ? $m[1] : null;

        $reason = match (true) {
            $text === '' => 'error',
            in_array($code, [504, 530, 534, 535, 538], true) => 'auth',
            $code !== null && $code >= 500 => 'rejected',
            $code !== null && $code >= 400 => 'temporary',
            preg_match('/failed to authenticate|authenticator/i', $text) === 1 => 'auth',
            preg_match('/timed out/i', $text) === 1 => 'timeout',
            preg_match('/could not be established|unable to connect|closed unexpectedly|unable to (write|read)|starttls|tls required|process could not be started/i', $text) === 1 => 'connection',
            default => 'error',
        };

        return [$reason, $code, $status];
    }

    /**
     * 古い記録を消す（今の店。毎晩 mail-records:prune から）。消した数
     */
    public static function prune(?CarbonImmutable $now = null): int
    {
        $before = ($now ?? CarbonImmutable::now())->subDays(self::KEEP_DAYS)->utc();
        $total = 0;

        // 一度に消しすぎない（大きな店でも長く錠を持たない）
        do {
            $ids = MailRecord::query()->where('created_at', '<', $before)->orderBy('created_at')->limit(1000)->pluck('id');
            $total += $ids->isEmpty() ? 0 : MailRecord::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return $total;
    }

    /**
     * キューの仕事の始まりと終わり（CoreServiceProvider の JobProcessing・JobProcessed・JobExceptionOccurred）
     */
    public static function jobStarted(?string $uuid): void
    {
        self::$jobs[] = is_string($uuid) && preg_match('/\A[0-9a-f-]{36}\z/i', $uuid) === 1 ? strtolower($uuid) : '';
    }

    public static function jobEnded(): void
    {
        array_pop(self::$jobs);
    }

    /**
     * 店の文脈が始まる・終わる時に、別の接続を捨てる（ほかの店・作り直した店の DB へ持ち越さない）
     */
    public static function forgetWriter(): void
    {
        if (self::$writerFor !== null) {
            DB::purge(self::WRITER);
            config(['database.connections.'.self::WRITER => null]);
        }

        self::$writerFor = null;
    }

    /**
     * @return array{tag: MailTag, tenant: string, id: string|null}|null
     */
    private static function entry(Email $message): ?array
    {
        $entry = self::entries()[$message] ?? null;

        // 札を結んだ店と、今の店が違えば書かない（店の境界）
        if ($entry === null || $entry['tenant'] !== self::tenantKey()) {
            return null;
        }

        return $entry;
    }

    /**
     * @param  list<string>  $addresses
     * @return array<string, mixed>
     */
    private static function fresh(Email $message, MailTag $tag, array $addresses, ?string $index, ?string $job, string $status): array
    {
        return [
            'kind' => $tag->kind,
            'subject' => mb_substr(MailHeaderText::line((string) $message->getSubject()), 0, 200),
            'status' => $status,
            'attempts' => 1,
            'recipients' => ['addresses' => $addresses],
            'recipient_index' => $index,
            'recipient_count' => count($message->getTo()) + count($message->getCc()) + count($message->getBcc()),
            'transaction_id' => $tag->transactionId,
            'job_id' => $job === '' ? null : $job,
        ];
    }

    /**
     * 宛先（To・Cc・Bcc。上限まで）と、最初の宛先のブラインドインデックス（お客さんの台帳の email_index と同じ値）
     *
     * @return array{0: list<string>, 1: string|null}
     */
    private static function recipients(Email $message): array
    {
        $addresses = array_values(array_slice(array_map(
            static fn (Address $address): string => mb_substr($address->getAddress(), 0, 320),
            [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()],
        ), 0, self::ADDRESSES_MAX));

        return [$addresses, $addresses === [] ? null : BlindIndex::of('email', $addresses[0])];
    }

    /**
     * 書く接続。店の接続がトランザクションの途中なら、同じ設定の別の接続
     */
    private static function connection(): string
    {
        if (DB::connection(self::TENANT)->transactionLevel() === 0) {
            return self::TENANT;
        }

        $tenant = (string) self::tenantKey();

        if (self::$writerFor !== $tenant) {
            self::forgetWriter();
            config(['database.connections.'.self::WRITER => config('database.connections.'.self::TENANT)]);
            self::$writerFor = $tenant;
        }

        return self::WRITER;
    }

    private static function tenantKey(): ?string
    {
        $tenant = tenancy()->initialized ? tenant() : null;

        return $tenant === null ? null : (string) $tenant->getTenantKey();
    }

    /**
     * @return WeakMap<Email, array{tag: MailTag, tenant: string, id: string|null}>
     */
    private static function entries(): WeakMap
    {
        return self::$entries ??= new WeakMap;
    }

    private static function warn(Throwable $e): void
    {
        // 例外の文には宛先が入ることがあるので、種類だけ
        Log::warning('メールの記録を書けませんでした', ['exception' => $e::class]);
    }
}
