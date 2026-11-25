<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Plugin\Manifest\PackageManifest;
use App\Core\Version;
use Composer\Semver\Semver;
use UnexpectedValueException;

/**
 * 入っているプラグインとテーマの一覧。composer が入れたときに残す記録（vendor/composer/installed.json）から、
 * マニフェスト（extra.ec-sugar。旧名のころのキー extra.sugar-ec も読む。PackageManifest）のあるパッケージだけを読む。本番ではイメージの中の読み取り専用のファイルで、Web の処理からは変えられない（ADR 0006）。
 * 記録が無い・壊れている・マニフェストがおかしいときも例外にせず、分かったことを problem に入れて返す（一覧の画面を落とさない）
 */
final class InstalledPackages
{
    private const int MAX_TEXT = 300;

    private const int MAX_AUTHORS = 5;

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path('vendor/composer/installed.json');
    }

    /**
     * @return list<InstalledPackage> 種類（テーマ・プラグイン）と名前の順
     */
    public function all(): array
    {
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;
        $data = $raw === false ? null : json_decode($raw, true, 64);
        $packages = is_array($data) && is_array($data['packages'] ?? null) ? $data['packages'] : [];
        $found = [];

        foreach ($packages as $package) {
            $manifest = PackageManifest::of($package);

            if (is_array($package) && is_string($package['name'] ?? null) && is_array($manifest)) {
                $found[] = $this->read($package, $manifest);
            }
        }

        $order = ['theme' => 0, 'plugin' => 1];
        usort($found, static fn (InstalledPackage $a, InstalledPackage $b): int => [$order[$a->kind ?? ''] ?? 2, $a->name ?? $a->package] <=> [$order[$b->kind ?? ''] ?? 2, $b->name ?? $b->package]);

        return $found;
    }

    /**
     * @param  array<mixed>  $package
     * @param  array<mixed>  $manifest
     */
    private function read(array $package, array $manifest): InstalledPackage
    {
        $problems = [];
        $kind = in_array($manifest['type'] ?? null, ['theme', 'plugin'], true) ? $manifest['type'] : null;
        $name = is_string($manifest['name'] ?? null) && preg_match('/\A[a-z][a-z0-9-]{1,31}\z/', $manifest['name']) === 1 ? $manifest['name'] : null;
        $core = is_string($manifest['core'] ?? null) ? self::text($manifest['core'], 64) : null;
        $compatible = false;

        if ($kind === null) {
            $problems[] = '種類（type）が theme でも plugin でもありません';
        }

        if ($name === null) {
            $problems[] = '名前（name）が無いか、形が違います（英小文字・数字・ハイフン）';
        }

        if ($core === null) {
            $problems[] = '対応する本体のバージョン（core）が書かれていません';
        } else {
            try {
                $compatible = Semver::satisfies(Version::CORE, $core);
            } catch (UnexpectedValueException) {
                $problems[] = '対応する本体のバージョン（core）の書き方が違います';
            }
        }

        $authors = [];

        foreach (is_array($package['authors'] ?? null) ? $package['authors'] : [] as $author) {
            if (is_array($author) && is_string($author['name'] ?? null) && count($authors) < self::MAX_AUTHORS) {
                $authors[] = self::text($author['name'], 100);
            }
        }

        $licenses = array_values(array_map(static fn (string $license): string => self::text($license, 64), array_filter((array) ($package['license'] ?? []), is_string(...))));

        return new InstalledPackage(
            package: self::text($package['name'], 200),
            kind: $kind,
            name: $name,
            version: is_string($package['version'] ?? null) ? self::text($package['version'], 64) : '?',
            description: is_string($package['description'] ?? null) ? self::text($package['description'], self::MAX_TEXT) : null,
            authors: $authors,
            licenses: $licenses,
            core: $core,
            compatible: $compatible,
            // 自社: ec-sugar/ の名前で、このリポジトリの plugins/ から入ったもの（名前だけでは名乗れる）。旧名の sugar-ec/ は自社と見ない
            firstParty: str_starts_with($package['name'], 'ec-sugar/') && ($package['dist']['type'] ?? null) === 'path' && str_starts_with((string) ($package['dist']['url'] ?? ''), 'plugins/'),
            problem: $problems === [] ? null : implode('。', $problems).'。',
        );
    }

    private static function text(string $value, int $max): string
    {
        // 制御文字を除いて、長すぎる文は切る
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, $max);
    }
}
