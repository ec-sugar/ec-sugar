<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites;

use App\Core\Party\Events\CustomerLeft;
use App\Core\Plugin\AccountLink;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginFavorites\Console\RunFavoritesCommand;
use EcSugar\PluginFavorites\Http\FavoritesController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「お気に入り」（ADR 0071）。プラグインの登録簿に自分を登録する。
 * 表（会員の印・知らせの選択・前の日の値段・送った知らせ）は店の DB に。ショップは商品の画面と一覧の札のハート（核の買い方の口と札の口）、
 * マイページの入口と /p/favorites/… の画面。管理画面は App の画面（お気に入りの多い商品の順位。数だけ。ADR 0051）。
 * 値下げ・再入荷の知らせは毎日の favorites:notify が積み、送る仕事が 1 通ずつ送る（お知らせのメールの受け取りに同意した会員だけ。核の口 MarketingConsent）
 */
final class FavoritesServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'favorites');

        // 退会したら、その会員の印・選択・知らせの記録を消す（退会と同じトランザクション）
        Event::listen(CustomerLeft::class, static fn (CustomerLeft $e) => app(Favorites::class)->leave($e->accountId));

        if ($this->app->runningInConsole()) {
            $this->commands([RunFavoritesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('favorites:notify')->dailyAt('10:15')->timezone('Asia/Tokyo')->withoutOverlapping(120);
        });

        $plugins->register(new PluginDefinition(
            name: 'favorites',
            title: 'お気に入り',
            description: '会員のお客さんが、商品の画面と一覧でハートを押してお気に入りに入れ、マイページで見返せます。値下げ・再入荷のお知らせも選べます（お知らせのメールに登録している方だけ）。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            purchaseOptions: new FavoriteButton,
            accountLink: new AccountLink('favorites', 'お気に入り', '/p/favorites/list'),
            adminPage: new FavoritesAdminPage,
            storefrontRoutes: static function (): void {
                $token = '[A-Za-z0-9_-]{43}';
                $listing = '[0-9A-Za-z]{26}';
                // マイページの一覧（会員だけ。ほかはログインへ）と、ログインから戻る道（足すのはセッションに覚えた商品だけ）。
                // 一覧と知らせの選択は、ブラウザが画面を開く時・画面のフォームから送る時だけ（ADR 0088）。ハートはタグのある商品の画面の toggles.js が送るので印を付けない
                Route::get('/list', [FavoritesController::class, 'index'])->middleware('navigation-only')->name('list');
                Route::get('/back/{listingId}', [FavoritesController::class, 'back'])->where('listingId', $listing)->name('back');
                // 配信の停止の画面（開くだけでは変えない。押して変える）と、押す（画面のボタンと、メールのソフトのワンクリック〔RFC 8058〕）。
                // メールのソフトは CSRF の合い言葉を持たないので、この 1 つだけ CSRF の確かめを外す（合い言葉は 256 ビットの乱数で、できるのは止めることだけ）
                Route::get('/unsubscribe/{token}', [FavoritesController::class, 'showUnsubscribe'])->where('token', $token)->middleware('secret-link')->name('unsubscribe');
                Route::post('/unsubscribe/{token}', [FavoritesController::class, 'unsubscribe'])->where('token', $token)->middleware('secret-link')->name('unsubscribe.submit')
                    ->withoutMiddleware(ValidateCsrfToken::class);
                // ハートと知らせの選択は、接続元ごとに 1 分に 60 回まで（カートの書き込みと同じ枠）
                Route::middleware('throttle:storefront-cart')->group(function () use ($listing): void {
                    Route::post('/settings', [FavoritesController::class, 'settings'])->middleware('navigation-only')->name('settings');
                    Route::post('/{listingId}', [FavoritesController::class, 'toggle'])->where('listingId', $listing)->name('toggle');
                });
            },
        ));
    }
}
