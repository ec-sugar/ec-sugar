<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Sample\Demo\DemoShop;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Storefront\Seo\Sitemap;
use Illuminate\Http\Response;

/**
 * 検索エンジン向けの robots.txt とサイトマップ（ADR 0061 の 8・9）。店ごとに核が返す（public/robots.txt は持たない）。
 * 準備中のショップとデモの店（ADR 0086）は robots.txt で全部を止め、サイトマップは出さない（404）
 */
final class SeoController
{
    /**
     * 検索エンジンに回らせない道筋（開いているショップ）。カート・注文・マイページ・パスワード・同意・プレビュー・郵便番号・App の公開の経路（/p/）。
     * 画面にも noindex を付けている（ここは回る手間を省くため）
     */
    public const array DISALLOW = ['/cart', '/checkout', '/orders', '/account', '/password', '/consent', '/preview/', '/postal/', '/p/'];

    public function __construct(private readonly StorefrontAccessService $access) {}

    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        if ($this->access->status() === ShopStatus::Open && ! DemoShop::active()) {
            foreach (self::DISALLOW as $path) {
                $lines[] = "Disallow: {$path}";
            }

            $lines[] = '';
            $lines[] = 'Sitemap: '.route('storefront.sitemap');
        } else {
            $lines[] = 'Disallow: /';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-cache']);
    }

    public function sitemap(Sitemap $sitemap): Response
    {
        if ($this->access->status() !== ShopStatus::Open || DemoShop::active()) {
            return response('', 404, ['X-Robots-Tag' => 'noindex, nofollow']);
        }

        return response($sitemap->xml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-cache']);
    }
}
