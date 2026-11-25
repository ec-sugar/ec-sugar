<?php

use App\Http\Controllers\Internal\TlsAllowedController;
use App\Http\Middleware\RequireTlsAskEntry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 中央（運営側）のルート
|--------------------------------------------------------------------------
| CENTRAL_DOMAINS に列挙したホストでだけ応答する。テナントの店頭は routes/tenant.php。
| API 中心（docs/CONCEPT.md 7）: 画面は後で SPA に置き換え、ここは入口と疎通確認だけ。
*/

// 管理画面のホストは routes/admin.php が受け持つ
foreach (array_diff(config('tenancy.central_domains'), [config('admin.domain')]) as $domain) {
    Route::domain($domain)->group(function () {
        // 公開: 疎通の確認だけ（中身を返さない）。セッションも Cookie も持たない（web の群を外す。中央のホストは店と同じドメインの下にあり、
        // ここで出した Cookie を隣の店のホストに見せない。見直しの軽 6）
        Route::get('/', fn () => response()->json([
            'app' => config('app.name'),
            'scope' => 'central',
        ]))->middleware('public')->withoutMiddleware('web');
    });
}

// 公開（ただし内側だけ）: 入口の Caddy が店のホストの証明書をその場で取る前に「この名前は管理画面か、ある店か」を聞く（ADR 0031）。
// 聞くのは Caddy で、ログインは無い。外からは届かない: nginx が Caddy の住所からの内側のポート（8081）だけで受けて印を付け、
// 印の無い要求は RequireTlsAskEntry が 404 にする（Host を偽っても通らない）。答えは 200 か 404 だけで、中央 DB を読むだけ。
// Host を決めない（Caddy は内側の名前 web:8081 で聞く）。セッションも Cookie も持たない（web の群を外す）
Route::get('/internal/tls/allowed', TlsAllowedController::class)
    ->middleware(['public', RequireTlsAskEntry::class])
    ->withoutMiddleware('web');
