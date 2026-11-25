<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Section\Models\ShopPage;
use App\Core\Section\Services\ShopPageService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Catalog\Catalog;
use App\Storefront\View\PrivacyPolicyView;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\Response;

/**
 * 店頭の固定の画面（トップ・特定商取引法に基づく表記・プライバシーポリシー・見つからない）と、自由なページ（ADR 0039 区切り 3）。
 */
final class PageController
{
    public function __construct(private readonly StorefrontView $view) {}

    public function home(Catalog $catalog): Response
    {
        return $this->view->render('home', [
            'listings' => $catalog->featured((int) config('storefront.featured_limit')),
        ]);
    }

    /**
     * 自由なページ。公開していて、ゴミ箱に入っていないページだけ（下書き・無いページは「見つかりません」。どちらで断ったかは見せない）
     */
    public function page(ShopPageService $pages, string $slug): Response
    {
        $page = strlen($slug) > ShopPage::SLUG_MAX ? null : $pages->findPublished($slug);

        return $page === null ? $this->missing() : $this->view->renderFreePage($page);
    }

    public function legal(): Response
    {
        return $this->view->render('legal');
    }

    /**
     * プライバシーポリシー（ADR 0094）。本文が無い店では「見つかりません」（空のページを出さない。フッターのリンクも出ない）
     */
    public function privacy(ShopSettingsService $settings): Response
    {
        $text = $settings->privacyPolicy();

        return $text === null ? $this->missing() : $this->view->renderPrivacy(PrivacyPolicyView::fromText($text, route('storefront.privacy', [], false)));
    }

    public function missing(): Response
    {
        return $this->view->render('error', ['status' => 404], 404);
    }
}
