<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\ShopDigests;
use App\Core\Event\Outbox\EventRelay;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Throwable;

/**
 * 流し損ねた知らせ（ADR 0089）。運営の様子から、店ごとに「止めた知らせ」を待ちに戻して流し直す。
 * オーナーだけ・直前のパスキーの再確認の後（経路の印）。入った店の記録だけを流す（EnterTenant の下）。
 * 誰がどの店で流し直したかを中央の監査ログに残し（中身は数だけ）、運営の様子の数をその場で数え直す
 */
final class EventRetryController
{
    public function __construct(private readonly EventRelay $relay) {}

    public function store(Request $request, AuditLog $audit, ShopDigests $digests): JsonResponse
    {
        $operator = $request->user('operator');

        if (! $operator instanceof Operator) {
            throw new LogicException('ログインした運営の経路です');
        }

        $resumed = $this->relay->resume();
        // ほかの処理が流していれば、その回が拾う（null）
        $result = $this->relay->run(EventRelay::SWEEP_LIMIT);
        $counts = $this->relay->counts();
        $audit->record('tenant.events_retried', $operator, ['resumed' => $resumed, 'delivered' => $result['delivered'] ?? 0, 'stopped' => $counts['stopped']],
            (string) Tenant::current()->getTenantKey(), $request->ip());

        // 運営の様子の数を今の数に（落ちても流し直しの答えは変えない。10 分ごとの回が直す）
        try {
            $digests->collect();
        } catch (Throwable $error) {
            report($error);
        }

        return response()->json(['resumed' => $resumed, 'delivered' => $result['delivered'] ?? 0, 'busy' => $result === null] + $counts);
    }
}
