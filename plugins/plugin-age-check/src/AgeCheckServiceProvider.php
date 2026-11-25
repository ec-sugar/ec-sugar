<?php

declare(strict_types=1);

namespace EcSugar\PluginAgeCheck;

use App\Core\Party\Events\CustomerLeft;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginAgeCheck\Http\AgeGateController;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「年齢確認」（ADR 0077）。プラグインの登録簿に自分を登録する。
 * 表（age_check_listings・age_check_members）は店の DB に。注文の確かめと記録は核の口（AgeCheckHandler）、商品ごとの年齢は管理画面の商品の画面の欄（ADR 0050）、
 * 商品の画面の年齢の門（店の設定で使う時だけ）は /p/age-check/gate
 */
final class AgeCheckServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'age-check');

        // 退会したら、会員が確かめた年齢を消す（退会と同じトランザクション）
        Event::listen(CustomerLeft::class, static fn (CustomerLeft $e) => AgeCheckRules::leave($e->accountId));

        $plugins->register(new PluginDefinition(
            name: 'age-check',
            title: '年齢確認',
            description: 'お酒・たばこなど、年齢確認が必要な商品を決めると、商品の画面に「20 歳未満の方はお求めいただけません」と表示し、注文の時に生年月日と同意を確認します（生年月日は保存しません）。確認できた注文には「年齢確認済み」が付きます。「使わない」にすると、年齢確認が必要な商品も確認なしで販売されます（商品ごとの年齢の設定は残り、「使う」に戻すと再び有効になります）。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                // 年齢の門（店の設定で使う時だけ、核の商品の画面がここへ送る）。答えは Cookie だけで、記録は残さない
                Route::get('/gate', [AgeGateController::class, 'show'])->name('gate');
                Route::post('/gate', [AgeGateController::class, 'answer'])->middleware('throttle:storefront-cart')->name('gate.answer');
            },
            listingPanel: new AgeCheckListingPanel,
            ageCheck: new AgeCheckRules,
        ));
    }
}
