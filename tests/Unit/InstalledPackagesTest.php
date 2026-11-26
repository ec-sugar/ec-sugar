<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Plugin\InstalledPackage;
use App\Core\Plugin\InstalledPackages;
use App\Core\Version;
use PHPUnit\Framework\TestCase;

/**
 * 入っているプラグインとテーマの読み取り。マニフェストのあるパッケージだけ、おかしな所は例外にせず problem に。
 * 自社かどうかは名前だけでは決めない、対応する核のバージョンを確かめる、作り手の書いた文は切って制御文字を除く
 */
final class InstalledPackagesTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = (string) tempnam(sys_get_temp_dir(), 'installed');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_only_packages_with_a_manifest_are_read_and_checked(): void
    {
        $packages = $this->read([
            ['name' => 'laravel/framework', 'version' => 'v13.0.0'],
            $this->package('ec-sugar/theme-kinari', ['type' => 'theme', 'name' => 'kinari', 'core' => '^1.0'], authors: [['name' => 'Hana']]),
            $this->package('acme/plugin-banner', ['type' => 'plugin', 'name' => 'banner', 'core' => '^2.0'], dist: ['type' => 'zip', 'url' => 'https://example.com/x.zip']),
            // ec-sugar/ を名乗っていても、plugins/ から入っていなければ自社ではない
            $this->package('ec-sugar/plugin-fake', ['type' => 'plugin', 'name' => 'fake', 'core' => '>=1.0 <2'], dist: ['type' => 'zip', 'url' => 'plugins/x']),
        ]);

        $this->assertSame(['kinari', 'banner', 'fake'], array_map(static fn (InstalledPackage $p): ?string => $p->name, $packages));
        [$kinari, $banner, $fake] = $packages;
        $this->assertSame(['theme', '0.1.0', true, true, null, ['Hana'], ['proprietary']], [$kinari->kind, $kinari->version, $kinari->compatible, $kinari->firstParty, $kinari->problem, $kinari->authors, $kinari->licenses]);
        // 核のバージョンの範囲の外
        $this->assertSame(['plugin', false, false, null], [$banner->kind, $banner->compatible, $banner->firstParty, $banner->problem]);
        $this->assertSame([true, false], [$fake->compatible, $fake->firstParty]);
        $this->assertSame('1.1.1', Version::CORE);
    }

    /**
     * マニフェストのキー: 新しいキー ec-sugar が無ければ旧名のころのキー sugar-ec も読む。両方あれば新しい方だけ。
     * 旧名の作り手の名前（sugar-ec/）は、plugins/ から入っていても自社と見ない（名前で自社の見分けを緩めない）
     */
    public function test_the_old_manifest_key_is_still_read_and_the_new_one_wins(): void
    {
        $packages = $this->read([
            $this->package('acme/theme-old', ['type' => 'theme', 'name' => 'old', 'core' => '^1.0'], key: 'sugar-ec'),
            $this->package('acme/plugin-old', ['type' => 'plugin', 'name' => 'oldapp', 'core' => '^1.0'], key: 'sugar-ec'),
            $this->package('acme/plugin-new', ['type' => 'plugin', 'name' => 'newapp', 'core' => '^1.0']),
            ['name' => 'acme/plugin-both', 'version' => '0.1.0', 'extra' => [
                'sugar-ec' => ['type' => 'plugin', 'name' => 'stale', 'core' => '^9.0'],
                'ec-sugar' => ['type' => 'plugin', 'name' => 'both', 'core' => '^1.0'],
            ]],
            // 新しいキーが壊れていれば、古いキーに戻って読まない（両方あれば新しい方だけ）
            ['name' => 'acme/plugin-broken', 'version' => '0.1.0', 'extra' => [
                'sugar-ec' => ['type' => 'plugin', 'name' => 'fallback', 'core' => '^1.0'],
                'ec-sugar' => 'plugin',
            ]],
            $this->package('sugar-ec/plugin-renamed', ['type' => 'plugin', 'name' => 'renamed', 'core' => '^1.0'], key: 'sugar-ec'),
        ]);

        $read = array_combine(array_map(static fn (InstalledPackage $p): string => $p->package, $packages), $packages);
        ksort($read);
        $this->assertSame(['acme/plugin-both', 'acme/plugin-new', 'acme/plugin-old', 'acme/theme-old', 'sugar-ec/plugin-renamed'], array_keys($read));
        $this->assertSame(['theme', 'old', true, null], [$read['acme/theme-old']->kind, $read['acme/theme-old']->name, $read['acme/theme-old']->compatible, $read['acme/theme-old']->problem]);
        $this->assertSame(['plugin', 'oldapp', true], [$read['acme/plugin-old']->kind, $read['acme/plugin-old']->name, $read['acme/plugin-old']->compatible]);
        $this->assertSame(['plugin', 'newapp', true], [$read['acme/plugin-new']->kind, $read['acme/plugin-new']->name, $read['acme/plugin-new']->compatible]);
        $this->assertSame(['both', true], [$read['acme/plugin-both']->name, $read['acme/plugin-both']->compatible]);
        $this->assertFalse($read['sugar-ec/plugin-renamed']->firstParty);
    }

    public function test_broken_manifests_are_reported_not_thrown(): void
    {
        $packages = $this->read([
            $this->package('a/one', ['type' => 'module', 'name' => 'One!', 'core' => 'not a version']),
            $this->package('a/two', ['type' => 'plugin', 'name' => 'two']),
            $this->package('a/three', ['type' => 'plugin', 'name' => ['x'], 'core' => 1]),
            ['name' => 'a/four', 'extra' => ['ec-sugar' => 'plugin']],
        ]);

        $this->assertCount(3, $packages);
        $problems = array_combine(array_map(static fn (InstalledPackage $p): string => $p->package, $packages), array_map(static fn (InstalledPackage $p): ?string => $p->problem, $packages));
        $this->assertStringContainsString('種類（type）が theme でも plugin でもありません', (string) $problems['a/one']);
        $this->assertStringContainsString('名前（name）が無いか、形が違います', (string) $problems['a/one']);
        $this->assertStringContainsString('書き方が違います', (string) $problems['a/one']);
        $this->assertStringContainsString('書かれていません', (string) $problems['a/two']);
        $this->assertStringContainsString('名前（name）が無いか', (string) $problems['a/three']);
        foreach ($packages as $package) {
            $this->assertFalse($package->compatible, $package->package);
        }
    }

    public function test_text_from_the_author_is_cut_and_cleaned(): void
    {
        [$package] = $this->read([
            $this->package('a/long', ['type' => 'plugin', 'name' => 'long', 'core' => '^1.0'], description: "一行目\n\u{0007}<b>二行目</b>".str_repeat('あ', 400), authors: [['email' => 'no-name@example.com'], ...array_fill(0, 8, ['name' => 'x'])]),
        ]);

        $this->assertSame(300, mb_strlen((string) $package->description));
        $this->assertStringStartsWith('一行目 <b>二行目</b>', (string) $package->description);
        $this->assertCount(5, $package->authors);
    }

    public function test_a_missing_or_broken_record_reads_as_nothing(): void
    {
        $this->assertSame([], (new InstalledPackages($this->path.'-missing'))->all());

        foreach (['', '{', '"x"', '{"packages": "x"}', '{"packages": [1, null, "x"]}', str_repeat('[', 100).str_repeat(']', 100)] as $broken) {
            file_put_contents($this->path, $broken);
            $this->assertSame([], (new InstalledPackages($this->path))->all(), $broken);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return list<InstalledPackage>
     */
    private function read(array $packages): array
    {
        file_put_contents($this->path, (string) json_encode(['packages' => $packages, 'dev' => true, 'dev-package-names' => []]));

        return (new InstalledPackages($this->path))->all();
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $dist
     * @param  list<array<string, string>>  $authors
     * @return array<string, mixed>
     */
    private function package(string $name, array $manifest, array $dist = ['type' => 'path', 'url' => 'plugins/x'], ?string $description = null, array $authors = [], string $key = 'ec-sugar'): array
    {
        return ['name' => $name, 'version' => '0.1.0', 'dist' => $dist, 'license' => ['proprietary'], 'description' => $description, 'authors' => $authors, 'extra' => [$key => $manifest]];
    }
}
