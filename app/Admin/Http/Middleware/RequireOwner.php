<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use App\Admin\Models\Operator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * オーナーだけの経路（メンバーと権限。ADR 0024）。auth:operator の後に置く
 */
final class RequireOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $operator = $request->user('operator');

        abort_unless($operator instanceof Operator && $operator->role === 'owner', 403, 'オーナーだけの操作です。');

        return $next($request);
    }
}
