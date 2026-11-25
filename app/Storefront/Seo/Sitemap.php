<?php

declare(strict_types=1);

namespace App\Storefront\Seo;

use App\Core\Catalog\Models\Category;
use App\Core\Plugin\Contracts\ShopPagesProvider;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Section\Models\ShopPage;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Catalog\Catalog;
use DateTimeInterface;
use Throwable;

/**
 * サイトマップ（/sitemap.xml。ADR 0061 の 8）。検索に載せる画面だけ: トップ・すべての商品・カテゴリ・公開中の商品・公開した自由なページ・特商法の表記・プライバシーポリシー（本文がある店だけ。ADR 0094）・
 * 使っている App の検索に載せるページ（ADR 0072。お知らせ・ブログの記事など）。
 * 1 つのファイルの上限（5 万件）まで。商品は新しく直した順に入れ、上限を超えた分は入れない。開いているショップだけ（呼ぶ側が確かめる）
 */
final class Sitemap
{
    public const int MAX_URLS = 50_000;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
        private readonly ShopSettingsService $settings,
    ) {}

    public function xml(): string
    {
        $entries = [
            [route('storefront.home'), null],
            [route('storefront.products'), null],
        ];

        foreach (Category::query()->orderBy('position')->orderBy('id')->get(['slug', 'updated_at']) as $category) {
            $entries[] = [route('storefront.category', ['slug' => $category->slug]), $category->updated_at];
        }

        foreach (ShopPage::query()->where('status', ShopPage::PUBLISHED)->orderBy('position')->orderBy('id')->get(['slug', 'updated_at']) as $page) {
            $entries[] = [route('storefront.page', ['slug' => $page->slug]), $page->updated_at];
        }

        $entries[] = [route('storefront.legal'), null];

        if ($this->settings->hasPrivacyPolicy()) {
            $entries[] = [route('storefront.privacy'), null];
        }

        // App の検索に載せるページ（ADR 0072。お知らせ・ブログなど）。使っている店だけ。App の頭の下の、このショップの URL だけを入れる
        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->shopPages !== null && $this->states->enabled($plugin->name)) {
                $entries = [...$entries, ...$this->appEntries($plugin->shopPages, max(0, self::MAX_URLS - count($entries)))];
            }
        }

        $room = max(0, self::MAX_URLS - count($entries));

        if ($room > 0) {
            // 会員だけ・ランクだけの商品（ADR 0079）は載せない（会員が開いても、ゲストに見せる商品だけ）
            $listings = $this->catalog->asGuest()->published()->orderByDesc('updated_at')->orderBy('id')->limit($room)->toBase()->get(['id', 'updated_at']);

            foreach ($listings as $listing) {
                $entries[] = [route('storefront.product', ['listingId' => (string) $listing->id]), is_string($listing->updated_at) ? new \DateTimeImmutable($listing->updated_at) : null];
            }
        }

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

        foreach ($entries as [$url, $modified]) {
            $lastmod = $modified instanceof DateTimeInterface ? '<lastmod>'.$modified->format('Y-m-d').'</lastmod>' : '';
            $lines[] = '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'.$lastmod.'</url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    /**
     * App の返した URL のうち、このショップの、App の頭の下のものだけ（ほかのホスト・ほかの道筋は捨てる）。App が落ちてもサイトマップは出す
     *
     * @return list<array{0: string, 1: ?DateTimeInterface}>
     */
    private function appEntries(ShopPagesProvider $pages, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        try {
            $items = $pages->sitemap($limit);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $base = rtrim(url('/'), '/').'/'.$pages->prefix();
        $out = [];

        foreach (array_slice($items, 0, $limit) as [$url, $modified]) {
            if ($url === $base || str_starts_with($url, $base.'/') || str_starts_with($url, $base.'?')) {
                $out[] = [$url, $modified];
            }
        }

        return $out;
    }
}
