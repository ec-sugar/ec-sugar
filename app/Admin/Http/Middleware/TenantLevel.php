<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use App\Admin\Services\OperatorAccess;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 経路に要る店の権限の段階の印（tenant-level:operate・tenant-level:money。ADR 0024）。
 * 確かめるのは EnterTenant（印を読んで、入る前に断る）。ここでは EnterTenant の後で、確かめが済んでいることだけを見る
 */
final class TenantLevel
{
    public function handle(Request $request, Closure $next, string $level): Response
    {
        if (! array_key_exists($level, OperatorAccess::LEVELS) || ! is_string($request->attributes->get(EnterTenant::LEVEL))) {
            throw new LogicException('tenant-level は EnterTenant の内側の経路に、read・operate・money で付ける');
        }

        return $next($request);
    }
}
