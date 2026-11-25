<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\DevDoctor;

/**
 * 開発の環境の診断（make doctor）のうち、ファイルを比べる所。小さな置き場の写しを作り、そろっている形は ok、
 * 今日起きた壊れ方（vendor をリンクで借りた・lock と違う vendor・控えに無い App・node_modules のずれ）は NG になることを確かめる
 */
final class DevDoctorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/t0192-doctor-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/plugins/plugin-faq/database/migrations', 0777, true);
        mkdir($this->root.'/plugins/plugin-unused/database/migrations', 0777, true);
        mkdir($this->root.'/vendor/composer', 0777, true);
        mkdir($this->root.'/vendor/ec-sugar', 0777, true);
        mkdir($this->root.'/bootstrap/cache', 0777, true);
        mkdir($this->root.'/database/migrations/tenant', 0777, true);

        $this->json('composer.json', ['require' => ['php' => '^8.4', 'laravel/framework' => '^13.0', 'ec-sugar/plugin-faq' => '^0.1'], 'config' => ['platform' => ['php' => '8.4.1']]]);
        $this->json('plugins/plugin-faq/composer.json', ['name' => 'ec-sugar/plugin-faq', 'version' => '0.1.0', 'extra' => ['laravel' => ['providers' => ['EcSugar\\PluginFaq\\FaqServiceProvider']]]]);
        $this->json('plugins/plugin-unused/composer.json', ['name' => 'ec-sugar/plugin-unused', 'version' => '0.1.0']);
        $packages = [
            ['name' => 'laravel/framework', 'version' => 'v13.17.0', 'dist' => ['reference' => 'aaa']],
            ['name' => 'ec-sugar/plugin-faq', 'version' => '0.1.0', 'dist' => ['type' => 'path', 'reference' => 'bbb']],
        ];
        $this->json('composer.lock', ['packages' => $packages, 'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '12.0.0', 'dist' => ['reference' => 'ccc']]]]);
        $this->json('vendor/composer/installed.json', ['packages' => [...$packages, ['name' => 'phpunit/phpunit', 'version' => '12.0.0', 'dist' => ['reference' => 'ccc']]], 'dev' => true]);
        symlink('../../plugins/plugin-faq', $this->root.'/vendor/ec-sugar/plugin-faq');
        file_put_contents($this->root.'/bootstrap/cache/packages.php', "<?php return array ('ec-sugar/plugin-faq' => array ('providers' => array (0 => 'EcSugar\\\\PluginFaq\\\\FaqServiceProvider')));\n");

        touch($this->root.'/database/migrations/2026_01_01_000000_create_tenants_table.php');
        touch($this->root.'/database/migrations/tenant/2026_01_01_000100_create_parties_table.php');
        touch($this->root.'/plugins/plugin-faq/database/migrations/2026_01_02_000000_create_faq_table.php');
        touch($this->root.'/plugins/plugin-unused/database/migrations/2026_01_03_000000_create_unused_table.php');

        $lock = ['packages' => ['' => ['name' => 'ec-sugar'], 'node_modules/vue' => ['version' => '3.5.0'], 'node_modules/@rollup/rollup-linux-x64-gnu' => ['version' => '4.0.0', 'optional' => true]]];
        $this->json('package-lock.json', $lock);
        mkdir($this->root.'/node_modules');
        $this->json('node_modules/.package-lock.json', ['packages' => ['node_modules/vue' => ['version' => '3.5.0']]]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function test_a_matching_environment_is_ok(): void
    {
        $this->assertSame([DevDoctor::OK], $this->levels(DevDoctor::composerFindings($this->root)));
        // 使っていない App のフォルダは注意だけ
        $this->assertSame([DevDoctor::WARN, DevDoctor::OK], $this->levels(DevDoctor::pluginFindings($this->root)));
        // ほかの OS 向けの任意の依存（optional）は入っていなくてよい
        $this->assertSame([DevDoctor::OK], $this->levels(DevDoctor::npmFindings($this->root)));
    }

    public function test_a_vendor_borrowed_by_a_link_is_ng(): void
    {
        $other = $this->root.'-other';
        rename($this->root.'/vendor', $other);
        symlink($other, $this->root.'/vendor');

        try {
            $findings = DevDoctor::composerFindings($this->root);
            $this->assertSame([DevDoctor::NG], $this->levels($findings));
            $this->assertStringContainsString('リンク', $findings[0][1]);
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    }

    public function test_a_vendor_that_differs_from_the_lock_is_ng(): void
    {
        $this->json('vendor/composer/installed.json', ['packages' => [
            ['name' => 'laravel/framework', 'version' => 'v13.16.0', 'dist' => ['reference' => 'zzz']],
            ['name' => 'phpunit/phpunit', 'version' => '12.0.0', 'dist' => ['reference' => 'ccc']],
        ], 'dev' => true]);

        $findings = DevDoctor::composerFindings($this->root);
        $this->assertSame([DevDoctor::NG], $this->levels($findings));
        $this->assertStringContainsString('laravel/framework v13.16.0 → v13.17.0', $findings[0][1]);
        $this->assertStringContainsString('ec-sugar/plugin-faq が無い', $findings[0][1]);

        // App の方は「vendor に入っていない」をまとめて 1 行
        $plugins = DevDoctor::pluginFindings($this->root);
        $this->assertContains(DevDoctor::NG, $this->levels($plugins));
        $this->assertStringContainsString('vendor に入っていない App とテーマが 1 個', $plugins[0][1]);
    }

    public function test_a_plugin_missing_from_the_laravel_cache_or_pointing_elsewhere_is_ng(): void
    {
        file_put_contents($this->root.'/bootstrap/cache/packages.php', "<?php return array ();\n");
        $findings = DevDoctor::pluginFindings($this->root);
        $this->assertSame(DevDoctor::NG, $findings[0][0]);
        $this->assertStringContainsString('package:discover', $findings[0][2]);

        // ほかの作業場所の App を指すリンク（vendor を写して借りたとき）
        unlink($this->root.'/vendor/ec-sugar/plugin-faq');
        mkdir($this->root.'/elsewhere');
        symlink($this->root.'/elsewhere', $this->root.'/vendor/ec-sugar/plugin-faq');
        $messages = array_column(DevDoctor::pluginFindings($this->root), 1);
        $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'ほかの作業場所の App を読む')));
    }

    public function test_node_modules_that_differ_from_the_lock_are_ng(): void
    {
        $this->json('node_modules/.package-lock.json', ['packages' => ['node_modules/vue' => ['version' => '3.4.0'], 'node_modules/left-pad' => ['version' => '1.0.0']]]);

        $findings = DevDoctor::npmFindings($this->root);
        $this->assertSame([DevDoctor::NG], $this->levels($findings));
        $this->assertStringContainsString('vue 3.4.0 → 3.5.0', $findings[0][1]);
        $this->assertStringContainsString('left-pad は lock に無い', $findings[0][1]);
    }

    public function test_tenant_migrations_include_only_the_apps_in_use(): void
    {
        $this->assertSame(['2026_01_01_000000_create_tenants_table'], DevDoctor::migrationNames($this->root, 'central'));
        $this->assertSame(
            ['2026_01_01_000100_create_parties_table', '2026_01_02_000000_create_faq_table'],
            DevDoctor::migrationNames($this->root, 'tenant'),
        );
    }

    /**
     * @param  array<mixed>  $data
     */
    private function json(string $path, array $data): void
    {
        file_put_contents($this->root.'/'.$path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $findings
     * @return list<string>
     */
    private function levels(array $findings): array
    {
        return array_column($findings, 0);
    }
}
