<?php

declare(strict_types=1);

use App\Storefront\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 運営（中央のホスト）で受ける決済代行の通知（ADR 0022）
|--------------------------------------------------------------------------
| つないだ店（Stripe Connect）の通知は運営の 1 か所で受け、通知の account から店を引いてテナントの文脈で処理する。
| セッションも CSRF も持たない群（bootstrap/app.php の central-webhooks）。署名で確かめる。
*/

// 公開: Stripe Connect の通知（送るのは Stripe。運営の Connect の署名の鍵で確かめ、署名の無い・違う・古い通知は 400）。接続元ごとの回数制限つき
Route::middleware(['public', 'throttle:webhooks'])->group(function () {
    Route::post('/webhooks/stripe-connect', [WebhookController::class, 'stripeConnect'])->name('central.webhooks.stripe-connect');
});
