<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 開発の環境の診断（`make doctor`、ops/dev/doctor.sh）のうち、ファイルの中身を比べる所。読むだけで、何も書かない。
 *
 * vendor が壊れていても診断できるように、ほかのクラス（vendor の autoload）を使わない。doctor.sh が `php -r` でこのファイルだけを読み込み、
 * DevDoctor::main([調べる項目, 置き場の根]) を呼ぶ。結果は 1 行ずつ「段階<TAB>見つけたこと<TAB>直し方」（段階は ok・warn・ng）で出す。
 *
 * 調べる項目:
 * - php: PHP の版と拡張が本番のイメージ（docker/Dockerfile）とそろうか（EnvironmentParityTest と同じ決まり）
 * - composer: vendor が composer.lock と同じか（vendor/composer/installed.json と lock の名前・版・参照を比べる）、vendor がリンクでないか
 * - npm: node_modules が package-lock.json と同じか（npm が入れたときに残す node_modules/.package-lock.json と比べる）
 * - plugins: plugins/ の App とテーマが composer.json・composer.lock・vendor・Laravel の控え（bootstrap/cache/packages.php）にそろうか
 * - migrations-central・migrations-tenant: 移行のファイルの名前（DB の migrations 表と比べるのは doctor.sh）
 */
final class DevDoctor
{
    /** docker/Dockerfile の docker-php-ext-install と pecl install にそろえる（EnvironmentParityTest が Dockerfile と照らし合わせる） */
    public const array EXTENSIONS = ['pdo_pgsql', 'intl', 'gd', 'zip', 'bcmath', 'redis', 'pcntl', 'exif'];

    public const string OK = 'ok';

    public const string WARN = 'warn';

    public const string NG = 'ng';

    /** 1 つの項目で並べる見つけたことの上限（多いときは「ほか N 件」にまとめる） */
    private const int MAX_LINES = 6;

    /**
     * doctor.sh の入口。$args は [調べる項目, 置き場の根]
     *
     * @param  list<string>  $args
     */
    public static function main(array $args): int
    {
        $check = $args[0] ?? '';
        $root = rtrim($args[1] ?? '', '/');

        if ($root === '' || ! is_dir($root)) {
            fwrite(STDERR, "保存先の根がありません: {$root}\n");

            return 2;
        }

        if (str_starts_with($check, 'migrations-')) {
            foreach (self::migrationNames($root, substr($check, strlen('migrations-'))) as $name) {
                echo $name, "\n";
            }

            return 0;
        }

        $findings = match ($check) {
            'php' => self::phpFindings($root),
            'composer' => self::composerFindings($root),
            'npm' => self::npmFindings($root),
            'plugins' => self::pluginFindings($root),
            default => null,
        };

        if ($findings === null) {
            fwrite(STDERR, "知らない項目です: {$check}\n");

            return 2;
        }

        foreach ($findings as [$level, $message, $fix]) {
            // 区切りの TAB と改行を中に入れない（doctor.sh が 1 行ずつ読む）
            echo implode("\t", array_map(static fn (string $s): string => str_replace(["\t", "\n", "\r"], ' ', $s), [$level, $message, $fix])), "\n";
        }

        return 0;
    }

    /**
     * 本番のイメージに無い拡張（足りないもの）
     *
     * @return list<string>
     */
    public static function missingExtensions(): array
    {
        return array_values(array_filter(self::EXTENSIONS, static fn (string $extension): bool => ! extension_loaded($extension)));
    }

