<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Plugin\Services\PluginStates;
use App\Models\Tenant;
use Closure;

/**
 * プラグインの定期の処理を、そのプラグインを使う店だけで回す（ADR 0025）。店ごとに店の文脈に入って確かめる（店の DB をまたがない）。
 * 使うのをやめた店でも、片付けないとお金や在庫が宙に浮く仕事が残っている時だけ（$leftover が真）は回す（贈り物の期限での返金など。ADR 0055）
 */
final class PluginTenants
{
    public function __construct(private readonly PluginStates $states) {}

    /**
     * @param  Closure(Tenant): void  $work  店の文脈の中で呼ぶ
     * @param  ?Closure(): bool  $leftover  使うのをやめた店で、それでも片付ける仕事が残っているか（店の文脈の中で呼ぶ。無ければ使う店だけ）
     * @return int 回した店の数
     */
    public function each(string $plugin, Closure $work, ?Closure $leftover = null): int
    {
        $count = 0;

        // 店の DB を作り直している最中の店には入らない（DB や表が無くて落ち、後ろの店まで止めない。Tenant::serving）
        tenancy()->runForMultiple(Tenant::serving(), function (Tenant $tenant) use ($plugin, $work, $leftover, &$count): void {
            if ($this->states->enabled($plugin) || ($leftover !== null && $leftover())) {
                $work($tenant);
                $count++;
            }
        });

        return $count;
    }
}
