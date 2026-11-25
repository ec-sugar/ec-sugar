<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog;

use App\Core\Plugin\Contracts\ShopPagesProvider;
use EcSugar\PluginBlog\Http\BlogController;
use EcSugar\PluginBlog\Models\BlogPost;
use Illuminate\Support\Facades\Route;

/**
 * ショップの /news（ADR 0072）。一覧（種類で絞る・20 件ずつ）・記事・Atom。核が storefront・public・plugin:blog の印を付けて /news の下に置く。
 * サイトマップに載せるのは、無料で読める分がある記事だけ。
 * 記事の URL の名前に使えない言葉（feed など）は BlogPosts::RESERVED_SLUGS
 */
final class BlogShopPages implements ShopPagesProvider
{
    public const string PREFIX = 'news';

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function routes(): void
    {
        Route::get('/', [BlogController::class, 'index'])->name('index');
        // Atom はサイトマップと同じ回数の枠（読む機械が回る道）
        Route::get('/feed', [BlogController::class, 'feed'])->middleware('throttle:storefront')->name('feed');
        Route::get('/{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9]+(-[a-z0-9]+)*')->name('show');
    }

    public function sitemap(int $limit): array
    {
        // 一覧（最後に変えた日は、出ている記事のいちばん新しい直した日）と、出ている記事（新しい順）。
        // 有料の記事は**無料の分があるものだけ**載せる（無料の分は検索の入口にする。守りは攻撃に向け、手間はお客さんに掛けない）。
        // 全部有料の記事は、検索の機械に出せる中身が無いので載せない（BlogPost::withFreeContent）
        $latest = BlogPost::withFreeContent(BlogPost::shown())->orderByDesc('updated_at')->first(['updated_at']);
        $entries = [[route('storefront.plugin.blog.pages.index'), $latest?->updated_at]];

        foreach (BlogPost::withFreeContent(BlogPost::shown())->orderByDesc('published_at')->orderByDesc('id')->limit(max(0, $limit - 1))->get(['slug', 'updated_at']) as $post) {
            $entries[] = [route('storefront.plugin.blog.pages.show', ['slug' => $post->slug]), $post->updated_at];
        }

        return array_slice($entries, 0, $limit);
    }
}
