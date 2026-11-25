<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Core\Event\ChainCodec;
use App\Core\Event\ChainVerification;
use App\Core\Trace\TraceId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * 中央の監査ログ（ADR 0013）。テナントのドメインイベント（EventStore）と同じく、追記専用のハッシュ連鎖。
 *
 * - 鍵は APP_KEY から HKDF で派生する（テナント鍵は使えない。中央の記録なので）
 * - テナント文脈の中から呼んでも中央 DB に書く（AuditEvent は CentralConnection）
 * - 失敗した操作（ログインの失敗など）も残すため、呼び出し側のトランザクションとは切り離して書くことがある
 *   （その場合は record を呼び出し側の DB::transaction の外で呼ぶ）
 * - 行には書いた処理の ID（trace_id。ADR 0087）を添える。ハッシュの本文には入れない（EventStore と同じ）
 */
final class AuditLog
{
    private const int APPEND_LOCK = 0x5E_C0_A0;

    // 鍵を派生する文脈の名前は旧名 SUGAR-EC のまま。変えると今までの記録の封印が確かめられなくなる（で変えないと決めた）
    private const string SEAL_CONTEXT = 'sugar-ec.central-audit.v1';

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(string $type, ?Operator $operator = null, array $data = [], ?string $tenantId = null, ?string $ip = null): AuditEvent
    {
        if (preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\z/', $type) !== 1 || strlen($type) > 64) {
            throw new InvalidArgumentException("監査ログの種類名が不正です: {$type}");
        }

        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = null;
        }

        ChainCodec::assertPlain($data);

        $connection = DB::connection(config('tenancy.database.central_connection'));

        return $connection->transaction(function () use ($connection, $type, $operator, $data, $tenantId, $ip): AuditEvent {
            $connection->select('SELECT pg_advisory_xact_lock(?)', [self::APPEND_LOCK]);

            $last = AuditEvent::query()->orderByDesc('sequence')->first(['sequence', 'hash']);

            $event = new AuditEvent([
                'sequence' => ($last->sequence ?? 0) + 1,
                'type' => $type,
                'operator_id' => $operator?->id,
                'tenant_id' => $tenantId,
                'ip' => $ip,
                'data' => $data,
                'occurred_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                'prev_hash' => $last->hash ?? str_repeat('0', 64),
                'trace_id' => TraceId::current(),
            ]);
            $event->hash = $this->hashOf($event);
            $event->save();

            return $event;
        });
    }

    public function verify(): ChainVerification
    {
        $expectedPrev = str_repeat('0', 64);
        $expectedSequence = 1;
        $checked = 0;

        foreach (AuditEvent::query()->lazyById(500, 'sequence') as $event) {
            if ($event->sequence !== $expectedSequence
                || ! hash_equals($expectedPrev, $event->prev_hash)
                || ! hash_equals($this->hashOf($event), $event->hash)) {
                return new ChainVerification($checked, $event->sequence);
            }

            $expectedPrev = $event->hash;
            $expectedSequence++;
            $checked++;
        }

        return new ChainVerification($checked, null);
    }

    private function hashOf(AuditEvent $event): string
    {
        $body = ChainCodec::encode([
            'v' => 1,
            'sequence' => $event->sequence,
            'type' => $event->type,
            'operator_id' => $event->operator_id,
            'tenant_id' => $event->tenant_id,
            'ip' => $event->ip,
            'data' => $event->data,
            'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'prev_hash' => $event->prev_hash,
        ]);

        return hash_hmac('sha256', $body, hash_hkdf('sha256', self::appKey(), 32, self::SEAL_CONTEXT));
    }

    private static function appKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('APP_KEY が設定されていないため監査ログを封印できません');
        }

        return $key;
    }
}
