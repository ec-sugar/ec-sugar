<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Plugin\Services\PluginStates;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 経路の印 plugin:<名前>。今の店がそのプラグインを使っていなければ 404（あるかどうかも見せない。ADR 0025）。店の文脈の中の経路だけに付ける
 */
final class RequirePlugin
{
    public function __construct(private readonly PluginStates $states) {}

    public function handle(Request $request, Closure $next, string $plugin): Response
    {
        abort_unless(tenancy()->initialized && $this->states->enabled($plugin), 404);

        return $next($request);
    }
}
