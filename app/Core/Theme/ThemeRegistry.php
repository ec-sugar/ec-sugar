<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Section\InvalidSectionSchemaException;
use App\Core\Section\SectionSchema;
use App\Models\Tenant;
use InvalidArgumentException;
use JsonException;

/**
 * 入っているテーマの登録簿。テーマ API のバージョンが合わないテーマは受け付けない（更新でテーマが壊れたまま動かさない）。
 */
final class ThemeRegistry
{
    // 店頭がテーマに約束していること（画面の名前と渡すデータの形）のバージョン。変えるときは docs/design/theme-api.md と一緒に上げる
    // 2 は区画の宣言（theme.json。ADR 0028）。1（店の設定の見せ方の項目を読む形）は区切り 2c でやめた
    public const int API_VERSION = 2;

    /** theme.json の大きさの上限（バイト） */
    private const int MAX_MANIFEST_BYTES = 65536;

    /** 見本の絵（theme.json の screenshot）の大きさの上限（バイト） */
    public const int MAX_SCREENSHOT_BYTES = 524288;

    /**
     * 見本の絵のファイルの名前の形。theme.json と同じフォルダの中のファイルだけ（/ や .. を書けないので、外のファイルを指せない）。
     * 拡張子で種類を決める（配るときの Content-Type。中身の頭の印も配るときに確かめる）
     */
    public const string SCREENSHOT_NAME = '/\A[a-z0-9][a-z0-9_-]{0,63}\.(png|jpg|jpeg|webp)\z/';

    /** @var array<string, ThemeDefinition> */
    private array $themes = [];

    public function register(ThemeDefinition $theme): void
    {
        if (preg_match('/\A[a-z][a-z0-9-]{1,31}\z/', $theme->name) !== 1) {
            throw new InvalidArgumentException("テーマの名前が不正です: {$theme->name}");
        }

        if ($theme->themeApi !== self::API_VERSION) {
            throw new InvalidArgumentException("テーマ {$theme->name} はテーマ API {$theme->themeApi} 用です（店頭は ".self::API_VERSION.'）');
        }

        if (isset($this->themes[$theme->name])) {
            throw new InvalidArgumentException("テーマが二重に登録されています: {$theme->name}");
        }

        $this->themes[$theme->name] = $this->withManifest($theme);
    }

    /**
     * theme.json を読み、区画の宣言を確かめて入れる（形が違えば登録しない）。
     * theme.json はテーマのパッケージの中のデータで、コードではない（ADR 0028。2026-10-29）
     */
    private function withManifest(ThemeDefinition $theme): ThemeDefinition
    {
        $path = $theme->manifest ?? throw new InvalidArgumentException("テーマ {$theme->name}: theme.json を持つ（セクションの宣言。ADR 0028）");
        $size = is_file($path) ? filesize($path) : false;

        if ($size === false || $size > self::MAX_MANIFEST_BYTES) {
            throw new InvalidArgumentException("テーマ {$theme->name}: theme.json が無いか、大きすぎます");
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("テーマ {$theme->name}: theme.json を読めません（{$e->getMessage()}）", previous: $e);
        }

        if (! is_array($manifest) || array_is_list($manifest) || array_diff(array_map('strval', array_keys($manifest)), ['theme_api', 'sections', 'pages', 'motion', 'screenshot', 'style']) !== []) {
            throw new InvalidArgumentException("テーマ {$theme->name}: theme.json に書けるのは theme_api・sections・pages・motion・screenshot・style だけです");
        }

        if (($manifest['theme_api'] ?? null) !== $theme->themeApi) {
            throw new InvalidArgumentException("テーマ {$theme->name}: theme.json の theme_api が登録と違います");
        }

        try {
            // pages（ページに置ける区画と最初の並び。ADR 0039）は書かなくてよい。書かなければ決まった並びのトップとして読む
            $sections = SectionSchema::fromManifest(
                is_array($manifest['sections'] ?? null) ? $manifest['sections'] : throw new InvalidSectionSchemaException('sections', 'セクションの宣言が要る'),
                $manifest['pages'] ?? null,
            );
            // 店の人が選べる見た目（ADR 0097。書かなくてよい。書かなければ何も選べない）
            $style = array_key_exists('style', $manifest) ? ThemeStyle::fromManifest($manifest['style']) : null;
        } catch (InvalidSectionSchemaException $e) {
            throw new InvalidArgumentException("テーマ {$theme->name}: theme.json の {$e->getMessage()}", previous: $e);
        }

        // 動きの強さ（書かなければ控えめ）。決まった値のほかは書き間違いとして登録で止める（外枠の data-motion に出す値を絞る）
        $motion = array_key_exists('motion', $manifest) ? $manifest['motion'] : ThemeDefinition::MOTION_DEFAULT;

        if (! in_array($motion, ThemeDefinition::MOTIONS, true)) {
            throw new InvalidArgumentException("テーマ {$theme->name}: theme.json の motion は ".implode('・', ThemeDefinition::MOTIONS).' のどれかです');
        }

        return $theme->withManifest($sections, $motion, self::screenshot($theme, $path, $manifest['screenshot'] ?? null), $style);
    }

    /**
     * 見本の絵（theme.json の screenshot。書かなくてよい）。theme.json と同じフォルダの中の png・jpg・webp のファイルの名前で書く。
     * 形が違う・無い・大きすぎるときは登録で止める（書き間違いのまま、管理画面で絵が出ないことに気づかないのを防ぐ）
     */
    private static function screenshot(ThemeDefinition $theme, string $manifest, mixed $name): ?string
    {
        if ($name === null) {
            return null;
        }

        if (! is_string($name) || preg_match(self::SCREENSHOT_NAME, $name) !== 1) {
            throw new InvalidArgumentException("テーマ {$theme->name}: theme.json の screenshot は、theme.json と同じフォルダの png・jpg・webp のファイルの名前で書きます（英小文字・数字・_・-）");
        }

        // 名前に / を書けないので同じフォルダの中だが、置いたものがシンボリックリンクで外を指していても断る
        $folder = realpath(dirname($manifest));
        $path = realpath(dirname($manifest).'/'.$name);
        $size = $folder !== false && $path !== false && dirname($path) === $folder && is_file($path) ? filesize($path) : false;

        if ($path === false || $size === false || $size === 0 || $size > self::MAX_SCREENSHOT_BYTES) {
            throw new InvalidArgumentException("テーマ {$theme->name}: 見本の絵 {$name} が無いか、大きすぎます（".(self::MAX_SCREENSHOT_BYTES / 1024).'KB まで）');
        }

        return $path;
    }

    public function get(string $name): ThemeDefinition
    {
        return $this->themes[$name] ?? throw new InvalidArgumentException("入っていないテーマです: {$name}");
    }

    /**
     * 全店の既定のテーマ（config('storefront.theme')）
     */
    public function active(): ThemeDefinition
    {
        return $this->get((string) config('storefront.theme'));
    }

    /**
     * この店のテーマ（テナントの data.theme）。未設定か、入っていないテーマ（外したテーマなど）なら既定のテーマで描く
     */
    public function forTenant(Tenant $tenant): ThemeDefinition
    {
        $name = $tenant->getAttribute('theme');

        return is_string($name) && isset($this->themes[$name]) ? $this->themes[$name] : $this->active();
    }

    public function has(string $name): bool
    {
        return isset($this->themes[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->themes);
    }
}
