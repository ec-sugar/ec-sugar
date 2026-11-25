<?php

declare(strict_types=1);

namespace App\Core\Plugin\Scaffold;

use App\Core\Plugin\Manifest\AppCategory;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\View\FileViewFinder;
use JsonException;
use RuntimeException;

/**
 * 新しい App（プラグイン）のひな形を出す（make:plugin）。既存の App を写すと、その App だけの処理まで持ち込むので、
 * 公式のひな形から始めて、人にも AI にも作りのばらつきを減らす。
 * 出すのは今の App の作り方（plugins/README.md・ADR 0025・ADR 0051・conventions.md）に沿った最小の形:
 * composer.json（マニフェスト）・登録・店の DB の表・書く係（連鎖のロックとイベント）・管理画面の App の画面・ショップの経路と画面・テスト・README・ヘルプ。
 * 上書きはしない（1 つでも行き先にファイルがあれば、何も書かずに断る）。ルートの composer.json・一覧のテスト・ヘルプの目次は手で足す（manualSteps）
 */
final class AppScaffold
{
    /** 名前の長さ（表・索引・イベントの集約の名前が、DB と核の上限に収まるように。核の登録簿は 32 まで） */
    public const int NAME_MAX = 24;

    public const int TITLE_MAX = 40;

    /** 英小文字で始まり、英小文字・数字をハイフン 1 つでつなぐ（先頭・末尾・続けてのハイフンは断る） */
    private const string NAME_PATTERN = '/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/';

