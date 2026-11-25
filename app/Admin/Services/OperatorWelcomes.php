<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Models\Operator;
use App\Admin\Models\OperatorWelcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * 管理画面のホームの「ようこそ」を、運営ごと・店ごとに最初の 1 回だけ出す。
 * ブラウザの保存領域は使わない（ADR 0016）ので、閉じた印はサーバーに置く。店に入れるかは呼ぶ側（EnterTenant）が確かめる
 */
final class OperatorWelcomes
{
    public function seen(Operator $operator, string $tenantId): bool
    {
        return OperatorWelcome::query()->where('operator_id', $operator->id)->where('tenant_id', $tenantId)->exists();
    }

    /**
     * 閉じた印を付ける。何度呼んでも 1 行（同時に 2 回でも一意の制約で 1 行。最初の時刻を残す）
     */
    public function markSeen(Operator $operator, string $tenantId): void
    {
        OperatorWelcome::query()->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'operator_id' => $operator->id,
            'tenant_id' => $tenantId,
            'seen_at' => CarbonImmutable::now(),
        ]);
    }
}
