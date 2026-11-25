<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventReceivers;
use App\Core\Party\Events\CustomerLeft;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Transaction\Models\TransactionLine;
use EcSugar\PluginRestock\Console\RunRestockCommand;
use EcSugar\PluginRestock\Http\RestockController;
use EcSugar\PluginRestock\Jobs\NotifyRestock;
use EcSugar\PluginRestock\Models\RestockRequest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「再入荷のお知らせ」。プラグインの登録簿に自分を登録する。
 * 表（登録）は店の DB に。ショップは商品の画面の買い方の口（「入荷したら知らせる」。売り切れの時にも出す）と /p/restock/… の画面、
 * 管理画面は App の画面（種類ごとの数だけ。ADR 0051）。核には手を入れず、在庫が戻ったことは核の記録の受け手（EventReceivers）で知る:
 * 入庫・在庫の調整（inventory.received・inventory.adjusted の増やす向き）と、取引の取消・返金（transaction.phase_changed。確保していた在庫が戻る）。
 * 取りこぼし（キューの失敗・商品を公開し直した・見積もりの受け入れで確保を解いた）は 1 時間ごとの restock:run が拾う
 */
final class RestockServiceProvider extends ServiceProvider
{
    /** 確保していた在庫が戻る取引の段 */
    private const array RELEASING = ['cancel', 'refund'];

    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'restock');

        // 在庫が戻ったかもしれない: 待っている登録のある種類だけ、キューで確かめて知らせる（記録の確定の後に送り出し役が届ける。落ちたら届け直す。ADR 0089）。
        // 2 回届いても、知らせるのは「待っている」登録だけで、送ったら「知らせた」にする（1 回だけ）。App を止めている間の記録は届けない
        $this->app->make(EventReceivers::class)->on('restock.replenished', ['inventory.received', 'inventory.adjusted', 'transaction.phase_changed'], static function (DomainEvent $event): void {
            $variants = self::replenished($event->type, $event->aggregate_id, $event->payload);

            if ($variants === []) {
                return;
            }

            $waiting = RestockRequest::query()->whereIn('variant_id', $variants)->where('status', RestockStatus::Waiting->value)->distinct()->pluck('variant_id');

            foreach ($waiting as $variantId) {
                NotifyRestock::dispatch((string) $variantId);
            }
        }, app: 'restock');

        // 退会したら、その会員が登録した進行中の登録を取り消す（退会と同じトランザクション）
        Event::listen(CustomerLeft::class, static fn (CustomerLeft $e) => app(RestockRequests::class)->leave($e->accountId));

        if ($this->app->runningInConsole()) {
            $this->commands([RunRestockCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('restock:run')->hourlyAt(25)->withoutOverlapping(60);
        });

        $plugins->register(new PluginDefinition(
            name: 'restock',
            title: '再入荷のお知らせ',
            description: '売り切れの商品（種類ごと）に、お客さんがメールを登録できます。入荷したら 1 回だけメールでお知らせします。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            purchaseOptions: new RestockPurchaseOption,
            storefrontRoutes: static function (): void {
                // メールのリンクの画面（開くだけでは変えない。押して変える）。合い言葉は 43 文字
                Route::get('/confirm/{token}', [RestockController::class, 'showConfirm'])->where('token', '[A-Za-z0-9_-]{43}')->middleware('secret-link')->name('confirm');
                Route::get('/cancel/{token}', [RestockController::class, 'showCancel'])->where('token', '[A-Za-z0-9_-]{43}')->middleware('secret-link')->name('cancel');
                // 受付のフォームは会員のメール・入れ直しの値を出すので、ブラウザが画面を開く時・画面のフォームから送る時だけ（ADR 0088）
                Route::get('/{listingId}', [RestockController::class, 'show'])->where('listingId', '[0-9A-Za-z]{26}')->middleware('navigation-only')->name('show');
                Route::get('/{listingId}/sent', [RestockController::class, 'sent'])->where('listingId', '[0-9A-Za-z]{26}')->name('sent');
                // 送る・押すは、店 × 接続元で 1 分に 10 回まで（会員のログインと同じ枠。中でもメールと接続元ごとに数える）
                Route::middleware('throttle:storefront-member')->group(function (): void {
                    Route::post('/confirm/{token}', [RestockController::class, 'confirm'])->where('token', '[A-Za-z0-9_-]{43}')->middleware('secret-link')->name('confirm.submit');
                    Route::post('/cancel/{token}', [RestockController::class, 'cancel'])->where('token', '[A-Za-z0-9_-]{43}')->middleware('secret-link')->name('cancel.submit');
                    Route::post('/{listingId}', [RestockController::class, 'submit'])->where('listingId', '[0-9A-Za-z]{26}')->middleware('navigation-only')->name('submit');
                });
            },
            adminPage: new RestockAdminPage,
        ));
    }

    /**
     * 記録から、在庫が戻ったかもしれない種類を読む（戻らない記録は空）
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function replenished(string $type, string $aggregateId, array $payload): array
    {
        $variant = is_string($payload['variant_id'] ?? null) ? [$payload['variant_id']] : [];

        return match (true) {
            $type === 'inventory.received' => $variant,
            $type === 'inventory.adjusted' => (int) ($payload['delta'] ?? 0) > 0 ? $variant : [],
            $type === 'transaction.phase_changed' && in_array($payload['to'] ?? null, self::RELEASING, true) => array_values(array_map('strval', TransactionLine::query()
                ->where('transaction_id', $aggregateId)->whereNotNull('variant_id')->distinct()->pluck('variant_id')->all())),
            default => [],
        };
    }
}