    /** 管理画面に出す名前で断る文字: 制御文字・文字の向きを変える印・幅の無い空白と、生成するコードの文字列や Blade を壊す記号 */
    private const string TITLE_REFUSED = '/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\'"\\\\`<>&{}@$]/u';

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? base_path(), DIRECTORY_SEPARATOR);
    }

    /**
     * 名前の誤り（無ければ null）。形・長さと、入っている App・使われている名前空間・ファイル・表と重ならないこと
     */
    public function nameProblem(string $name, PluginRegistry $plugins): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || strlen($name) < 2 || strlen($name) > self::NAME_MAX) {
            return '名前は英小文字で始まり、英小文字・数字とハイフンで '.self::NAME_MAX.' 文字まで（例 stamp-card）。ハイフンは先頭・末尾・続けて使えません';
        }

        if ($plugins->has($name)) {
            return "App {$name} はもう入っています";
        }

        // ショップの画面の Blade の名前空間（App の名前）が、核やテーマの名前空間と重ならない
        $finder = app()->bound('view') ? View::getFinder() : null;

        if ($finder instanceof FileViewFinder && array_key_exists($name, $finder->getHints())) {
            return "名前 {$name} は画面の名前空間で使われています";
        }

        if (class_exists($this->namespaceOf($name).'\\'.Str::studly($name).'ServiceProvider')) {
            return "名前空間 {$this->namespaceOf($name)} はもう使われています";
        }

        $table = self::prefixOf($name).'_items';

        foreach ([...(glob($this->root.'/database/migrations/*.php') ?: []), ...(glob($this->root.'/plugins/*/database/migrations/*.php') ?: [])] as $migration) {
            if (str_contains((string) file_get_contents($migration), "'{$table}'")) {
                return "表 {$table} はもうあります（".basename($migration).'）';
            }
        }

        return null;
    }

    /**
     * 管理画面に出す名前の誤り（無ければ null）
     */
    public static function titleProblem(string $title): ?string
    {
        if (trim($title) !== $title || $title === '' || mb_strlen($title) > self::TITLE_MAX || preg_match(self::TITLE_REFUSED, $title) !== 0) {
            return '表示名は '.self::TITLE_MAX.' 文字まで。前後の空白・改行・引用符・\\ ` < > & { } @ $ は使えません';
        }

        return null;
    }

    /**
     * ひな形を書く。行き先が 1 つでもあれば何も書かずに断る（上書きしない）
     *
     * @return list<string> 書いたファイル（ルートからの道筋）
     *
     * @throws RuntimeException
     */
    public function make(string $name, string $title, AppCategory $category): array
    {
        $files = $this->files($name, $title, $category);

        foreach (array_keys($files) as $path) {
            if (file_exists($this->root.'/'.$path)) {
                throw new RuntimeException("{$path} はもうあります（上書きしません）");
            }
        }

        if (is_dir($this->root.'/plugins/'.self::pluginDir($name))) {
            throw new RuntimeException('plugins/'.self::pluginDir($name).' はもうあります（上書きしません）');
        }

        foreach ($files as $path => $content) {
            $full = $this->root.'/'.$path;

            if (! is_dir(dirname($full)) && ! mkdir(dirname($full), 0755, true) && ! is_dir(dirname($full))) {
                throw new RuntimeException('フォルダを作れません: '.dirname($path));
            }

            // x: 無い時だけ作る（確かめた後に別の誰かが作っても、上書きしない）
            $handle = @fopen($full, 'xb');

            if ($handle === false) {
                throw new RuntimeException("{$path} を作れません（もうあるか、書けません）");
            }

            fwrite($handle, $content);
            fclose($handle);
        }

        return array_keys($files);
    }

    /**
     * 手で足すこと（ひな形のコマンドは、ほかのファイルを書き換えない）
     *
     * @return list<string>
     */
    public static function manualSteps(string $name, string $title, AppCategory $category): array
    {
        $package = 'ec-sugar/'.self::pluginDir($name);
        $studly = Str::studly($name);
        $camel = Str::camel($name);

        return [
            "ルートの composer.json の require に \"{$package}\": \"^0.1\" を足し、composer update {$package} で入れる（path のパッケージ。symlink）",
            "tests/Feature/Admin/PluginListTest.php の入っているパッケージの一覧に '{$package}' を名前の順で足す",
            "tests/Feature/Plugin/AppManifestTest.php の App の目的の一覧に '{$name}' => '{$category->value}' を名前の順で足す",
            // 全部の App の共通のテスト（AppContractTest）は、画面を持つ App の 1 件の作り方が無いと「足す」と落ちる
            "tests/Feature/Plugin/AppContractRecords.php の contractRecord に '{$name}' => \$this->saved(new \\EcSugar\\Plugin{$studly}\\Models\\{$studly}Item, ['title' => '契約の確認の記録', 'status' => 'open']), を足す",
            "resources/admin/navigation.js の HELP_PAGES に { key: '{$name}', label: '{$title}（App）', description: '…' } を App の説明の並びに足す",
            "resources/admin/help/index.js に import {$camel} from './{$name}.js'; と、CONTENT に '{$name}': {$camel} を足す",
            "plugins/README.md の App の一覧に plugin-{$name} を足す（目的のまとまりの下）",
            "php artisan test tests/Feature/Plugin/{$studly}AppTest.php で、ひな形のテストが通ることを確認してから中身を作る",
        ];
    }

    /**
     * 書くファイル（ルートからの道筋 => 中身）
     *
     * @return array<string, string>
     */
    private function files(string $name, string $title, AppCategory $category): array
    {
        $studly = Str::studly($name);
        $dir = 'plugins/'.self::pluginDir($name);
        $replace = [
            '__NAME__' => $name,
            '__PREFIX__' => self::prefixOf($name),
            '__STUDLY__' => $studly,
            '__TITLE__' => $title,
            '__CATEGORY__' => $category->value,
        ];
        // PHP の use の並びは App の名前で変わる（StampCard\Models と StampCard\StampCardStatus など）ので、入れ替えた後に並べ直す
        $stub = static fn (string $file): string => str_ends_with($file, '.php.stub') && ! str_ends_with($file, '.blade.php.stub')
            ? self::sortImports(strtr((string) file_get_contents(__DIR__.'/stubs/'.$file), $replace))
            : strtr((string) file_get_contents(__DIR__.'/stubs/'.$file), $replace);

        return [
            "{$dir}/composer.json" => $this->composerJson($name, $title, $category),
            "{$dir}/README.md" => $stub('README.md.stub'),
            "{$dir}/src/{$studly}ServiceProvider.php" => $stub('ServiceProvider.php.stub'),
            "{$dir}/src/{$studly}Status.php" => $stub('Status.php.stub'),
            "{$dir}/src/{$studly}Items.php" => $stub('Items.php.stub'),
            "{$dir}/src/{$studly}AdminPage.php" => $stub('AdminPage.php.stub'),
            "{$dir}/src/Models/{$studly}Item.php" => $stub('Model.php.stub'),
            "{$dir}/src/Http/{$studly}Controller.php" => $stub('Controller.php.stub'),
            "{$dir}/resources/views/index.blade.php" => $stub('index.blade.php.stub'),
            "{$dir}/database/migrations/".now()->format('Y_m_d').'_000100_create_'.self::prefixOf($name).'_items_table.php' => $stub('migration.php.stub'),
            "tests/Feature/Plugin/{$studly}AppTest.php" => $stub('AppTest.php.stub'),
            "resources/admin/help/{$name}.js" => $stub('help.js.stub'),
        ];
    }

    /**
     * composer.json（path のパッケージとマニフェスト）。値は JSON として書く（表示名をそのまま文字列に入れない）
     */
    private function composerJson(string $name, string $title, AppCategory $category): string
    {
        $namespace = $this->namespaceOf($name);

        try {
            return json_encode([
                'name' => 'ec-sugar/'.self::pluginDir($name),
                'description' => "EC-SUGAR の App: {$title}",
                'type' => 'library',
                'version' => '0.1.0',
                'license' => 'proprietary',
                'authors' => [['name' => 'Fivenine design']],
                'require' => ['php' => '^8.3'],
                'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
                'extra' => [
                    'laravel' => ['providers' => [$namespace.'\\'.Str::studly($name).'ServiceProvider']],
                    'ec-sugar' => ['type' => 'plugin', 'name' => $name, 'plugin_api' => PluginRegistry::API_VERSION, 'core' => '^1.0', 'category' => $category->value],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $e) {
            throw new RuntimeException('composer.json を作れません', previous: $e);
        }
    }

    /**
     * ファイルの頭の use（字下げの無い 1 行ずつのもの。クラスの中の use は字下げがあるので触らない）を、
     * pint（ordered_imports の alpha）と同じ順に並べ直す: \ を空白に替えて、大文字小文字を区別せずに比べる
     */
    private static function sortImports(string $code): string
    {
        $lines = explode("\n", $code);
        $at = array_keys(array_filter($lines, static fn (string $line): bool => preg_match('/\Ause [A-Za-z0-9_\\\\]+;\z/', $line) === 1));
        $imports = array_map(static fn (int $i): string => $lines[$i], $at);
        $key = static fn (string $line): string => str_replace('\\', ' ', substr($line, 4, -1));
        usort($imports, static fn (string $a, string $b): int => strcasecmp($key($a), $key($b)));

        foreach ($at as $n => $i) {
            $lines[$i] = $imports[$n];
        }

        return implode("\n", $lines);
    }

    private function namespaceOf(string $name): string
    {
        return 'EcSugar\\Plugin'.Str::studly($name);
    }

    /** 表とイベントの名前の頭（ハイフンを _ に。PluginMigrationsTest と同じ） */
    public static function prefixOf(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    public static function pluginDir(string $name): string
    {
        return 'plugin-'.$name;
    }
}
