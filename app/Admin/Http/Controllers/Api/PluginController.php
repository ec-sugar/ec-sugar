<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Core\Plugin\InstalledPackage;
use App\Core\Plugin\InstalledPackages;
use App\Core\Plugin\PluginRegistry;
use App\Core\Theme\ThemeRegistry;
use App\Core\Version;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * 入っているプラグインとテーマの一覧。オーナーだけ（経路の RequireOwner）、読むだけ。
 * 入れる・外すは composer と CLI だけで、管理画面からはできない（CONCEPT 7.2）
 */
final class PluginController
{
    public function index(InstalledPackages $installed, ThemeRegistry $themes, PluginRegistry $plugins): JsonResponse
    {
        // テーマごとの使っている店（店のテーマが未設定か入っていなければ、既定のテーマで描いている）。中央の DB の店の一覧だけを読む
        $usedBy = [];

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            if ($tenant instanceof Tenant) {
                $usedBy[$themes->forTenant($tenant)->name][] = ['id' => $tenant->getTenantKey(), 'name' => $tenant->getAttribute('name')];
            }
        }

        return response()->json([
            'core_version' => Version::CORE,
            'packages' => array_map(static fn (InstalledPackage $p): array => [
                'package' => $p->package,
                'kind' => $p->kind,
                'name' => $p->name,
                'version' => $p->version,
                'description' => $p->description,
                'authors' => $p->authors,
                'licenses' => $p->licenses,
                'core' => $p->core,
                'compatible' => $p->compatible,
                'first_party' => $p->firstParty,
                'problem' => $p->problem,
                // 動いている（テーマ API・プラグイン API のバージョンが合って登録簿に載ったもの）
                'active' => $p->name !== null && ($p->kind === 'theme' ? $themes->has($p->name) : $plugins->has($p->name)),
                'default' => $p->kind === 'theme' && $p->name === config('storefront.theme'),
                'shops' => $p->kind === 'theme' && $p->name !== null ? $usedBy[$p->name] ?? [] : [],
            ], $installed->all()),
        ]);
    }
}
