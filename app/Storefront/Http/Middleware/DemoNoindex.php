<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use App\Core\Sample\Demo\DemoShop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * デモの店（ADR 0086）の写真・robots.txt などセッションを持たない群（storefront-media）の答えにも X-Robots-Tag: noindex を付ける
 * （画面は DemoVisitor が付ける。写真だけが画像の検索に載らないように）。デモでない店では何もしない
 */
final class DemoNoindex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (DemoShop::active()) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
