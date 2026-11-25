<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks;

use App\Core\Plugin\AccountLink;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginMemberRanks\Console\RunRanksCommand;
use EcSugar\PluginMemberRanks\Http\RankController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「会員ランク」（ADR 0079）。プラグインの登録簿に自分を登録する。
 * 表（会員のランク・ランクの動き・会員だけの商品）は店の DB に。ランクは直近 12 か月の購入額（台帳から）で毎日決め直し、上がったら知らせる。
 * 特典は核の会員の特典の口（MemberBenefitsHandler）で渡す: 会員価格（値引きの候補）・ポイントの率の上乗せ・会員だけ・ランクだけの商品（見せない商品）。
 * ショップはマイページの「会員ランク」（/p/member-ranks/account）、管理画面は App の画面（ランクごとの人数・会員の一覧・手の上げ下げ）と商品の画面の欄
 */
final class MemberRanksServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'member-ranks');

        if ($this->app->runningInConsole()) {
            $this->commands([RunRanksCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('member-ranks:run')->dailyAt('03:40')->withoutOverlapping(120);
        });

        $plugins->register(new PluginDefinition(
            name: RankSettings::PLUGIN,
            title: '会員ランク',
            description: '直近 12 か月のお買い物の額で、会員のランク（ブロンズ・シルバー・ゴールドなど 3 つまで）を決めます。ランクごとに、会員価格・ポイントの上乗せ・会員限定の商品を設定できます。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                // 会員のランクの画面は、ブラウザが画面を開く時だけ通す（ADR 0088）
                Route::middleware('navigation-only')->group(function (): void {
                    // マイページの「会員ランク」（会員だけ。ほかはログインの画面へ）
                    Route::get('/account', [RankController::class, 'show'])->name('account');
                });
            },
            listingPanel: new RankListingPanel,
            accountLink: new AccountLink(RankSettings::PLUGIN, '会員ランク', '/p/member-ranks/account'),
            adminPage: new RankAdminPage,
            memberBenefits: new MemberRankBenefits,
        ));
    }
}
