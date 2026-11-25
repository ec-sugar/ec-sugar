<?php

declare(strict_types=1);

namespace App\Storefront\Console;

use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Themes\LegacyContentImport;
use Illuminate\Console\Command;
use Throwable;

/**
 * 店の設定にあった見せ方の項目（控えの表 legacy_storefront_settings）を、テーマの区画の値へ写す（ADR 0028。LegacyContentImport）。
 * テーマにまだバージョンが無い店だけを写すので、何度走らせてもよい。区画のテーマへの切り替え（ThemeSelector）でも同じことをする。
 */
final class ImportThemeContentCommand extends Command
{
    protected $signature = 'storefront:import-theme-content
        {tenant? : テナントの ID（省略するとすべての店）}
        {--theme= : 写す先のテーマ（省略するとその店が今使っているテーマ）}';

    protected $description = '店の設定にあった見せ方の項目を、テーマのセクションの値へコピーする（まだバージョンが無い店だけ）';

    public function handle(ThemeRegistry $themes, LegacyContentImport $import): int
    {
        $tenantId = $this->argument('tenant');
        $query = is_string($tenantId) ? Tenant::query()->whereKey($tenantId) : Tenant::query()->orderBy('id');
        $tenants = array_values(array_filter($query->get()->all(), static fn (mixed $tenant): bool => $tenant instanceof Tenant));

        if ($tenants === []) {
            $this->error('テナントがありません'.(is_string($tenantId) ? ": {$tenantId}" : ''));

            return self::INVALID;
        }

        $name = $this->option('theme');

        if (is_string($name) && ! $themes->has($name)) {
            $this->error("入っていないテーマです: {$name}");

            return self::INVALID;
        }

        $actor = 'cli:'.(get_current_user() ?: 'unknown');

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            $theme = is_string($name) ? $themes->get($name) : $themes->forTenant($tenant);

            // 1 つの店で落ちても、残りの店は続ける（終わりの値で失敗を知らせる）
            try {
                $done = $tenant->run(fn (): bool => $import->import($theme, $actor));
                $this->line("{$tenant->getTenantKey()}: テーマ {$theme->name} に".($done ? 'コピーして公開した' : 'はもうバージョンがあるか、コピーするものが無い'));
            } catch (Throwable $e) {
                report($e);
                $this->error("{$tenant->getTenantKey()}: 写せなかった（{$e->getMessage()}）");
                $failed = true;
            }
        }

        return isset($failed) ? self::FAILURE : self::SUCCESS;
    }
}
