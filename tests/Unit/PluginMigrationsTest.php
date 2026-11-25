<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * プラグインの表の移行（ADR 0050 の 2）。表の名前はプラグインの名前で始め、核の表は変えない（作る・足すのは自分の表だけ。参照はよい）
 */
final class PluginMigrationsTest extends TestCase
{
    public function test_plugins_create_and_change_only_their_own_tables(): void
    {
        $files = glob(dirname(__DIR__, 2).'/plugins/*/database/migrations/*.php') ?: [];
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $plugin = basename(dirname($file, 3));
            $manifest = json_decode((string) file_get_contents(dirname($file, 3).'/composer.json'), true, 32, JSON_THROW_ON_ERROR);
            $prefix = str_replace('-', '_', (string) ($manifest['extra']['ec-sugar']['name'] ?? ''));
            $this->assertNotSame('', $prefix, "{$plugin}: マニフェストに名前が無い");

            $code = (string) file_get_contents($file);
            preg_match_all("/Schema::(?:create|table)\\('([a-z0-9_]+)'/", $code, $tables);
            preg_match_all('/ALTER TABLE ([a-z0-9_]+)/i', $code, $altered);

            foreach ([...$tables[1], ...$altered[1]] as $table) {
                $this->assertStringStartsWith($prefix, $table, basename($file).": プラグイン {$plugin} が自分のでない表 {$table} を作る・変える");
            }
        }
    }
}
