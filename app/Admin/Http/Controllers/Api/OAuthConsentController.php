<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Agent\AgentOAuthAuthorizations;
use App\Admin\Models\Operator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * OAuth の許可の画面の API（ADR 0101。SPA の /oauth/consent/<合い言葉>）。ログインした運営だけ・管理画面の接続元の制限の中。
 *
 * - GET /api/oauth-requests/{requestId}: 頼みの中身（名乗った名前・行き先のホスト・頼まれた種類・選べる店と段階）
 * - POST /api/oauth-requests/{requestId}/approve: 許可（直前の再確認の後）。{ tenants: [店の ID, …], scope } → { redirect_to }（登録した行き先に code・state・iss）
 * - POST /api/oauth-requests/{requestId}/deny: 拒否 → { redirect_to }（登録した行き先に error=access_denied・state）
 *
 * 頼みが無い・期限切れ・決めた後は 404（画面は「もう一度つなぎ直して」と出す）。行き先の URL はサーバーが登録したものから作り、画面はそこへ移るだけ
 */
final class OAuthConsentController extends Controller
{
    public function __construct(private readonly AgentOAuthAuthorizations $authorizations) {}

    public function show(string $requestId): JsonResponse
    {
        $described = $this->authorizations->describe($requestId, self::operator());
        abort_if($described === null, 404);

        return response()->json(['request' => $described])->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, string $requestId): JsonResponse
    {
        $redirect = $this->authorizations->approve($requestId, self::operator(), $request->input('tenants'), $request->input('scope'), $request->ip());
        abort_if($redirect === null, 404);

        return response()->json(['redirect_to' => $redirect])->header('Cache-Control', 'no-store');
    }

    public function deny(Request $request, string $requestId): JsonResponse
    {
        $redirect = $this->authorizations->deny($requestId, self::operator(), $request->ip());
        abort_if($redirect === null, 404);

        return response()->json(['redirect_to' => $redirect])->header('Cache-Control', 'no-store');
    }

    private static function operator(): Operator
    {
        $operator = Auth::guard('operator')->user();
        abort_unless($operator instanceof Operator, 401);

        return $operator;
    }
}