    /**
     * composer が対象にする PHP の版（composer.json の config.platform.php）。読めなければ空
     */
    public static function targetPhpVersion(string $root): string
    {
        $composer = self::readJson($root.'/composer.json');
        $config = is_array($composer['config'] ?? null) ? $composer['config'] : [];
        $platform = is_array($config['platform'] ?? null) ? $config['platform'] : [];

        return is_string($platform['php'] ?? null) ? $platform['php'] : '';
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function phpFindings(string $root): array
    {
        $findings = [];
        $target = self::targetPhpVersion($root);

        if ($target === '') {
            $findings[] = [self::NG, 'composer.json の config.platform.php が読めません', 'composer.json を確認する'];
        } elseif (version_compare(PHP_VERSION, $target, '<')) {
            $findings[] = [self::NG, 'PHP '.PHP_VERSION.' は composer の対象 '.$target.' より古い', 'brew upgrade php（Linux は mise などで '.$target.' 以上に）'];
        }

        $missing = self::missingExtensions();

        if ($missing !== []) {
            $findings[] = [self::NG, '本番のイメージにある拡張が足りません: '.implode('・', $missing), 'pecl install redis など（CLAUDE.md の「開発コマンド」）。php -m で確認する'];
        }

        if ($findings === []) {
            $findings[] = [self::OK, 'PHP '.PHP_VERSION.'（対象 '.$target.' 以上）と拡張 '.count(self::EXTENSIONS).' つが本番のイメージとそろう', ''];
        }

        return $findings;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function composerFindings(string $root): array
    {
        $vendor = $root.'/vendor';

        if (is_link($vendor)) {
            return [[self::NG, 'vendor がほかの場所へのリンクです（'.(string) readlink($vendor).'）。App の読み込み先がずれ、phpstan やテストが誤って落ちます',
                'rm vendor && composer install --no-interaction（rm はリンクだけを消す。worktree でも自分の vendor を入れる）']];
        }

        if (! is_dir($vendor)) {
            return [[self::NG, 'vendor がありません', 'composer install --no-interaction']];
        }

        $lock = self::readJson($root.'/composer.lock');

        if ($lock === null) {
            return [[self::NG, 'composer.lock が読めません', 'git checkout composer.lock']];
        }

        $installed = self::readJson($vendor.'/composer/installed.json');

        if ($installed === null) {
            return [[self::NG, 'vendor/composer/installed.json がありません（入れる途中で止まった）', 'composer install --no-interaction']];
        }

        $locked = self::composerPackages($lock['packages'] ?? []);
        $lockedDev = self::composerPackages($lock['packages-dev'] ?? []);
        $have = self::composerPackages($installed['packages'] ?? []);
        $findings = [];

        if ($lockedDev !== [] && ($installed['dev'] ?? null) === false) {
            $findings[] = [self::NG, '開発用の依存（phpunit・phpstan など）が入っていません（--no-dev で入れた）', 'composer install --no-interaction'];
        } else {
            $locked += $lockedDev;
        }

        $drift = [];

        foreach ($locked as $name => [$version, $reference]) {
            if (! isset($have[$name])) {
                $drift[] = "{$name} が無い";
            } elseif ($have[$name][0] !== $version) {
                $drift[] = "{$name} {$have[$name][0]} → {$version}";
            } elseif ($reference !== '' && $have[$name][1] !== '' && $have[$name][1] !== $reference) {
                $drift[] = "{$name} の中身が違う";
            }
        }

        foreach (array_keys(array_diff_key($have, $locked)) as $name) {
            $drift[] = "{$name} は lock に無い";
        }

        if ($drift !== []) {
            $findings[] = [self::NG, 'vendor が composer.lock と違います（'.count($drift).' 個: '.self::sample($drift).'）', 'composer install --no-interaction'];
        }

        if ($findings === []) {
            $findings[] = [self::OK, 'vendor は composer.lock と同じ（'.count($locked).' 個）', ''];
        }

        return $findings;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function npmFindings(string $root): array
    {
        $fix = 'npm ci --ignore-scripts --no-audit --no-fund';
        $modules = $root.'/node_modules';
        $lock = self::readJson($root.'/package-lock.json');

        if ($lock === null) {
            return [[self::NG, 'package-lock.json が読めません', 'git checkout package-lock.json']];
        }

        if (is_link($modules)) {
            return [[self::WARN, 'node_modules がほかの場所へのリンクです（'.(string) readlink($modules).'）。ビルドがほかの作業の版で作られます', "rm node_modules && {$fix}"]];
        }

        if (! is_dir($modules)) {
            return [[self::WARN, 'node_modules がありません（管理画面とショップの画面をビルドするときだけ要る）', $fix]];
        }

        $hidden = self::readJson($modules.'/.package-lock.json');

        if ($hidden === null) {
            return [[self::WARN, 'node_modules に npm の控え（.package-lock.json）がありません。何が入っているか分かりません', $fix]];
        }

        $want = self::npmPackages($lock['packages'] ?? [], skipOptional: true);
        $have = self::npmPackages($hidden['packages'] ?? [], skipOptional: false);
        $drift = [];

        foreach ($want as $path => $version) {
            if (! isset($have[$path])) {
                $drift[] = self::npmName($path).' が無い';
            } elseif ($have[$path] !== $version) {
                $drift[] = self::npmName($path)." {$have[$path]} → {$version}";
            }
        }

        $allLocked = self::npmPackages($lock['packages'] ?? [], skipOptional: false);

        foreach (array_keys(array_diff_key($have, $allLocked)) as $path) {
            $drift[] = self::npmName($path).' は lock に無い';
        }

        if ($drift !== []) {
            return [[self::NG, 'node_modules が package-lock.json と違います（'.count($drift).' 個: '.self::sample($drift).'）', $fix]];
        }

        return [[self::OK, 'node_modules は package-lock.json と同じ（'.count($have).' 個）', '']];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function pluginFindings(string $root): array
    {
        $composer = self::readJson($root.'/composer.json') ?? [];
        $required = array_merge(
            is_array($composer['require'] ?? null) ? $composer['require'] : [],
            is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [],
        );
        $lock = self::readJson($root.'/composer.lock') ?? [];
        $locked = self::composerPackages($lock['packages'] ?? []) + self::composerPackages($lock['packages-dev'] ?? []);
        $installed = self::composerPackages((self::readJson($root.'/vendor/composer/installed.json') ?? [])['packages'] ?? []);
        $discovered = self::discoveredPackages($root.'/bootstrap/cache/packages.php');
        $vendorReady = is_dir($root.'/vendor') && ! is_link($root.'/vendor');

        $ng = [];
        $notInstalled = [];
        $unused = [];
        $found = [];

        foreach (self::pluginPackages($root) as $name => $plugin) {
            $found[$name] = true;

            if (! isset($required[$name])) {
                $unused[] = $plugin['dir'];

                continue;
            }

            if (! isset($locked[$name])) {
                $ng[] = ["{$name} が composer.lock にありません", "composer update {$name}"];

                continue;
            }

            if ($plugin['version'] !== '' && $locked[$name][0] !== $plugin['version']) {
                $ng[] = ["{$name} の版が composer.lock（{$locked[$name][0]}）と plugins/{$plugin['dir']}（{$plugin['version']}）で違います", "composer update {$name}"];
            }

            if (! $vendorReady) {
                // vendor の形の問題は composer の項目が出す
                continue;
            }

            if (! isset($installed[$name])) {
                $notInstalled[] = $name;

                continue;
            }

            $real = realpath($root.'/vendor/'.$name);
            $expected = realpath($root.'/plugins/'.$plugin['dir']);

            if ($real === false || $real !== $expected) {
                $ng[] = ["vendor/{$name} が plugins/{$plugin['dir']} でなく ".($real === false ? '無い所' : $real).' を指しています（ほかの作業場所の App を読む）', 'rm -r vendor && composer install --no-interaction'];
            }

            if ($discovered !== null && $plugin['providers'] !== [] && array_diff($plugin['providers'], $discovered[$name] ?? []) !== []) {
                $ng[] = ["{$name} の係（ServiceProvider）が Laravel の控え（bootstrap/cache/packages.php）にありません。App が登録されません", 'php artisan package:discover（開発の画面は docker compose exec app php artisan package:discover）'];
            }
        }

        foreach (array_keys($required) as $name) {
            if (is_string($name) && str_starts_with($name, 'ec-sugar/') && ! isset($found[$name])) {
                $ng[] = ["composer.json の {$name} が plugins/ にありません", 'git status で消えたフォルダを確認する'];
            }
        }

        if ($notInstalled !== []) {
            array_unshift($ng, ['vendor に入っていない App とテーマが '.count($notInstalled).' 個（'.self::sample($notInstalled).'）。登録されず、その App の移行も流れない', 'composer install --no-interaction']);
        }

        $findings = [];

        foreach (array_slice($ng, 0, self::MAX_LINES) as [$message, $fix]) {
            $findings[] = [self::NG, $message, $fix];
        }

        if (count($ng) > self::MAX_LINES) {
            $findings[] = [self::NG, 'ほか '.(count($ng) - self::MAX_LINES).' 件', '上と同じ直し方'];
        }

        if ($unused !== []) {
            $findings[] = [self::WARN, 'composer.json で使っていない App のフォルダ: '.self::sample($unused), '使うなら composer require ec-sugar/<名前>'];
        }

        if ($ng === []) {
            $findings[] = [self::OK, 'App とテーマ '.(count($found) - count($unused)).' 個が '.($vendorReady
                ? 'composer.json・lock・vendor・Laravel の控えにそろう'
                : 'composer.json と lock にそろう（vendor は composer の行を先に直す）'), ''];
        }

        return $findings;
    }

    /**
     * 移行のファイルの名前（拡張子なし。migrations 表の migration と同じ形）。central は中央の DB、tenant は店の DB
     * （核の database/migrations/tenant と、composer.json で使う App の database/migrations。PluginRegistry が店の移行に足すもの）
     *
     * @return list<string>
     */
    public static function migrationNames(string $root, string $kind): array
    {
        $directories = match ($kind) {
            'central' => [$root.'/database/migrations'],
            'tenant' => [$root.'/database/migrations/tenant'],
            default => [],
        };

        if ($kind === 'tenant') {
            $composer = self::readJson($root.'/composer.json') ?? [];
            $required = is_array($composer['require'] ?? null) ? $composer['require'] : [];
            $required += is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];

            foreach (self::pluginPackages($root) as $name => $plugin) {
                if (isset($required[$name])) {
                    $directories[] = $root.'/plugins/'.$plugin['dir'].'/database/migrations';
                }
            }
        }

        $names = [];

        foreach ($directories as $directory) {
            foreach (glob($directory.'/*.php') ?: [] as $file) {
                $names[] = basename($file, '.php');
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * plugins/*\/composer.json の名前・フォルダ・版・係
     *
     * @return array<string, array{dir: string, version: string, providers: list<string>}>
     */
    private static function pluginPackages(string $root): array
    {
        $plugins = [];

        foreach (glob($root.'/plugins/*/composer.json') ?: [] as $file) {
            $package = self::readJson($file);

            if (! is_string($package['name'] ?? null)) {
                continue;
            }

            $extra = is_array($package['extra'] ?? null) ? $package['extra'] : [];
            $laravel = is_array($extra['laravel'] ?? null) ? $extra['laravel'] : [];
            $providers = is_array($laravel['providers'] ?? null) ? array_values(array_filter($laravel['providers'], 'is_string')) : [];

            $plugins[$package['name']] = [
                'dir' => basename(dirname($file)),
                'version' => is_string($package['version'] ?? null) ? $package['version'] : '',
                'providers' => $providers,
            ];
        }

        return $plugins;
    }

    /**
     * Laravel の控え（bootstrap/cache/packages.php。package:discover が作る）の、パッケージごとの係。控えが無ければ null（次の起動で作られる）
     *
     * @return array<string, list<string>>|null
     */
    private static function discoveredPackages(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        // 中身は Laravel が書いた配列だけ（手元のファイル）
        $manifest = include $file;
        $out = [];

        foreach (is_array($manifest) ? $manifest : [] as $name => $entry) {
            $providers = is_array($entry) && is_array($entry['providers'] ?? null) ? $entry['providers'] : [];
            $out[(string) $name] = array_values(array_filter($providers, 'is_string'));
        }

        return $out;
    }

    /**
     * composer の lock か installed.json の packages を、名前 => [版, 参照] に
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function composerPackages(mixed $packages): array
    {
        $out = [];

        foreach (is_array($packages) ? $packages : [] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            $dist = is_array($package['dist'] ?? null) ? $package['dist'] : [];
            $source = is_array($package['source'] ?? null) ? $package['source'] : [];
            $reference = $dist['reference'] ?? $source['reference'] ?? '';

            $out[$package['name']] = [is_string($package['version'] ?? null) ? $package['version'] : '', is_string($reference) ? $reference : ''];
        }

        return $out;
    }

    /**
     * npm の lock の packages を、場所（node_modules/…）=> 版 に。根（""）と、版の無いもの（リンク）は除く。
     * $skipOptional は、ほかの OS・CPU 向けで入らないことのある任意の依存（optional）を除くとき
     *
     * @return array<string, string>
     */
    private static function npmPackages(mixed $packages, bool $skipOptional): array
    {
        $out = [];

        foreach (is_array($packages) ? $packages : [] as $path => $package) {
            if ($path === '' || ! is_array($package) || ! is_string($package['version'] ?? null)) {
                continue;
            }

            if ($skipOptional && ($package['optional'] ?? false) === true) {
                continue;
            }

            $out[(string) $path] = $package['version'];
        }

        return $out;
    }

    private static function npmName(string $path): string
    {
        $at = strrpos($path, 'node_modules/');

        return $at === false ? $path : substr($path, $at + strlen('node_modules/'));
    }

    /**
     * @param  list<string>  $items
     */
    private static function sample(array $items): string
    {
        $head = implode('、', array_slice($items, 0, 3));

        return count($items) > 3 ? $head.' ほか' : $head;
    }

    /**
     * @return array<mixed>|null
     */
    private static function readJson(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }
}
