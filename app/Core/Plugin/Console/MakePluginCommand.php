<?php

declare(strict_types=1);

namespace App\Core\Plugin\Console;

use App\Core\Plugin\Manifest\AppCategory;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Scaffold\AppScaffold;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * 新しい App（プラグイン）のひな形を plugins/plugin-<名前> に作る。開発の手元で使う（本番のイメージは読み取り専用で、App は composer で入れる。CONCEPT 7.2）。
 * 書くのは新しいファイルだけで、ルートの composer.json・一覧のテスト・ヘルプの目次は書き換えない（最後に手で足すことを出す）
 */
final class MakePluginCommand extends Command
{
    protected $signature = 'make:plugin
        {name : App の名前（英小文字・数字・ハイフン。例 stamp-card。表とイベントの頭は stamp_card）}
        {--category=other : App の目的（analytics・marketing・page・selling・products・payment・shipping・customers・operations・other）}
        {--title= : 管理画面に出す名前（省くと App の名前）}';

    protected $description = '新しい App のひな形を plugins/plugin-<名前> に作る（登録・表・管理画面の App の画面・ショップの経路・テスト・README・ヘルプ）';

    public function handle(AppScaffold $scaffold, PluginRegistry $plugins): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('本番ではひな形を作りません（App は開発の手元で作り、composer で入れます）。');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        $title = is_string($this->option('title')) ? $this->option('title') : $name;
        $category = AppCategory::tryFrom((string) $this->option('category'));
        $problem = match (true) {
            $category === null => '目的は '.implode('・', array_column(AppCategory::cases(), 'value')).' のどれかです',
            default => $scaffold->nameProblem($name, $plugins) ?? AppScaffold::titleProblem($title),
        };

        if ($problem !== null || $category === null) {
            $this->error((string) $problem);

            return self::FAILURE;
        }

        try {
            $files = $scaffold->make($name, $title, $category);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("App「{$title}」のひな形を作りました:");

        foreach ($files as $file) {
            $this->line("  {$file}");
        }

        $this->newLine();
        $this->info('次に手動で追加すること:');

        foreach (AppScaffold::manualSteps($name, $title, $category) as $i => $step) {
            $this->line('  '.($i + 1).'. '.$step);
        }

        return self::SUCCESS;
    }
}
