<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeStyleSheet;

/**
 * 店が選んだ見た目（ADR 0097）をテーマの外枠に渡す形。テーマには $themeStyle で渡し、核の部品 <x-storefront::theme-style /> が読む。
 *
 * - href: テーマの CSS の後に読む小さな CSS（`/theme/style-{印}.css`）。テーマの CSS のままでよい（最初の組で主な色も組のまま）・
 *   見た目を宣言しないテーマは null（link を出さない）。下書きのプレビューでは署名つきの下書きの CSS の道筋を必ず置く（編集の画面が読み直す）
 * - font・radius: 選んだ字の組と角の丸みの名前（宣言しないテーマは空）。テーマは外枠の <html> に
 *   `data-sugar-font="{{ $themeStyle->font }}" data-sugar-radius="{{ $themeStyle->radius }}"` と書き、CSS の [data-sugar-font="…"] で受ける
 */
final readonly class ThemeStyleView
{
    public function __construct(
        public ?string $href = null,
        public string $font = '',
        public string $radius = '',
        public bool $preview = false,
    ) {}

    /**
     * この要求で描く画面の見た目（StorefrontView が要求に置く。核の部品 <x-storefront::theme-style /> が読む）。画面を描く前は null
     */
    public static function current(): ?self
    {
        $view = request()->attributes->get(self::class);

        return $view instanceof self ? $view : null;
    }

    /**
     * 店で使うテーマの見た目の値の CSS（null なら宣言が無い）。$draft なら使うバージョンの下書き、ほかは公開した値
     */
    public static function sheet(ThemeContentService $content, ThemeDefinition $theme, bool $draft): ?ThemeStyleSheet
    {
        if ($theme->style === null) {
            return null;
        }

        $values = $draft
            ? $content->draftStyle($content->current($theme->name), $theme->style)
            : $content->publishedStyle($theme->name, $theme->style);

        return new ThemeStyleSheet($theme->style, $values);
    }

    /**
     * ふつうの画面（公開した値）。下書きのプレビューでは、$previewPath（署名つきの下書きの CSS）を必ず置く
     */
    public static function of(?ThemeStyleSheet $sheet, ?string $previewPath = null): self
    {
        if ($sheet === null) {
            return new self;
        }

        $href = $previewPath ?? ($sheet->isDefault() ? null : route('storefront.theme.style', ['mark' => $sheet->mark()], false));

        return new self($href, $sheet->values['font'] ?? '', $sheet->values['radius'] ?? '', $previewPath !== null);
    }
}
