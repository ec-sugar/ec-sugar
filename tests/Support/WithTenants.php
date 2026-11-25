<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use Closure;

/**
 * テスト用: 店（テナント）を作り、店の中で処理する。作った店は $this->tenants に入れる（tearDown で消す）
 */
trait WithTenants
{
    /** @var list<Tenant> */
    private array $tenants = [];

    /**
     * 店を作る（ドメインは {id}.localhost）
     */
    private function tenant(string $id, ?string $name = null): Tenant
    {
        $tenant = Tenant::create(['id' => $id, 'name' => $name ?? "店 {$id}"]);
        $tenant->domains()->create(['domain' => "{$id}.localhost"]);
        $this->tenants[] = $tenant;

        return $tenant;
    }

    /**
     * 店の中で処理して、必ず店の外へ戻る
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(string $id, Closure $work): mixed
    {
        tenancy()->initialize(Tenant::query()->findOrFail($id));

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }

    /**
     * 作った店を消す（tearDown で）
     */
    private function deleteTenants(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->tenants as $tenant) {
            $tenant->delete();
        }

        $this->tenants = [];
    }
}
