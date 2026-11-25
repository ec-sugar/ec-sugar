<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Core\Theme\Services\StaleThemeVersionException;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use App\Core\Theme\ThemeStyle;
use App\Core\Theme\ThemeStyleSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * テーマの見た目（色の組・主な色・字の組・角の丸み。ADR 0097。EnterTenant の後）: 宣言と下書きの値を読む・下書きに保存する。
 * 値は区画の並びと同じバージョンの下書きに入り、「公開する」（…/pages/{ページ}/publish。バージョンごと）でいっしょにショップに出る。
 * 読むのは閲覧、変えるのは運用（EnterTenant の既定。テーマの中身と同じ）。
 *
 * - GET /themes/{テーマ}/style: { style: 宣言（見た目を宣言しないテーマは null）, values: 下書きの値, warnings: 読みにくいかもしれない組み合わせ, version }
 * - PUT /themes/{テーマ}/style/draft: { version_id, revision, style: { palette, accent, font, radius } }。宣言に無い名前・形の違う色は 422
 *   （style.palette など。何も保存しない）、古い改訂は 409。読みにくい組み合わせは保存して warnings で知らせる（止めない）
 */
final class ThemeStyleController
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ThemeContentService $content,
    ) {}

    public function show(string $theme): JsonResponse
    {
        return response()->json($this->present($this->theme($theme)));
    }

    public function update(Request $request, string $theme): JsonResponse
    {
        $definition = $this->theme($theme);

        if ($definition->style === null) {
            $message = 'このテーマには、選べる色やフォントがありません。';

            return response()->json(['message' => $message, 'user_message' => $message], 422);
        }

        $data = $request->validate([
            'version_id' => ['present', 'nullable', 'ulid'],
            'revision' => ['required', 'integer', 'min:0'],
            // 中の形は核（ThemeStyle::validate）がテーマの宣言どおりに確かめる
            'style' => ['required', 'array'],
        ]);

        try {
            $this->content->saveStyle($definition->name, $definition->style, $data['version_id'], (int) $data['revision'], $data['style'], TenantActor::of($request));
        } catch (StaleThemeVersionException $e) {
            return response()->json([
                'message' => '別の操作で先に更新されています。最新の内容を読み込み直してから、もう一度保存してください。',
                'version_id' => $e->versionId,
                'current_revision' => $e->currentRevision,
            ], 409);
        }

        return response()->json($this->present($definition));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ThemeDefinition $theme): array
    {
        $version = $this->content->current($theme->name);
        $style = $theme->style;

        return [
            'style' => $style?->toArray(),
            'values' => $style === null ? null : $this->content->draftStyle($version, $style),
            'warnings' => $style === null ? [] : $this->warnings($style, $this->content->draftStyle($version, $style)),
            'version' => $version === null ? null : ThemeContentController::version($version),
        ];
    }

    /**
     * @param  array{palette: string, accent: string|null, font: string|null, radius: string|null}  $values
     * @return list<array{code: string, message: string}>
     */
    private function warnings(ThemeStyle $style, array $values): array
    {
        return array_map(static fn (array $w): array => ['code' => $w['code'], 'message' => $w['message']], (new ThemeStyleSheet($style, $values))->warnings());
    }

    private function theme(string $name): ThemeDefinition
    {
        abort_unless($this->themes->has($name), 404);

        return $this->themes->get($name);
    }
}
