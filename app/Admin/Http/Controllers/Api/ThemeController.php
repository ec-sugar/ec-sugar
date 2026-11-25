<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Core\Plugin\InstalledPackages;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 管理画面の「デザイン」のテーマの画面（上に今のテーマの大きな札、下に入っているテーマの札の並び）が読むもの。
 *
 * - index（EnterTenant の後。閲覧の段階から）: 入っているテーマの札（名前・バージョン・見本の絵）と、この店が使っているテーマ、
 *   テーマごとの中身の状態（公開した日時・公開していない変更があるか）。選び替えは …/shop-settings/theme、公開は …/pages/home/publish のまま
 * - screenshot（ログイン中の運営なら誰でも。店をまたがない、テーマのパッケージの中の静的な絵）: theme.json の screenshot を配る。
 *   ファイルは登録の時に theme.json と同じフォルダの中だけに絞ってあり、ここでは大きさと中身の頭の印（png・jpg・webp）を確かめてから返す
 */
final class ThemeController
{
    /** 拡張子 => Content-Type（拡張子は登録の時に png・jpg・jpeg・webp に絞ってある） */
    private const array IMAGE_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ThemeContentService $content,
    ) {}

    public function index(InstalledPackages $installed): JsonResponse
    {
        $tenant = Tenant::current();
        $chosen = $tenant->getAttribute('theme');
        // テーマのパッケージのバージョン（composer の記録から。テストの見本のテーマのように、パッケージで入っていなければ null）
        $versions = [];

        foreach ($installed->all() as $package) {
            if ($package->kind === 'theme' && $package->name !== null) {
                $versions[$package->name] = $package->version;
            }
        }

        return response()->json([
            // 店が選んだテーマ（選んでいない・入っていなければ null。そのときは既定のテーマで描く）
            'chosen' => is_string($chosen) && $this->themes->has($chosen) ? $chosen : null,
            'default' => $this->themes->active()->name,
            'active' => $this->themes->forTenant($tenant)->name,
            'themes' => array_map(fn (string $name): array => $this->present($this->themes->get($name), $versions[$name] ?? null), $this->themes->names()),
        ]);
    }

    public function screenshot(string $theme): BinaryFileResponse
    {
        abort_unless($this->themes->has($theme), 404);
        $path = $this->themes->get($theme)->screenshot ?? abort(404);
        $type = self::IMAGE_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? abort(404);
        $size = is_file($path) ? filesize($path) : false;

        // 登録の後にファイルが差し替わっていても、絵でないもの・大きすぎるものは配らない
        abort_if($size === false || $size === 0 || $size > ThemeRegistry::MAX_SCREENSHOT_BYTES || ! self::looksLike($type, (string) file_get_contents($path, length: 12)), 404);

        return response()->file($path, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            // 札の URL に中身の印（?v=）を付けているので、長めに持たせてよい（テーマを更新すれば URL が変わる）
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ThemeDefinition $theme, ?string $version): array
    {
        $current = $this->content->current($theme->name);

        return [
            'name' => $theme->name,
            'label' => $theme->label,
            'version' => $version,
            'screenshot' => $theme->screenshot === null ? null : '/api/themes/'.$theme->name.'/screenshot?v='.self::stamp($theme->screenshot),
            // 中身のバージョン（まだ保存していなければ null。ショップにはテーマの見本の文と店の名前が出ている）
            'content' => $current === null ? null : [
                'version_id' => $current->id,
                'revision' => $current->revision,
                'published_at' => $current->published_at?->toIso8601String(),
                'unpublished_changes' => $current->published === null || $current->published !== $current->draft,
            ],
        ];
    }

    /**
     * 絵の URL に付ける印（ファイルの大きさと更新の時刻から。中身そのものは読まない）
     */
    private static function stamp(string $path): string
    {
        return substr(hash('xxh3', (string) @filesize($path).':'.(string) @filemtime($path)), 0, 12);
    }

    private static function looksLike(string $type, string $head): bool
    {
        return match ($type) {
            'image/png' => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            'image/jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'image/webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            default => false,
        };
    }
}
