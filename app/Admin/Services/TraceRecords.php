<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Incident;
use App\Core\Event\Models\DomainEvent;
use App\Core\Payment\Models\CheckoutPlan;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\WebhookEvent;
use App\Core\Trace\TraceId;
use App\Models\Tenant;
use App\Storefront\Orders\SentMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 処理の ID（trace ID。ADR 0087）で、1 つの店の関係する記録を並べる。オーナーだけ（経路の RequireOwner）、読むだけ。
 *
 * - 店の文脈（EnterTenant）の中で、その店の DB の記録（イベント・送ったメール・支払いの画面と返金の頼み・決済代行の通知）を読む
 * - 中央の記録（監査ログ・壊れたことの記録・失敗した裏の仕事）は、入った店の ID の行だけを読む（ほかの店の記録は、同じ ID でも出さない）。
 *   店の中の判断には使わない（並べて見せるだけ）
 * - 返すのは種類・ID・時刻・状態だけ。イベントの中身・監査の中身と接続元・メールの件名と宛先・例外の文・仕事の中身は返さない
 */
final class TraceRecords
{
    /** 1 つの種類に並べる数 */
    public const int LIMIT = 100;

    /**
     * @return array<string, mixed>
     */
    public function present(string $traceId): array
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('処理の ID の記録は、店の文脈（EnterTenant）の中で読む');
        }

        if (! TraceId::valid($traceId)) {
            throw new LogicException('処理の ID は ULID の形で渡す（経路の whereUlid）');
        }

        $traceId = strtolower($traceId);
        $tenantId = (string) $tenant->getTenantKey();

        return [
            'trace_id' => $traceId,
            'events' => DomainEvent::query()->where('trace_id', $traceId)->orderBy('sequence')->limit(self::LIMIT)
                ->get(['sequence', 'type', 'aggregate_type', 'aggregate_id', 'actor', 'occurred_at'])
                ->map(static fn (DomainEvent $e): array => [
                    'sequence' => $e->sequence,
                    'type' => $e->type,
                    'aggregate_type' => $e->aggregate_type,
                    'aggregate_id' => $e->aggregate_id,
                    'actor' => $e->actor,
                    'at' => $e->occurred_at->toIso8601String(),
                ])->all(),
            'mails' => SentMail::query()->where('trace_id', $traceId)->orderBy('created_at')->limit(self::LIMIT)
                ->get(['id', 'transaction_id', 'kind', 'status', 'created_at', 'sent_at'])
                ->map(static fn (SentMail $m): array => [
                    'id' => $m->id,
                    'transaction_id' => $m->transaction_id,
                    'kind' => $m->kind,
                    'status' => $m->status,
                    'at' => ($m->sent_at ?? $m->created_at)->toIso8601String(),
                ])->all(),
            'checkouts' => CheckoutPlan::query()->where('trace_id', $traceId)->orderBy('created_at')->limit(self::LIMIT)
                ->get(['id', 'transaction_id', 'attempt', 'idempotency_key', 'created_at'])
                ->map(static fn (CheckoutPlan $p): array => [
                    'id' => $p->id,
                    'transaction_id' => $p->transaction_id,
                    'attempt' => $p->attempt,
                    'idempotency_key' => $p->idempotency_key,
                    'at' => $p->getAttribute('created_at')?->toIso8601String(),
                ])->all(),
            'refunds' => Refund::query()->where('trace_id', $traceId)->orderBy('created_at')->limit(self::LIMIT)
                ->get(['id', 'transaction_id', 'purpose', 'status', 'idempotency_key', 'created_at'])
                ->map(static fn (Refund $r): array => [
                    'id' => $r->id,
                    'transaction_id' => $r->transaction_id,
                    'purpose' => $r->purpose,
                    'status' => $r->status,
                    'idempotency_key' => $r->idempotency_key,
                    'at' => $r->created_at->toIso8601String(),
                ])->all(),
            'webhooks' => WebhookEvent::query()->where('trace_id', $traceId)->orderBy('created_at')->limit(self::LIMIT)
                ->get(['provider', 'event_id', 'type', 'outcome', 'created_at'])
                ->map(static fn (WebhookEvent $w): array => [
                    'provider' => $w->provider,
                    'event_id' => $w->event_id,
                    'type' => $w->type,
                    'outcome' => $w->outcome,
                    'at' => $w->getAttribute('created_at')?->toIso8601String(),
                ])->all(),
            'audit' => AuditEvent::query()->where('trace_id', $traceId)->where('tenant_id', $tenantId)->orderBy('sequence')->limit(self::LIMIT)
                ->get(['sequence', 'type', 'operator_id', 'occurred_at'])
                ->map(static fn (AuditEvent $a): array => [
                    'sequence' => $a->sequence,
                    'type' => $a->type,
                    'operator_id' => $a->operator_id,
                    'at' => $a->occurred_at->toIso8601String(),
                ])->all(),
            'incidents' => Incident::query()->where('last_trace_id', $traceId)->where('tenant_id', $tenantId)->orderByDesc('last_at')->limit(self::LIMIT)
                ->get(['kind', 'exception', 'location', 'count', 'last_at'])
                ->map(static fn (Incident $i): array => [
                    'kind' => $i->kind,
                    'exception' => $i->exception,
                    'location' => $i->location,
                    'count' => $i->count,
                    'at' => $i->last_at->toIso8601String(),
                ])->all(),
            'failed_jobs' => $this->failedJobs($traceId, $tenantId),
        ];
    }

    /**
     * 失敗した裏の仕事（中央の failed_jobs）のうち、この店でこの ID のもの。仕事の中身の、店の ID（stancl/tenancy が載せる）と
     * Context の ID（Laravel が載せる。値は PHP の serialize の形）で絞る。返すのは仕事の名前・キュー・時刻だけ（中身と例外の文は返さない）
     *
     * @return list<array{uuid: string, job: string, queue: string, at: string}>
     */
    private function failedJobs(string $traceId, string $tenantId): array
    {
        $connection = DB::connection((string) config('tenancy.database.central_connection'));

        return $connection->table((string) config('queue.failed.table', 'failed_jobs'))
            // 先に文字の一致で絞る（ID は ULID の文字だけなので LIKE の特別な文字を含まない）
            ->where('payload', 'like', '%'.$traceId.'%')
            ->whereRaw("payload::jsonb ->> 'tenant_id' = ?", [$tenantId])
            ->whereRaw("payload::jsonb -> 'illuminate:log:context' -> 'data' ->> ? = ?", [TraceId::KEY, serialize($traceId)])
            ->orderByDesc('failed_at')->limit(self::LIMIT)
            ->get(['uuid', 'queue', 'payload', 'failed_at'])
            ->map(static function (object $row): array {
                $payload = json_decode((string) $row->payload, true);
                $job = is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'unknown';

                return [
                    'uuid' => (string) $row->uuid,
                    'job' => mb_substr($job, 0, 200),
                    'queue' => (string) $row->queue,
                    'at' => CarbonImmutable::parse((string) $row->failed_at)->toIso8601String(),
                ];
            })->values()->all();
    }
}
