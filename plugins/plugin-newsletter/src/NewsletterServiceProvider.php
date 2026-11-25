<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

use App\Core\Party\Events\CustomerLeft;
use App\Core\Plugin\AccountLink;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginNewsletter\Console\RunNewsletterCommand;
use EcSugar\PluginNewsletter\Http\NewsletterController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「メルマガ・一斉メール」。プラグインの登録簿に自分を登録する。
 * 表（購読・配信・送った記録）は店の DB に。ショップは受付・確かめ・配信の停止・マイページの /p/newsletter/… の画面と、
 * 注文の入力の「お知らせのメールを受け取る」（核の口。いつも選んでいない状態から）。管理画面は App の画面（配信の一覧と 1 件・書く。ADR 0051）。
 * 配信は 1 分ごとの newsletter:run が、店ごとに 1 分の上限まで少しずつキューに積む
 */
final class NewsletterServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'newsletter');

        // 退会したら、その会員が選んだ購読を止める（退会と同じトランザクション）
        Event::listen(CustomerLeft::class, static fn (CustomerLeft $e) => app(NewsletterSubscribers::class)->leave($e->accountId));

        if ($this->app->runningInConsole()) {
            $this->commands([RunNewsletterCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('newsletter:run')->everyMinute()->withoutOverlapping(10);
        });

        $plugins->register(new PluginDefinition(
            name: 'newsletter',
            title: 'メルマガ・一斉メール',
            description: 'お知らせのメールに登録したお客さんへ、店からのお知らせ（新商品・セールなど）をメールで一斉に送れます。お客さんは登録ページ・ご注文の手続き・マイページから登録でき、配信の停止はメールのリンクからいつでもワンクリックでできます。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                $token = '[A-Za-z0-9_-]{43}';
                // 受付のフォーム（入れ直しのメール）とマイページの購読は、ブラウザが画面を開く時・画面のフォームから送る時だけ（ADR 0088）
                Route::get('/', [NewsletterController::class, 'show'])->middleware('navigation-only')->name('form');
                Route::get('/sent', [NewsletterController::class, 'sent'])->name('sent');
                Route::get('/account', [NewsletterController::class, 'account'])->middleware('navigation-only')->name('account');
                // メールのリンクの画面（開くだけでは変えない。押して変える）
                Route::get('/confirm/{token}', [NewsletterController::class, 'showConfirm'])->where('token', $token)->middleware('secret-link')->name('confirm');
                Route::get('/unsubscribe/{token}', [NewsletterController::class, 'showUnsubscribe'])->where('token', $token)->middleware('secret-link')->name('unsubscribe');
                // 送る・押すは、店 × 接続元で 1 分に 10 回まで（会員のログインと同じ枠。受付の中でもメールと接続元ごとに数える）
                Route::middleware('throttle:storefront-member')->group(function () use ($token): void {
                    Route::post('/', [NewsletterController::class, 'submit'])->middleware('navigation-only')->name('submit');
                    Route::post('/account', [NewsletterController::class, 'toggle'])->middleware('navigation-only')->name('account.submit');
                    Route::post('/confirm/{token}', [NewsletterController::class, 'confirm'])->where('token', $token)->middleware('secret-link')->name('confirm.submit');
                });
                // 配信の停止: 画面のボタンと、メールのソフトのワンクリック（RFC 8058。List-Unsubscribe-Post）。
                // メールのソフトは CSRF の合い言葉を持たないので、この 1 つだけ CSRF の確かめを外す（合い言葉は 256 ビットの乱数で、その 1 人を止めるだけ）。
                // メールのソフトの送り元はまとまった住所から来るので、接続元の回数の上限も掛けない（止める操作を断らない）
                Route::post('/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])->where('token', $token)->middleware('secret-link')->name('unsubscribe.submit')
                    ->withoutMiddleware(ValidateCsrfToken::class);
            },
            accountLink: new AccountLink('newsletter', 'お知らせのメール', '/p/newsletter/account'),
            adminPage: new NewsletterAdminPage,
            checkoutOptIn: new NewsletterCheckout,
        ));
    }
}
