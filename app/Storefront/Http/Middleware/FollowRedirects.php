<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use App\Core\Shop\Services\RedirectService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 店が足した転送（ADR 0061 の 10）。ショップの答えが「見つかりません」（404）の GET と HEAD だけ、合う転送があれば 301 で送る。
 * 今ある画面は上書きしない（転送より画面が先）。行き先は同じショップの中の道筋だけ（RedirectService が持つ時に確かめてある）で、
 * Location はこのホストの URL にして送る（外のサイトへは飛ばない）。店頭の群（'storefront'）のいちばん内側に置く
 */
final class FollowRedirects
{
    public function __construct(private readonly RedirectService $redirects) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 404 || ! ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
            return $response;
        }

        // 道筋は % の形のまま（RedirectService が戻してから比べる）。? の後ろは届いたままの形
        $query = $request->server->get('QUERY_STRING');
        $to = $this->redirects->find($request->getPathInfo(), is_string($query) ? $query : null);

        if ($to === null) {
            return $response;
        }

        // 念のため、ここでも同じショップの中の道筋だけを通す（// と /\ で始まる行き先は、ブラウザがほかのホストと読む）
        if (preg_match('#\A/(?![/\\\\])[\x21-\x7E]*\z#', $to) !== 1 || str_contains($to, '\\')) {
            return $response;
        }

        return redirect()->to($request->getSchemeAndHttpHost().$to, 301)->header('Cache-Control', 'no-cache, private');
    }
}
