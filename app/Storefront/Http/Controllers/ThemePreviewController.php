<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Plugin\Services\AppSectionCatalog;
use App\Core\Section\Models\ShopPage;
use App\Core\Section\PageSpec;
use App\Core\Section\Services\ShopPageService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Theme\ThemeRegistry;
use App\Storefront\Access\ThemePreviewLink;
use App\Storefront\Catalog\Catalog;
use App\Storefront\View\SectionResolver;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * テーマの下書きのプレビュー（ADR 0039 区切り 2）。管理画面の編集の画面が作った署名つきの期限つきのリンク（ThemePreviewLink）で、
 * そのテーマのそのページを下書きの区画で描き、編集の画面の枠（iframe）に入れる。
 * 準備中のショップでも関所の外（RequireShopAccess の一覧）。署名が違う・期限切れ・ほかの店・入っていないテーマ・無いページは、
 * 商品のプレビューと同じく、開いているショップでは「見つかりません」、準備中のショップでは「準備中です」へ（中身を出さない。どれで断ったかも見せない）
 */
final class ThemePreviewController
{
    /** テーマの決まった画面に区画を積むページ（ページの名前 => テーマの画面の名前）。自由なページ（page-{ID}）は別に探す */
    private const array PAGES = ['home' => 'home'];

    /** 区画の種類とパターンの名前の形（宣言の決まりと同じ） */
    private const string NAME = '/\A[a-z][a-z0-9_]{0,31}\z/';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly ThemeRegistry $themes,
    ) {}

    public function show(Request $request, StorefrontAccessService $access, ShopPageService $pages, Catalog $catalog, string $theme, string $page): Response|RedirectResponse
    {
        $tenantId = (string) tenant()?->getTenantKey();
        $editor = ThemePreviewLink::editorOrigin();
        $granted = ThemePreviewLink::grants($tenantId, $theme, $page, $request->query('expires'), $request->query('token'))
            && $editor !== null
            && $this->themes->has($theme)
            && $this->themes->get($theme)->schema()->page($page) !== null;
        // 自由なページ（ADR 0039 区切り 3）: 店にあってゴミ箱に入っていないページ（下書きのページも開ける）
        $freeId = PageSpec::freeId($page);
        $freePage = $granted && $freeId !== null ? $pages->find($freeId) : null;
        $granted = $granted && ($freeId === null ? isset(self::PAGES[$page]) : $freePage instanceof ShopPage);

        if (! $granted) {
            $refused = $access->status() === ShopStatus::Open
                ? $this->view->render('error', ['status' => 404], 404)
                : redirect()->route('storefront.password');
            $refused->headers->set('X-Robots-Tag', StorefrontView::NO_INDEX);

            return $refused;
        }

        // 区画の試し見の断片の道筋。期限はこの画面のリンクと同じ（切れたら、編集の画面が作り直したリンクで読み直す）
        $trial = ThemePreviewLink::trialPath($tenantId, $theme, $page, (int) $request->query('expires'));
        // 見た目の下書きの CSS（ADR 0097）の道筋。期限はこの画面のリンクと同じ
        $style = ThemePreviewLink::stylePath($tenantId, $theme, (int) $request->query('expires'));

        return $this->view->renderDraftPreview($this->themes->get($theme), $page, $editor, [
            'listings' => $catalog->featured((int) config('storefront.featured_limit')),
        ], $freePage, $trial, $style);
    }

    /**
     * 区画の試し見の断片。下書きのプレビューの画面に置いた署名つきの道筋に、編集の部品（editor.js）が種類とパターンを足して読む。
     * そのページに置ける種類 × パターンの区画 1 つを、テーマの見本の値で描く（DB には何も書かない）。
     * 署名が違う・期限切れ・ほかの店・入っていないテーマ・無いページ・決まった並びのページ・置けない種類・無いパターンは、
     * どれも中身の無い 404（人が開く画面ではないので、準備中のショップでも「準備中です」へ送らない。どれで断ったかも見せない）。
     * テーマに部品の無い種類（外枠が描くお知らせの帯・フッター）は、描く物の無い印だけを返す
     */
    public function trial(Request $request, ShopPageService $pages, Catalog $catalog, SectionResolver $sections, AppSectionCatalog $apps, string $theme, string $page): Response
    {
        $tenantId = (string) tenant()?->getTenantKey();
        $editor = ThemePreviewLink::editorOrigin();
        $type = $request->query('type');
        $pattern = $request->query('pattern');
        $definition = $this->themes->has($theme) ? $this->themes->get($theme) : null;
        // 店で使う App の区画も試せる（ADR 0040。止めた App の区画は置けないので断る）
        $spec = $definition === null ? null : $apps->schema($definition->schema())->page($page);
        $granted = ThemePreviewLink::grantsTrial($tenantId, $theme, $page, $request->query('expires'), $request->query('token'))
            && $editor !== null && $definition !== null && $spec !== null && ! $spec->fixed
            && is_string($type) && is_string($pattern) && preg_match(self::NAME, $type) === 1 && preg_match(self::NAME, $pattern) === 1;
        $freeId = PageSpec::freeId($page);
        $freePage = $granted && $freeId !== null ? $pages->find($freeId) : null;
        $granted = $granted && ($freeId === null ? isset(self::PAGES[$page]) : $freePage instanceof ShopPage);
        $section = $granted && $definition !== null && $spec !== null ? $sections->trial($definition, $spec, (string) $type, (string) $pattern) : null;

        if ($definition === null || $editor === null || $section === null) {
            return response('', 404, ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => StorefrontView::NO_INDEX]);
        }

        return $this->view->renderSectionTrial($definition, $page, $editor, $section, [
            'listings' => $catalog->featured((int) config('storefront.featured_limit')),
        ], $freePage);
    }
}
