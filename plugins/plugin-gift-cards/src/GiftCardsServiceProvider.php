<?php

declare(strict_types=1);

namespace EcSugar\PluginGiftCards;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventReceivers;
use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginGiftCards\Console\IssueGiftCardsCommand;
use EcSugar\PluginGiftCards\Http\GiftCardController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「ギフトカード」（ADR 0076）。売るのは App（/p/gift-cards の画面と、払われたら発行してコードをメールで届ける）、
 * 残高の帳簿・注文の支払いへの充て方・管理画面（設定 › ギフトカード）は核（app/Core/GiftCard）。
 * 表（売った注文の額面と贈る相手）は店の DB に。払われた知らせ（PaymentConfirmed）で発行し、取りこぼしは 1 時間ごとの gift-cards:issue が拾う
 */
final class GiftCardsServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'gift-cards');

        // 払われたギフトカードの注文に発行してコードを届ける（確定の書き込みの後に送り出し役が届ける。ADR 0089）。App をやめた後に払われた注文にも発行する（お金を受け取った約束）。
        // 落ちたら送り出し役が報告して届け直す（1 時間ごとの見回りも拾い直す）。2 回届いても同じ注文には 1 枚だけ（核が買った注文ごとに 1 枚。2 回目はコードを出さないので、メールも送らない）
        $receivers = $this->app->make(EventReceivers::class);
        $receivers->notice('gift-cards.issue', PaymentConfirmed::class, static function (PaymentConfirmed $e): void {
            app(GiftCardIssuer::class)->paid($e->transactionId);
        });

        // 売った注文に返金が記録されたら（外でした返金。チャージバックの受け取りは核に無いので、届いたら店の人が止める）、発行したカードを止めて
        // ホームのやることに出す（盗んだカードで買った人が使い切る前に）。記録の確定の後に届く
        // 2 回届いても、止まっていないカードだけを止める（止めた後は何もしない）
        $receivers->on('gift-cards.stop-on-refund', ['ledger.journal_posted'], static function (DomainEvent $event): void {
            if (($event->payload['type'] ?? null) !== 'refund' || $event->aggregate_type !== 'transaction') {
                return;
            }

            $card = GiftCard::query()->where('purchase_transaction_id', $event->aggregate_id)->first();

            if ($card !== null && $card->stopped_at === null) {
                app(GiftCardService::class)->stop($card, GiftCardIssuer::ACTOR, 'refund');
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([IssueGiftCardsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('gift-cards:issue')->hourlyAt(35)->withoutOverlapping(60);
        });

        $plugins->register(new PluginDefinition(
            name: 'gift-cards',
            title: 'ギフトカード',
            description: 'ショップで使えるギフトカード（商品券）を販売します。お支払いが完了するとコードをメールで送り、お客さんはご注文の手続きの画面でコードを入力して使えます。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                // 申し込みの流れは贈る相手のメール・名前を出すので、どれもブラウザが画面を開く時・画面のフォームから送る時だけ通す（ADR 0088。App のタグの JavaScript に読ませない）
                Route::middleware('navigation-only')->group(function (): void {
                    Route::get('/', [GiftCardController::class, 'show'])->name('show');
                    Route::get('/confirm', [GiftCardController::class, 'confirm'])->name('confirm');
                    Route::get('/done', [GiftCardController::class, 'done'])->name('done');
                    Route::middleware('throttle:storefront-cart')->group(function (): void {
                        Route::post('/', [GiftCardController::class, 'submit'])->name('submit');
                        Route::post('/place', [GiftCardController::class, 'place'])->name('place');
                    });
                });
            },
        ));
    }
}
