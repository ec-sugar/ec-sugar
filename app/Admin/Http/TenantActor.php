<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Http\Middleware\EnterTenant;
use Illuminate\Http\Request;
use LogicException;

/**
 * テナントの中で運営が行った操作の actor（`operator:<id>`）。ドメインイベントの actor にそのまま渡す。
 * EnterTenant を通ったリクエストでしか取れない（通っていなければ例外。actor 無しで記録させない）。
 */
final class TenantActor
{
    public static function of(Request $request): string
    {
        $actor = $request->attributes->get(EnterTenant::ACTOR);

        if (! is_string($actor) || ! tenancy()->initialized) {
            throw new LogicException('EnterTenant を通っていないリクエストです');
        }

        return $actor;
    }
}
