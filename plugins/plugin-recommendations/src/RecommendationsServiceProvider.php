<?php

declare(strict_types=1);

namespace EcSugar\PluginRecommendations;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginRecommendations\Console\RankCommand;
use EcSugar\PluginRecommendations\Http\RememberViewed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「おすすめの区画」（ADR 0071）。プラグインの登録簿に自分を登録する。
 * ページビルダーに区画を 3 つ足す（ADR 0040 の口。関連商品・最近見た商品・売れ筋ランキング。外から埋め込まない区画）。
 * 並べる商品は部品の composer（RecommendationsComposer）が読む。最近見た商品は、商品の画面を開いた時にショップのミドルウェア（RememberViewed）が
 * お客さんのブラウザの Cookie に ID だけを書く（お店には残さない）。売れ筋は 1 時間ごとの recommendations:rank が数えて表に持つ
 */
final class RecommendationsServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        // 区画の部品は核が app-recommendations の名前で読む（ADR 0040）。テーマが描き直す同じ名前の部品にも同じ商品を渡す
        // （1 つの形で両方に当たる。形を 2 つ並べると、App の部品で composer が 2 度動く）
        View::composer('*::sections.app_recommendations_*', RecommendationsComposer::class);

        // ショップのミドルウェアの群の終わり（Cookie の暗号化の内側）に、商品の画面を開いた記録を Cookie に書く係を足す
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('storefront', RememberViewed::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RankCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('recommendations:rank')->hourlyAt(45)->withoutOverlapping(60);
        });

        $plugins->register(new PluginDefinition(
            name: 'recommendations',
            title: 'おすすめのセクション',
            description: 'ページの編集の画面に「関連商品」「最近見た商品」「売れ筋ランキング」のセクションを追加できます。並ぶのは、ショップで公開中の、いま購入できる商品だけです。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
        ));
    }
}
