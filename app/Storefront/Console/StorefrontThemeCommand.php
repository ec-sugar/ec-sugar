<?php

declare(strict_types=1);

namespace App\Storefront\Console;

use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Themes\ThemeSelector;
use Illuminate\Console\Command;

/**
 * 店のテーマを見る・変える（公式テーマ 1）。テーマは composer で入れたものだけを選べる（管理画面からコードは入れない。CONCEPT 7.2）。
 * 変えたことは中央の監査ログに残す（ThemeSelector。管理画面の店の設定も同じところを通る）。
 */
final class StorefrontThemeCommand extends Command
{
    protected $signature = 'storefront:theme
        {tenant? : テナントの ID（省略すると入っているテーマの一覧）}
        {theme? : 使うテーマの名前（省略すると今のテーマを出す。default に戻すときは --reset）}
        {--reset : 店のテーマを外し、既定のテーマに戻す}';

    protected $description = 'ショップのテーマを店ごとに見る・変える';

    public function handle(ThemeRegistry $themes, ThemeSelector $selector): int
    {
        $tenantId = $this->argument('tenant');

        if (! is_string($tenantId)) {
            $this->table(['name', 'label'], array_map(static fn (string $name): array => [$name, $themes->get($name)->label], $themes->names()));

            return self::SUCCESS;
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            $this->error("テナントがありません: {$tenantId}");

            return self::INVALID;
        }

        $current = $tenant->getAttribute('theme');
        $name = $this->argument('theme');

        if (! is_string($name) && ! $this->option('reset')) {
            $this->line('今のテーマ: '.$themes->forTenant($tenant)->name.(is_string($current) ? '' : '（既定）'));

            return self::SUCCESS;
        }

        if (is_string($name) && ! $themes->has($name)) {
            $this->error("入っていないテーマです: {$name}（php artisan storefront:theme で一覧）");

            return self::INVALID;
        }

        $next = $this->option('reset') ? null : $name;
        $selector->change($tenant, $next, 'cli:'.(get_current_user() ?: 'unknown'));

        $this->info('テーマを '.($next ?? '既定（'.$themes->active()->name.'）').' にしました');

        return self::SUCCESS;
    }
}
