<?php

declare(strict_types=1);

namespace App\Admin\Services;

use Carbon\CarbonImmutable;
use JsonException;
use Throwable;

/**
 * 外への通信の出口の代理の数（ADR 0102）。代理（docker/egress/proxy.php）が 10 秒ごとに書く状態のファイル（config の egress.state。
 * メモリの上の置き場を読み取り専用で差し込む）を読み、運営の様子（OperationsOverview）の機械の調子と困りごとにする。持っているのは数・形・時刻だけ
 * （宛先の名前は代理の記録にだけあり、ここには来ない。サーバーで sudo ec-sugar egress）。
 *
 * 決まり:
 * - 代理を使う形（egress.proxy がある）で、状態のファイルが 5 分より古い・読めない: 要対応（代理が止まると外への https が全部止まる。支払い・LINE・Slack）
 * - 直近 1 時間に、代理を通った先へつながらない（failed）のに 1 本も通っていない: 要対応（行き先の名前を引けない・代理から外へ出られない。
 *   支払いが止まっているかもしれない）
 * - 止める形（enforce）で、この 24 時間に断った: 要対応（許す一覧が足りずに支払いやメールが止まっているかもしれない。要るものなら一覧に足す）
 * - 見張りの形（watch）で、この 24 時間に許す一覧に無い宛先へ出た・断った（内向きの住所など）: 近いうちに（止める形へ切り替える前に一覧を見直す）
 * - 代理を使わない形（off）: 困りごとにしない（機械の調子の札に「使っていない」と出す）
 * - 開発の形（代理も状態のファイルも無い）: 札を出さない
 */
final class EgressWatch
{
    /** 状態のファイルがこれより古ければ、代理が止まっているとみなす（分。代理は 10 秒ごとに書く） */
    public const int STALE_MINUTES = 5;

    /** 状態のファイルの大きさの上限（代理が書くのは 300 バイトほど） */
    private const int MAX_BYTES = 4096;

    private const array KINDS = ['allowed', 'unlisted', 'refused', 'failed', 'smtp'];

    /**
     * @return array{health: array<string, array<string, mixed>>, issues: list<array{key: string, level: string, count: int, tenant: null}>}
     */
    public static function present(CarbonImmutable $now): array
    {
        $proxy = config('egress.proxy');
        $used = is_string($proxy) && $proxy !== '';
        $state = self::read((string) config('egress.state'));

        if (! $used) {
            // 代理を使わない形（off）は、代理だけが動いていて状態のファイルがある。開発の形は両方無い
            return ['health' => $state === null ? [] : ['egress' => ['status' => 'warn', 'mode' => 'off', 'updated_at' => null, 'last24h' => null]], 'issues' => []];
        }

        if ($state === null || $state['updated_at']->lessThan($now->subMinutes(self::STALE_MINUTES))) {
            return [
                'health' => ['egress' => [
                    'status' => 'bad',
                    'mode' => $state === null ? null : $state['mode'],
                    'updated_at' => $state === null ? null : $state['updated_at']->toIso8601String(),
                    'last24h' => $state === null ? null : $state['last24h'],
                ]],
                'issues' => [['key' => 'egress_proxy_down', 'level' => 'urgent', 'count' => 1, 'tenant' => null]],
            ];
        }

        $counts = $state['last24h'];
        $hour = $state['last1h'];
        $issues = [];

        if ($hour['failed'] > 0 && $hour['allowed'] + $hour['unlisted'] === 0) {
            $issues[] = ['key' => 'egress_failing', 'level' => 'urgent', 'count' => $hour['failed'], 'tenant' => null];
        }

        if ($state['mode'] === 'enforce') {
            if ($counts['refused'] > 0) {
                $issues[] = ['key' => 'egress_refused', 'level' => 'urgent', 'count' => $counts['refused'], 'tenant' => null];
            }
        } else {
            if ($counts['unlisted'] > 0) {
                $issues[] = ['key' => 'egress_unlisted', 'level' => 'soon', 'count' => $counts['unlisted'], 'tenant' => null];
            }

            if ($counts['refused'] > 0) {
                $issues[] = ['key' => 'egress_refused', 'level' => 'soon', 'count' => $counts['refused'], 'tenant' => null];
            }
        }

        return [
            'health' => ['egress' => [
                'status' => $issues === [] ? 'ok' : 'warn',
                'mode' => $state['mode'],
                'updated_at' => $state['updated_at']->toIso8601String(),
                'last24h' => $counts,
            ]],
            'issues' => $issues,
        ];
    }

    /**
     * 状態のファイルを読む。無い・大きすぎる・形が違えば null（代理が書く値だけを、決めた形で受ける）
     *
     * @return array{mode: string, updated_at: CarbonImmutable, last24h: array<string, int>, last1h: array<string, int>}|null
     */
    private static function read(string $path): ?array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_BYTES) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ! in_array($data['mode'] ?? null, ['watch', 'enforce'], true) || ! is_string($data['updated_at'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $data['updated_at']) !== 1 || ! is_array($data['last24h'] ?? null)) {
            return null;
        }

        $counts = self::counts($data['last24h']);
        // 直近 1 時間は無くてもよい（無ければ 0）
        $hour = is_array($data['last1h'] ?? null) ? self::counts($data['last1h']) : array_fill_keys(self::KINDS, 0);

        if ($counts === null || $hour === null) {
            return null;
        }

        try {
            $updated = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $data['updated_at'], 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $updated instanceof CarbonImmutable ? ['mode' => $data['mode'], 'updated_at' => $updated, 'last24h' => $counts, 'last1h' => $hour] : null;
    }

    /**
     * 種類ごとの数（0 以上の整数だけ。形が違えば null）
     *
     * @param  array<mixed>  $raw
     * @return array<string, int>|null
     */
    private static function counts(array $raw): ?array
    {
        $counts = [];

        foreach (self::KINDS as $kind) {
            $value = $raw[$kind] ?? null;

            if (! is_int($value) || $value < 0) {
                return null;
            }

            $counts[$kind] = $value;
        }

        return $counts;
    }
}
