<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventReceivers;
use App\Core\Listing\Enums\OfferSide;
use App\Core\Listing\Enums\Pricing;
use App\Core\Listing\ListingKindDefinition;
use App\Core\Listing\ListingKinds;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Events\RefundSettled;
use App\Core\Plugin\AccountLink;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\Console\GrantDownloadsCommand;
use EcSugar\PluginDownloads\Console\PurgeDownloadsCommand;
use EcSugar\PluginDownloads\Http\DownloadController;
use EcSugar\PluginDownloads\Models\DownloadFile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「ダウンロード販売」（ADR 0084）。商品の扱い「ダウンロード」を足し、ふだんのカートと注文の流れで売る（会員でもゲストでも。
 * 送料・お届け先・在庫を使わず、受渡は digital。物と一緒のカートも通る）。払いが確かになったらリンク（期限と回数）を作り、ご案内のメールを送る。
 * 売るファイルは商品の画面の App の欄に上げる（受け取り・形の確かめは核。置き場は写真と別の、外から見えない downloads）。
 * 注文の取消・返金でリンクを止める。App を止めた店では新しく売らない（核が種類を売らない）が、払われた注文へのリンク作りと、
 * もう払ったお客さんのダウンロードの画面とファイルは続ける（払ったお客さんのため。経路は keptStorefrontRoutes）
 */
final class DownloadsServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'downloads');

        // 商品の扱い「ダウンロード」（使う店だけで選べる）。カートに入り、送料・お届け先・在庫を使わない（1 回の注文で 1 点まで）
        $this->callAfterResolving(ListingKinds::class, static function (ListingKinds $kinds): void {
            if (! $kinds->has(DownloadListingPanel::KIND)) {
                // 売れるのは、置いたファイルのある商品だけ（ファイルの無い商品はカートにも確定にも進まない）。App を止めた店では核が売らない
                $kinds->register(new ListingKindDefinition(DownloadListingPanel::KIND, OfferSide::Sell, Pricing::Fixed, 'ダウンロード', plugin: 'downloads', stockless: true, digital: true,
                    sellable: static fn (array $ids): array => DownloadFile::query()->whereIn('listing_id', $ids)->whereNull('removed_at')->distinct()->pluck('listing_id')
                        ->map(static fn (mixed $id): string => (string) $id)->all()));
            }
        });

        // 受け手（ADR 0089）。確定の書き込みの後に送り出し役が届け、落ちたら報告して届け直す（10 分ごとの downloads:grant も作り直す）。
        // App を止めた店でも、もう払ったお客さんには渡す・返金したら止める（App に結びつけない）。
        // 2 回届いても、リンクはまだ無い商品にだけ作り（作った時だけ案内のメールを送る）、止めるのは止まっていないリンクだけ
        $receivers = $this->app->make(EventReceivers::class);

        // 払いが確かになったらリンクを作って渡し終える（ダウンロードの商品が無ければ何もしない）。
        // 代引きの「発送できる」の知らせ（お金が届く前）では何もしない。お金が届いた知らせで渡す
        $receivers->notice('downloads.grant', PaymentConfirmed::class, static function (PaymentConfirmed $e): void {
            if (! $e->onDelivery && app(DownloadGrants::class)->paid($e->transactionId)) {
                SendDownloadMail::dispatch($e->transactionId);
            }
        });

        // 返金が確かになったら（返品・一部返金・外でした返金と銀行振込・代引きの返金の記録・払った後の取消）、全部を返したならリンクを全部、
        // 一部なら返した行のダウンロードの商品のリンクを止める（止めた時刻は返金が確かになった時）
        $receivers->notice('downloads.refunded', RefundSettled::class, static function (RefundSettled $e, ?DomainEvent $event): void {
            app(DownloadGrants::class)->refunded($e->transactionId, $e->full, $e->purpose, $e->lines, $event->occurred_at ?? CarbonImmutable::now());
        });

        // 注文の取消（払う前の取消も）・取引の返金で、その注文のリンクを止める
        $receivers->on('downloads.stop-on-close', ['transaction.phase_changed'], static function (DomainEvent $event): void {
            $to = $event->payload['to'] ?? null;

            if (in_array($to, ['cancel', 'refund'], true) && Transaction::query()->whereKey($event->aggregate_id)->exists()) {
                app(DownloadGrants::class)->stopOrder($event->aggregate_id, (string) $to, $event->occurred_at);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([GrantDownloadsCommand::class, PurgeDownloadsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('downloads:grant')->everyTenMinutes()->withoutOverlapping(30);
            $schedule->command('downloads:purge')->dailyAt('04:40')->withoutOverlapping(60);
        });

        $admin = new DownloadAdminPage;

        $plugins->register(new PluginDefinition(
            name: 'downloads',
            title: 'ダウンロード販売',
            description: 'PDF・音楽・動画などのファイルを販売します。いつものカートで購入でき、お支払いが確認できると、期限と回数つきのダウンロードのリンクをお送りします。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            // App を止めた店でも、もう払ったお客さんは受け取れる（見直し）。新しく売るのは止まる（核が種類を売らない）
            keptStorefrontRoutes: static function (): void {
                // 注文と買ったファイルは、どれもブラウザが画面を開く時・画面のフォームから送る時だけ通す（ADR 0088。App のタグの JavaScript にファイルを読ませない）
                Route::middleware('navigation-only')->group(function (): void {
                    // マイページのダウンロード（会員だけ）
                    Route::get('/account', [DownloadController::class, 'account'])->name('account');
                    // 署名つきの道筋の 3 つは秘密の道筋の宣言（secret-link）を持つ。参照元を渡さない・残さない・検索に載せないは核が掛ける
                    // 注文のダウンロードの画面（会員の本人か、署名つきで来て照会を通した人だけ）
                    Route::get('/orders/{orderId}', [DownloadController::class, 'order'])->where('orderId', '[0-9A-Za-z]{26}')->middleware('secret-link')->name('order');
                    // 押す（回数を数えて、短い期限の道筋へ）
                    Route::post('/orders/{orderId}/{grantId}', [DownloadController::class, 'fetch'])->where(['orderId' => '[0-9A-Za-z]{26}', 'grantId' => '[0-9A-Za-z]{26}'])
                        ->middleware(['throttle:storefront-cart', 'secret-link'])->name('fetch');
                    // ファイルを渡す（署名と 5 分の期限だけ）
                    Route::get('/file/{grantId}', [DownloadController::class, 'file'])->where('grantId', '[0-9A-Za-z]{26}')->middleware(['throttle:storefront-cart', 'secret-link'])->name('file');
                });
            },
            listingPanel: new DownloadListingPanel,
            accountLink: new AccountLink('downloads', 'ダウンロード', '/p/downloads/account'),
            adminPage: $admin,
            orderLinks: new DownloadOrderLink,
            listingFiles: new DownloadFiles,
            adminOrderPanel: $admin,
            // 止めた店で、オーナーが選ぶとリンク・ファイルを全部消す（ADR 0092。払ったお客さんが受け取れるうちは断る）
            purger: new DownloadDataPurger,
        ));
    }
}
