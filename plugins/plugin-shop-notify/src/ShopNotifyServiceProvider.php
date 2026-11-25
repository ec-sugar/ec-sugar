<?php

declare(strict_types=1);

namespace EcSugar\PluginShopNotify;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventReceivers;
use App\Core\Payment\Events\PaymentAwaiting;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Transaction\Models\TransactionLine;
use EcSugar\PluginShopNotify\Jobs\CheckLowStock;
use EcSugar\PluginShopNotify\Jobs\SendShopNotice;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「店への知らせ」（ADR 0060 の 2）。注文が入った・取り消された・在庫が少なくなったを、Slack の Incoming Webhook か店のメールへ。
 * 核には手を入れず、核の知らせで知る: 払いが確かになった（PaymentConfirmed。0 円の注文の受付も）、取引の段の移り（取消・返金・合意での在庫の確保）、
 * 在庫の操作（入庫・調整）。どれも書き込みの確定の後に届き、ここではキューに載せるだけ（送るのはキューの仕事）。
 * 載せるのに失敗しても、例外を外へ出さない（注文や在庫の操作の答えを変えない。ほかの受け手も止めない）
 */
final class ShopNotifyServiceProvider extends ServiceProvider
{
    /** 確保していた在庫が戻る取引の段 */
    private const array RELEASING = ['cancel', 'refund'];

    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'shop-notify');

        // 受け手（ADR 0089）。コミットの後に送り出し役が届け、落ちたら届け直す。App を止めている間の記録は届けない（app: で核が見る）。
        // 2 回届いても、同じ知らせを同じ宛先へは 1 回だけ（送った知らせの記録 shop_notify_notices の一意と行のロック）、在庫の印は「無ければ入れる」
        $receivers = $this->app->make(EventReceivers::class);
        $plugin = ShopNotifySettings::PLUGIN;

        // 注文が入った（お支払いが確かめられた・0 円の注文を受け付けた）
        $receivers->notice('shop-notify.paid', PaymentConfirmed::class, static function (PaymentConfirmed $e): void {
            if (app(ShopNotifySettings::class)->wants('paid')) {
                SendShopNotice::dispatch('paid', $e->transactionId, $e->transactionId);
            }
        }, app: $plugin);

        // 銀行振込の注文が入った（入金待ち。ADR 0074）。「注文が入った」と同じ知らせ（同じ注文には 1 回だけ。入金を確かめた時は重ねて送らない）。
        // コンビニ払いの入金待ちは今までどおり、払われた時に知らせる
        $receivers->notice('shop-notify.awaiting', PaymentAwaiting::class, static function (PaymentAwaiting $e): void {
            if (app(ShopNotifySettings::class)->wants('paid') && PaymentAttempt::direct($e->transactionId)?->method === 'bank_transfer') {
                SendShopNotice::dispatch('paid', $e->transactionId, $e->transactionId);
            }
        }, app: $plugin);

        $receivers->on('shop-notify.changes', ['transaction.phase_changed', 'inventory.received', 'inventory.adjusted'], static function (DomainEvent $event): void {
            $type = $event->type;
            $payload = $event->payload;
            $to = $payload['to'] ?? null;

            // 注文が取り消された・返金された（受け付けていた注文だけ）
            if ($type === 'transaction.phase_changed' && ($payload['kind'] ?? null) === 'sale' && in_array($to, self::RELEASING, true)
                && app(ShopNotifySettings::class)->wants('cancelled') && app(ShopNotices::class)->wasAccepted($event->aggregate_id)) {
                SendShopNotice::dispatch('cancelled', $event->aggregate_id, $event->aggregate_id);
            }

            // 在庫の数が動いた種類（合意で確保した・取消で戻した・入庫・調整）を見直す
            $variants = match (true) {
                $type === 'transaction.phase_changed' => in_array($to, ['agreement', ...self::RELEASING], true)
                    ? array_values(array_map('strval', TransactionLine::query()->where('transaction_id', $event->aggregate_id)->whereNotNull('variant_id')->distinct()->pluck('variant_id')->all()))
                    : [],
                default => is_string($payload['variant_id'] ?? null) ? [$payload['variant_id']] : [],
            };

            if ($variants !== []) {
                CheckLowStock::dispatch($variants);
            }
        }, app: $plugin);

        $plugins->register(new PluginDefinition(
            name: ShopNotifySettings::PLUGIN,
            title: '店への通知',
            description: '注文が入った・取り消された・在庫が少なくなったを、Slack かお店のメールでお知らせします。お客さんの名前や住所は載せません。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            adminPage: new ShopNotifyAdminPage,
        ));
    }
}
