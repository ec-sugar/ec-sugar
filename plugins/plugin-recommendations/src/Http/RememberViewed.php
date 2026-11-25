<?php

declare(strict_types=1);

namespace EcSugar\PluginRecommendations\Http;

use App\Core\Plugin\Services\PluginStates;
use Closure;
use EcSugar\PluginRecommendations\RecentlyViewed;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ショップの商品の画面を開いたら、その商品の ID を「最近見た商品」の Cookie の先頭に足す（ADR 0071）。App がショップのミドルウェアの群の終わりに足す。
 * 商品の画面（storefront.product）を出せた時（200）だけで、ほかの画面では経路の名前を見るだけ（DB を引かない）。この店で App を使っていなければ何もしない
 */
final class RememberViewed
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->route()?->getName() !== 'storefront.product' || $response->getStatusCode() !== 200 || ! tenancy()->initialized) {
            return $response;
        }

        $id = strtolower((string) $request->route('listingId'));

        if (RecentlyViewed::isId($id) && app(PluginStates::class)->enabled('recommendations')) {
            $response->headers->setCookie(RecentlyViewed::remember($request, $id));
        }

        return $response;
    }
}
