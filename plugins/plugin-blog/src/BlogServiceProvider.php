<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginBlog\Http\BlogController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「お知らせ・ブログ」（ADR 0072）。プラグインの登録簿に自分を登録する。
 * 表（記事と種類）は店の DB に。ショップは /news（一覧・記事・Atom。検索に載せるページの口）と、管理画面からのプレビュー（/p/blog/preview/{ID}。署名つき）、
 * ページビルダーの区画「新着のお知らせ」（ADR 0040 の口と、店のデータを並べる区画）。管理画面は App の画面（一覧・1 件・書く・直す・公開・予約・取り下げ・消す。ADR 0051）。
 * どれも使う店だけで動き、止めても記事は消さない
 */
final class BlogServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'blog');

        $plugins->register(new PluginDefinition(
            name: 'blog',
            title: 'お知らせ・ブログ',
            description: 'ショップに「お知らせ」のページ（/news）を作り、新商品・お休みの案内・読み物の記事を載せます。日時を予約して公開することもでき、トップなどに新着のお知らせを置けます。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（目的 category と、区画「新着のお知らせ」）
            manifest: __DIR__.'/../composer.json',
            sectionHandler: new BlogSections,
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                // 管理画面からのプレビュー（署名と期限と店の ID。下書き・予約の記事も）
                Route::get('/preview/{postId}', [BlogController::class, 'preview'])->where('postId', '[0-9A-Za-z]{26}')->middleware('secret-link')->name('preview');
                // 有料の記事でログインへ送った後に、その記事へ戻る道（会員のログインの戻り先は App の道筋だけ）
                Route::get('/read/{postId}', [BlogController::class, 'read'])->where('postId', '[0-9A-Za-z]{26}')->name('read');
            },
            adminPage: new BlogAdminPage,
            shopPages: new BlogShopPages,
        ));
    }
}
