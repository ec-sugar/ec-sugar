<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventRelay;
use App\Core\Trace\TraceId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * ドメインイベントの書き込みと検証（ADR 0012）。
 *
 * - 状態の変更と同じ DB トランザクションで書く（呼び出し側のトランザクションに乗る）。片方だけ残らない
 * - 1 テナント DB の中で直列に積む（アドバイザリロック）。sequence は 1 からの連番
 * - hash = HMAC(テナント鍵から派生した鍵, 正準化した本文 + prev_hash)。鍵が無いと連鎖を作り直せない
 * - コミット後に DomainEventRecorded を発火し（同じプロセスの中だけ）、送り出し役（EventRelay）で名前のある受け手へ流す（拡張点「イベント」。ADR 0089）
 *
 * ロックの順序: 書き込む操作はすべて、最初に lock()（連鎖のロック）を取ってから取引・受渡・在庫の行をロックする。
 * 連鎖のロックはテナント内の書き込みを直列にする代わりに、行ロックとの取り合いによるデッドロックを起こさない。
 *
 * payload に入れてよいのは整数・文字列・真偽値・null と、その配列だけ（浮動小数は jsonb の往復で表現が変わる）。
 * 平文の個人情報は入れない。ID で参照する（ADR 0010）。
 *
 * 行には、書いた処理の ID（trace_id。ADR 0087）を添える。ハッシュの本文には入れない（無い頃の記録の検証を変えない）。
 * 後から書き換えられないことは、追記だけのトリガーが守る（ID は記録を引くための参考の印で、改竄の検知の対象ではない）
 */
final class EventStore
{
    // pg_advisory_xact_lock のキー。テナントごとに DB が別なので、ロックもテナントごとに閉じる
    private const int APPEND_LOCK = 0x5E_C0_E7;

    public function __construct(private readonly TenantCipher $cipher) {}

    /**
     * 連鎖のロックを取る（トランザクションの終わりまで保持。同じトランザクションで何度呼んでもよい）。
     */
    public function lock(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('連鎖のロックは DB トランザクションの中で取ります');
        }

        DB::select('SELECT pg_advisory_xact_lock(?)', [self::APPEND_LOCK]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function append(string $type, string $aggregateType, string $aggregateId, array $payload = [], ?string $actor = null): DomainEvent
    {
        if (preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\z/', $type) !== 1 || strlen($type) > 64) {
            throw new InvalidArgumentException("イベント名が不正です: {$type}");
        }

        if (preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', $aggregateType) !== 1) {
            throw new InvalidArgumentException("集約の種類が不正です: {$aggregateType}");
        }

        if ($actor !== null && preg_match('/\A[a-z]+(:[0-9A-Za-z_-]{1,48})?\z/', $actor) !== 1) {
            throw new InvalidArgumentException('actor の形式が不正です');
        }

        ChainCodec::assertPlain($payload);

        return DB::transaction(function () use ($type, $aggregateType, $aggregateId, $payload, $actor): DomainEvent {
            $this->lock();

            $last = DomainEvent::query()->orderByDesc('sequence')->first(['sequence', 'hash']);

            $event = new DomainEvent([
                'sequence' => ($last->sequence ?? 0) + 1,
                'type' => $type,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'payload' => $payload,
                'actor' => $actor,
                'occurred_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                'prev_hash' => $last->hash ?? DomainEvent::GENESIS,
                'trace_id' => TraceId::current(),
            ]);
            $event->hash = $this->hashOf($event);
            $event->save();

            // コミットの後: 同じプロセスの中だけの知らせ（要求の文脈が要るもの向け。落ちたら消える）と、名前のある受け手へ流す送り出し役（ADR 0089。
            // 落ちても毎分の見回りが印から先を拾う）
            DB::afterCommit(static fn () => event(new DomainEventRecorded($event)));
            DB::afterCommit(static fn () => app(EventRelay::class)->kick());

            return $event;
        });
    }

    /**
     * 核の知らせを、状態の変更と同じトランザクションで記録に残す（ADR 0089）。コミットの後に送り出し役が受け手へ届け、
     * プロセスが落ちても見回りが届け直す（`event()` で直に出さない）
     */
    public function notice(Notice $notice, ?string $actor = null): DomainEvent
    {
        return $this->append($notice::type(), 'transaction', $notice->aggregateId(), $notice->payload(), $actor);
    }

    /**
     * 連鎖を先頭から検証する。最初に壊れていた sequence を返す（無傷なら null）。
     */
    public function verify(): ChainVerification
    {
        $expectedPrev = DomainEvent::GENESIS;
        $expectedSequence = 1;
        $checked = 0;
        $headHash = null;

        foreach (DomainEvent::query()->lazyById(500, 'sequence') as $event) {
            if ($event->sequence !== $expectedSequence
                || ! hash_equals($expectedPrev, $event->prev_hash)
                || ! hash_equals($this->hashOf($event), $event->hash)) {
                // 先頭は、確かめられた最後のイベントまで
                return new ChainVerification($checked, $event->sequence, $expectedSequence - 1, $headHash);
            }

            $expectedPrev = $event->hash;
            $headHash = $event->hash;
            $expectedSequence++;
            $checked++;
        }

        return new ChainVerification($checked, null, $expectedSequence - 1, $headHash);
    }

    /**
     * 今の連鎖の先頭（最後のイベントの番号と hash。1 つも無ければ 0 と null）。1 行読むだけで、連鎖は確かめない。
     * 全件の検証（verify）の間の回でも先頭を控えられるようにするため
     *
     * @return array{0: int, 1: string|null}
     */
    public function head(): array
    {
        $last = DomainEvent::query()->orderByDesc('sequence')->first(['sequence', 'hash']);

        return $last === null ? [0, null] : [(int) $last->sequence, (string) $last->hash];
    }

    /**
     * その番号のイベントの hash（無ければ null）。前に控えた連鎖の先頭が今も同じ所にあるかを確かめるのに使う
     */
    public function hashAt(int $sequence): ?string
    {
        $hash = DomainEvent::query()->where('sequence', $sequence)->value('hash');

        return is_string($hash) ? $hash : null;
    }

    private function hashOf(DomainEvent $event): string
    {
        $body = ChainCodec::encode([
            'v' => 1,
            'sequence' => $event->sequence,
            'type' => $event->type,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => $event->aggregate_id,
            'payload' => $event->payload,
            'actor' => $event->actor,
            'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'prev_hash' => $event->prev_hash,
        ]);

        return $this->cipher->seal($body);
    }
}
