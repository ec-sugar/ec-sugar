<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Plugin\Services\PluginStates;
use App\Http\Middleware\RequirePlugin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App の契約のテストの部品: 経路の印 plugin:<名前>（RequirePlugin）の代わりに入れ、使っていない店で止めた App の名前を残してから、
 * 本物の RequirePlugin に渡す。404 が「使っていないから」の関所から出たのか、App の受け口（無い ID など）から出たのかを見分ける
 */
final class ContractPluginGate
{
    /** @var list<string> 使っていない店で止めた App の名前（止めた順） */
    public static array $stopped = [];

    public function __construct(private readonly PluginStates $states) {}

    public function handle(Request $request, Closure $next, string $plugin): Response
    {
        if (! tenancy()->initialized || ! $this->states->enabled($plugin)) {
            self::$stopped[] = $plugin;
        }

        return (new RequirePlugin($this->states))->handle($request, $next, $plugin);
    }
}
