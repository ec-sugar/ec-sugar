<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Trace\TraceId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 要求ごとに処理を追う ID（trace ID）を決める（ADR 0087）。すべての要求のいちばん外側で動く。
 *
 * - 外から来る X-Request-Id・traceparent などは使わない（誰でも好きな値を付けられる。ほかの処理の ID を名乗って記録に紛れ込ませない）。
 *   いつもこちらで新しく決める
 * - 応答の見出し X-Request-Id に返すのは管理画面のホストだけ（運営がエラーの時に ID を拾って記録を引くため）。
 *   ショップ・Webhook の受付・確かめ口には返さない（外の人に、記録を探るための手がかりを渡さない）
 */
final class AssignTraceId
{
    public const string HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = TraceId::start();

        $response = $next($request);

        if ($request->getHost() === config('admin.domain')) {
            $response->headers->set(self::HEADER, $id);
        }

        return $response;
    }
}
