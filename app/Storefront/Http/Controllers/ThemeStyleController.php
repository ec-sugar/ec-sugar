<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\ThemePreviewLink;
use App\Storefront\View\ThemeStyleView;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 店が選んだ見た目の小さな CSS（ADR 0097）。テーマの CSS の後に <link> で読む（インラインの style は使わない。CSP は緩めない）。
 *
 * - 公開: `/theme/style-{印}.css`。店で使うテーマの公開した値から作り、印（中身のハッシュ）が今の中身と合うときだけ返す。
 *   中身が変わると URL が変わるので、長くキャッシュさせる（immutable）。合わない・宣言の無いテーマは中身の無い 404（残さない）
 * - 下書き（編集の画面の真ん中のプレビュー）: `/preview/themes/{テーマ}/style.css?expires=…&token=…`。署名（店・テーマ・期限。用途の違う鍵）が
 *   合うときだけ、そのテーマの使うバージョンの下書きの値で返す。テーマの CSS のままでよい値なら空の CSS。残さない（秘密の道筋の宣言が no-store を掛ける）
 * - 中身は ThemeStyleSheet が作る `:root{--color-…:#rrggbb;…}` だけ（店の人の文字は入らない）
 */
final class ThemeStyleController
{
    private const string TYPE = 'text/css; charset=utf-8';

    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ThemeContentService $content,
    ) {}

    public function published(string $mark): Response
    {
        $sheet = ThemeStyleView::sheet($this->content, $this->themes->forTenant(Tenant::current()), draft: false);

        if ($sheet === null || ! hash_equals($sheet->mark(), $mark)) {
            return response('', 404, ['Cache-Control' => 'no-store']);
        }

        return response($sheet->css(), 200, ['Content-Type' => self::TYPE, 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    public function preview(Request $request, string $theme): Response
    {
        $granted = ThemePreviewLink::grantsStyle((string) tenant()?->getTenantKey(), $theme, $request->query('expires'), $request->query('token'))
            && $this->themes->has($theme);
        $sheet = $granted ? ThemeStyleView::sheet($this->content, $this->themes->get($theme), draft: true) : null;

        if ($sheet === null) {
            return response('', 404);
        }

        return response($sheet->isDefault() ? '' : $sheet->css(), 200, ['Content-Type' => self::TYPE]);
    }
}
