<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Auth;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 本人のログイン中の端末（ADR 0083）。一覧・1 つを終える・今の画面のほかを全部終える。
 * 守りの操作なので直前の再確認は求めない（盗んだセッションで本人を締め出しても、本人はパスキーで入り直してその端末を終えられる）
 */
final class OperatorSessionController
{
    public function __construct(private readonly OperatorSessions $sessions) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['sessions' => $this->sessions->active($this->operator(), $this->sessions->current($request))]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $operator = $this->operator();

        // 今の画面はログアウトで終える（ここで終えると、答えの前にセッションが消えたように見える）
        if ($sessionId === $this->sessions->current($request)) {
            return response()->json(['message' => 'この端末のログインは、「ログアウト」で終了してください。'], 422);
        }

        // ほかの運営の行・終えた行は見つからない扱い
        abort_unless($this->sessions->end($operator, $sessionId, 'ended_by_self'), 404);
        $this->sessions->record($operator, 'ended_by_self', 1, $request->ip());

        return response()->json(null, 204);
    }

    public function others(Request $request): JsonResponse
    {
        $operator = $this->operator();
        $count = $this->sessions->endAll($operator, 'others', $this->sessions->current($request));
        $this->sessions->record($operator, 'others', $count, $request->ip());

        return response()->json(['ended' => $count]);
    }

    private function operator(): Operator
    {
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);

        return $operator;
    }
}
