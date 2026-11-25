<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Admin\Services\AuditLog;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\ClientSource;

/**
 * 接続元の制限の外の受け口（/mcp と OAuth の鍵の受け渡し。ADR 0101）で、断った回だけを接続元ごとに数える部品。
 *
 * - 数えるのは接続元（IPv4 は住所、IPv6 は /64）と、IPv6 の広い接続元（/48。/64 を替えながら逃れるのを止める）。狭い方から数える
 * - 通った鍵は数えない（claude.ai などのクラウドのクライアントは多くの人が同じ出口から来るので、ほかの人の失敗で正しい鍵まで止めない）
 * - 断った回の監査ログ（`operator.agent_denied`。接続元だけで、見せられた鍵は残さない）は接続元ごとに 1 分 1 行にまとめる
 *   （失敗のたびに監査ログの全体の錠を取らせない。PR 186 の見直しの高 1）
 */
final class AgentFailures
{
    /** 広い接続元（/48）の上限は、狭い接続元の何倍か */
    public const int WIDE_FACTOR = 5;

    /**
     * 断った 1 回を数える。接続元の上限を越えていれば真（断る答えを 429 にする）
     */
    public static function over(string $name, ?string $ip, int $perMinute): bool
    {
        $limits = [[$name.'|'.ClientSource::of($ip), $perMinute, 60]];
        $wide = ClientSource::wide($ip);

        if ($wide !== null) {
            $limits[] = [$name.'-wide|'.$wide, $perMinute * self::WIDE_FACTOR, 60];
        }

        return Throttle::firstOver($limits) !== null;
    }

    /**
     * 断った回を監査ログへ（接続元ごとに 1 分 1 行だけ）
     *
     * @param  array<string, string>  $data
     */
    public static function record(AuditLog $audit, string $name, ?string $ip, array $data = []): void
    {
        if (Throttle::hit($name.'-audit|'.ClientSource::of($ip), 60) === 1) {
            $audit->record('operator.agent_denied', null, $data, ip: $ip);
        }
    }
}
