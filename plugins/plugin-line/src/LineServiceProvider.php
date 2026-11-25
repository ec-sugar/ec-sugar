<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

use App\Core\Event\Outbox\EventReceivers;
use App\Core\Party\Events\CustomerLeft;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Plugin\AccountLink;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Transaction\Events\FulfillmentShipped;
use App\Storefront\Access\ClientSource;
use App\Storefront\Http\Middleware\RefuseJsonBody;
use EcSugar\PluginLine\Http\LineController;
use EcSugar\PluginLine\Jobs\SendLineMessage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「LINE 連携」（ADR 0070）。プラグインの登録簿に自分を登録する。
 * 会員のログインの画面の「LINE でログイン」（核の口 LoginEntryProvider）と、LINE で確かめた会員を入れる核の口（MemberSignIn::forApp）、
 * マイページの「LINE 連携」（つなぐ・外す）、注文を受けた・発送したの知らせを LINE でも（核の知らせ PaymentConfirmed・FulfillmentShipped を聞く）、
 * 友だちの追加・ブロックを知る Webhook。表（つなぎ・送った記録）は店の DB に
 */
final class LineServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'line');

        // Webhook の回数の上限は店 × 接続元で（LINE の送り元は公開されない。ほかの店への知らせで、この店の枠を使い切らせない。見直し 軽 6）
        RateLimiter::for('line-webhook', static fn (Request $request) => Limit::perMinute(300)->by('line-webhook|'.tenant()?->getTenantKey().'|'.ClientSource::of($request->ip())));

        // 退会したら、その会員の LINE のつなぎを消す（退会と同じトランザクション）
        Event::listen(CustomerLeft::class, static fn (CustomerLeft $e) => app(LineLinks::class)->forget($e->accountId, $e->actor));

        // 注文を受けた・発送した（どちらも書き込みの確定の後に送り出し役が届ける。積めなければ届け直す。ADR 0089）。
        // 2 回届いても、同じ注文の同じ知らせは 1 回だけ（送った記録 line_messages の一意）。App を止めている間の記録は届けない
        $receivers = $this->app->make(EventReceivers::class);
        $receivers->notice('line.paid', PaymentConfirmed::class, static fn (PaymentConfirmed $e) => self::queue('order_paid', $e->transactionId), app: LineSettings::PLUGIN);
        $receivers->notice('line.shipped', FulfillmentShipped::class, static fn (FulfillmentShipped $e) => self::queue('order_shipped', $e->transactionId, $e->fulfillmentId), app: LineSettings::PLUGIN);

        $plugins->register(new PluginDefinition(
            name: LineSettings::PLUGIN,
            title: 'LINE 連携',
            description: 'お客さんが LINE のアカウントでログイン・会員登録できます。LINE と連携した会員には、注文の受付と発送のお知らせを LINE でも送ります（メールも今までどおり送ります）。お店の LINE 公式アカウントを使います。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                // LINE の認可の画面へ送る・LINE から戻る。どちらも、このブラウザのセッションの 1 回きりの値（state・nonce・PKCE）で守る
                Route::get('/login', [LineController::class, 'login'])->middleware('throttle:storefront-member')->name('login');
                Route::get('/callback', [LineController::class, 'callback'])->middleware('throttle:storefront-member')->middleware('secret-link')->name('callback');
                // まだつないでいない LINE: メールのコードで本人を確かめる（コードと回数は核のログインと同じ）。
                // メールの画面・マイページと送る操作は、ブラウザが画面を開く時・画面のフォームから送る時だけ（ADR 0088）
                Route::get('/email', [LineController::class, 'email'])->middleware('navigation-only')->name('email');
                Route::get('/code', [LineController::class, 'code'])->middleware('navigation-only')->name('code');
                Route::get('/account', [LineController::class, 'account'])->middleware('navigation-only')->name('account');
                Route::middleware(['navigation-only', 'throttle:storefront-member'])->group(static function (): void {
                    Route::post('/email', [LineController::class, 'sendCode'])->name('email.send');
                    Route::post('/code', [LineController::class, 'verifyCode'])->name('code.verify');
                    // マイページから LINE をつなぐ・外す（会員だけ・本人の再確認の後だけ）
                    Route::post('/connect', [LineController::class, 'connect'])->name('connect');
                    Route::post('/disconnect', [LineController::class, 'disconnect'])->name('disconnect');
                });
                // Messaging API の Webhook。送るのは LINE で、CSRF の合い言葉を持たない。守りはチャネルシークレットでの署名の確かめ（受け口で）。
                // 準備中のショップでも受ける（核の関所の外の一覧に名前を書いた）。
                // 本文は JSON なので、ショップの「JSON を断る」も外す（大きさは nginx のショップの上限・核の JSON の上限・受け口の LineWebhook::MAX_BYTES で見る）
                Route::post('/webhook', [LineController::class, 'webhook'])->middleware('throttle:line-webhook')->name('webhook')
                    ->withoutMiddleware([ValidateCsrfToken::class, RefuseJsonBody::class]);
            },
            accountLink: new AccountLink(LineSettings::PLUGIN, 'LINE 連携', '/p/line/account'),
            adminPage: new LineAdminPage,
            loginEntry: new LineLoginEntry,
        ));
    }

    /**
     * LINE の知らせを仕事に載せる（使う店で、その知らせを選んでいる時だけ）。誤りは外へ出さない（注文や発送の答えを変えない。ほかの受け手も止めない）
     */
    private static function queue(string $kind, string $transactionId, ?string $fulfillmentId = null): void
    {
        $settings = app(LineSettings::class);

        if ($settings->enabled() && $settings->wants($kind)) {
            SendLineMessage::dispatch($kind, $transactionId, $fulfillmentId);
        }
    }
}
