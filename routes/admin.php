<?php

declare(strict_types=1);

use App\Admin\Http\Controllers\Api\AddonController;
use App\Admin\Http\Controllers\Api\AgentProposalController;
use App\Admin\Http\Controllers\Api\AgentSettingsController;
use App\Admin\Http\Controllers\Api\AgentTokenController;
use App\Admin\Http\Controllers\Api\AppPageController;
use App\Admin\Http\Controllers\Api\CategoryController;
use App\Admin\Http\Controllers\Api\CouponController;
use App\Admin\Http\Controllers\Api\CustomerController;
use App\Admin\Http\Controllers\Api\CustomerPointsController;
use App\Admin\Http\Controllers\Api\DeliveryDateSettingsController;
use App\Admin\Http\Controllers\Api\EventRetryController;
use App\Admin\Http\Controllers\Api\GiftCardController;
use App\Admin\Http\Controllers\Api\HomeController;
use App\Admin\Http\Controllers\Api\ListingAppController;
use App\Admin\Http\Controllers\Api\ListingController;
use App\Admin\Http\Controllers\Api\ListingCsvController;
use App\Admin\Http\Controllers\Api\ListingFileController;
use App\Admin\Http\Controllers\Api\ListingImageController;
use App\Admin\Http\Controllers\Api\ListingPreviewController;
use App\Admin\Http\Controllers\Api\MailRecordController;
use App\Admin\Http\Controllers\Api\MemberController;
use App\Admin\Http\Controllers\Api\OAuthConsentController;
use App\Admin\Http\Controllers\Api\OperationsController;
use App\Admin\Http\Controllers\Api\OrderAppController;
use App\Admin\Http\Controllers\Api\OrderDocumentController;
use App\Admin\Http\Controllers\Api\OrderFieldController;
use App\Admin\Http\Controllers\Api\OrderReturnController;
use App\Admin\Http\Controllers\Api\PaymentSettingsController;
use App\Admin\Http\Controllers\Api\PluginController;
use App\Admin\Http\Controllers\Api\PointSettingsController;
use App\Admin\Http\Controllers\Api\SalesReportController;
use App\Admin\Http\Controllers\Api\SampleController;
use App\Admin\Http\Controllers\Api\SectionHtmlController;
use App\Admin\Http\Controllers\Api\SeoController;
use App\Admin\Http\Controllers\Api\ShippingMethodController;
use App\Admin\Http\Controllers\Api\ShopMediaController;
use App\Admin\Http\Controllers\Api\ShopNameController;
use App\Admin\Http\Controllers\Api\ShopPageController;
use App\Admin\Http\Controllers\Api\ShopSettingsController;
use App\Admin\Http\Controllers\Api\StockController;
use App\Admin\Http\Controllers\Api\TenantController;
use App\Admin\Http\Controllers\Api\TenantPluginController;
use App\Admin\Http\Controllers\Api\ThemeContentController;
use App\Admin\Http\Controllers\Api\ThemeController;
use App\Admin\Http\Controllers\Api\ThemeStyleController;
use App\Admin\Http\Controllers\Api\TraceController;
use App\Admin\Http\Controllers\Api\TransactionController;
use App\Admin\Http\Controllers\Api\VariantController;
use App\Admin\Http\Controllers\Auth\EnrollmentController;
use App\Admin\Http\Controllers\Auth\LoginController;
use App\Admin\Http\Controllers\Auth\OperatorSessionController;
use App\Admin\Http\Controllers\Auth\PasskeyController;
use App\Admin\Http\Controllers\Auth\RecoveryController;
use App\Admin\Http\Controllers\Auth\SessionController;
use App\Admin\Http\Controllers\OAuthController;
use App\Admin\Http\Controllers\ShellController;
use App\Admin\Http\Middleware\AuthenticateAgent;
use App\Admin\Http\Middleware\ConfigureAdminSession;
use App\Admin\Http\Middleware\EnsureOperatorIsActive;
use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\Middleware\RejectSignedInOperator;
use App\Admin\Http\Middleware\RequireConfirmationToOpenShop;
use App\Admin\Http\Middleware\RequireOwner;
use App\Admin\Http\Middleware\RequireRecentConfirmation;
use App\Admin\Http\Middleware\RestrictAdminIp;
use App\Admin\Mcp\ShopServer;
use App\Http\Middleware\HostPrefixedCookies;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

/*
|--------------------------------------------------------------------------
| 運営の管理画面（ADR 0013）
|--------------------------------------------------------------------------
| ADMIN_DOMAIN のホストでだけ応答する（bootstrap/app.php が domain と 'admin' ミドルウェア群を付ける）。
| API の応答はすべて JSON。画面は SPA（resources/admin）で、下の殻の経路がその HTML を返す。
*/

// 公開: 検索エンジン向けの robots.txt（ADR 0061）。管理画面は検索に載せないので全部を止める（中身は決まった 2 行だけ）
Route::get('/robots.txt', static fn () => response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']))->middleware('public');

// 公開: CSRF 用の Cookie（XSRF-TOKEN）を受け取るだけ
Route::get('/auth/csrf', fn () => response()->noContent())->middleware('public');

// 公開: ログインと登録の入口（ログイン前に使うもの。パスキーか登録トークンが無いと先へ進めない）
Route::middleware(['public', RejectSignedInOperator::class, 'throttle:admin-auth'])->group(function () {
    Route::post('/auth/enrollment', [EnrollmentController::class, 'start']);
    Route::get('/auth/enrollment/options', [EnrollmentController::class, 'options']);
    Route::post('/auth/enrollment/passkey', [EnrollmentController::class, 'store']);

    Route::get('/auth/login/options', [LoginController::class, 'options']);
    Route::post('/auth/login', [LoginController::class, 'store']);

    // パスキーをなくした時: メールと回復用のコードで登録のリンクを受け取る（ADR 0038。間違えるほど待ちが延びる）
    Route::post('/auth/recovery', [RecoveryController::class, 'redeem']);
    // コードも無いとき: メールで申し込み、リンクを開いて 24 時間待ち、同じブラウザで受け取る（ADR 0038 の区切り 2）
    Route::post('/auth/recovery/email', [RecoveryController::class, 'requestEmail']);
    Route::post('/auth/recovery/email/confirm', [RecoveryController::class, 'confirmEmail']);
    Route::post('/auth/recovery/email/finish', [RecoveryController::class, 'finishEmail']);
});

// AI エージェントの入口（MCP、ADR 0100）と OAuth 2.1 の機械の受け口（ADR 0101）。管理画面のセッション・Cookie・CSRF を使わない。
// 管理画面の接続元の制限（RestrictAdminIp）からも外す（claude.ai などのクラウドのクライアントから届くように。ADR 0101 の要点の 6）。
// 外してよい経路は RouteAudit::WITHOUT_ADMIN_IP の一覧だけで、ほかが外れると経路の見張りが落ちる。守りは鍵・OAuth・回数の上限
Route::middleware('public')
    ->withoutMiddleware([RestrictAdminIp::class, HostPrefixedCookies::class, ConfigureAdminSession::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ValidateCsrfToken::class])
    ->group(function () {
        // 公開の印は「セッションのログインではない」の意味で、鍵の確かめは AuthenticateAgent がする（経路の見張りの規則 16）。GET と DELETE は 405 を返すだけ。
        // 401 の WWW-Authenticate は AuthenticateAgent が付ける（laravel/mcp の付け足しは Passport の経路を前提にするので外す）
        Mcp::web('/mcp', ShopServer::class)->middleware(['throttle:agent-mcp', AuthenticateAgent::class])->withoutMiddleware(AddWwwAuthenticateHeader::class);

        // 公開: OAuth のメタデータ（RFC 9728・8414）。決まった値だけで、秘密も店のデータも入らない
        Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResourceRoot']);
        Route::get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResource']);
        Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer']);
        // 公開: 動的なクライアント登録（RFC 7591）。公開のクライアントだけ・行き先の形と数の上限・接続元ごとと全体の回数の上限
        Route::post('/oauth/register', [OAuthController::class, 'register'])->middleware('throttle:agent-oauth');
        // 公開: 鍵の受け渡しと取り消し。確かめは認可コード ＋ PKCE とリフレッシュトークン（AgentOAuthGrants）。断った回は接続元ごとに数える
        Route::post('/oauth/token', [OAuthController::class, 'token'])->middleware('throttle:agent-oauth');
        Route::post('/oauth/revoke', [OAuthController::class, 'revoke'])->middleware('throttle:agent-oauth');
    });

// 公開: OAuth の許可の頼み（ADR 0101）。頼みを表に 10 分置いて、許可の画面（SPA の /oauth/consent/…。ログインと再確認はそこで）へ送るだけ。
// 接続元の制限の中（運営がブラウザで開く所）
Route::get('/oauth/authorize', [OAuthController::class, 'authorize'])->middleware(['public', 'throttle:agent-oauth-authorize']);

Route::middleware(['auth:operator', EnsureOperatorIsActive::class])->group(function () {
    Route::get('/auth/me', [SessionController::class, 'show']);
    Route::post('/auth/logout', [LoginController::class, 'destroy']);

    Route::middleware('throttle:admin-auth')->group(function () {
        Route::get('/auth/confirm/options', [PasskeyController::class, 'confirmOptions']);
        Route::post('/auth/confirm', [PasskeyController::class, 'confirm']);
    });

    Route::get('/auth/passkeys', [PasskeyController::class, 'index']);
    // ログイン中の端末。本人の分だけ。終えるのは守りの操作なので再確認は求めない
    Route::get('/auth/sessions', [OperatorSessionController::class, 'index']);
    Route::delete('/auth/sessions/{sessionId}', [OperatorSessionController::class, 'destroy'])->whereUlid('sessionId');
    Route::post('/auth/sessions/others', [OperatorSessionController::class, 'others']);
    Route::get('/auth/recovery-codes', [RecoveryController::class, 'index']);
    Route::post('/auth/recovery/email/cancel', [RecoveryController::class, 'cancelEmail']);

    Route::middleware([RequireRecentConfirmation::class, 'throttle:admin-auth'])->group(function () {
        Route::get('/auth/passkeys/options', [PasskeyController::class, 'options']);
        Route::post('/auth/passkeys', [PasskeyController::class, 'store']);
        // {passkey} はパッケージが全体に結びつけている（持ち主で絞らない）ので別名にし、controller が自分の分だけ探す
        Route::delete('/auth/passkeys/{passkeyId}', [PasskeyController::class, 'destroy'])->whereUlid('passkeyId');
        Route::post('/auth/recovery-codes', [RecoveryController::class, 'store']);
    });

    // 管理画面の API（ADR 0014）。テナントの中に入るのは /api/tenants/{tenant}/… の EnterTenant だけ
    Route::prefix('api')->middleware('throttle:api')->group(function () {
        Route::get('/tenants', [TenantController::class, 'index']);

        // 運営のメンバーと権限（ADR 0024）。オーナーだけ、変えるのは直前の再確認の後
        Route::middleware(RequireOwner::class)->group(function () {
            Route::get('/members', [MemberController::class, 'index']);
            // 入っているプラグインとテーマ。読むだけ
            Route::get('/plugins', [PluginController::class, 'index']);
            // 運営全体の画面（ADR 0042）。全部の店の数と困りごと。中央の表だけを読む（店の DB に入らない）。読むだけ
            Route::get('/operations', [OperationsController::class, 'show']);
            // 要対応の件数だけ（ADR 0043）。店のホームの上のオーナーへの帯。読むだけ
            Route::get('/operations/alerts', [OperationsController::class, 'alerts']);
            // 復元のキットの今の様子（ADR 0111）。公開してよい値だけ（指紋・時刻）。読むだけ
            Route::get('/operations/recovery-kit', [OperationsController::class, 'recoveryKit']);
            Route::middleware(RequireRecentConfirmation::class)->group(function () {
                Route::post('/members', [MemberController::class, 'store']);
                Route::post('/members/{operatorId}/enrollment', [MemberController::class, 'enrollment'])->whereUlid('operatorId');
                Route::put('/members/{operatorId}/role', [MemberController::class, 'role'])->whereUlid('operatorId');
                Route::put('/members/{operatorId}/status', [MemberController::class, 'status'])->whereUlid('operatorId');
                Route::put('/members/{operatorId}/grants/{tenantId}', [MemberController::class, 'grant'])->whereUlid('operatorId')->where('tenantId', '[a-z0-9][a-z0-9_-]{0,62}');
                // 「APP_KEY の控えをサーバーの外に取りました」の答え。運営の様子から。入力は無く、残すのは「いつ・誰が」だけ（鍵は扱わない）。監査ログに残す
                Route::post('/operations/app-key-backup', [OperationsController::class, 'confirmAppKeyBackup'])->middleware('throttle:admin-auth');
                // 復元のキット（ADR 0111）。キットはブラウザの中で作る。material は APP_KEY をオーナーの画面へ渡す（渡すたびに監査ログ）、
                // 下は確かめ終えたキットの開ける鍵の公開の半分を受け取る。合い言葉・秘密の鍵・キットそのものは受け取らない
                Route::post('/operations/recovery-kit/material', [OperationsController::class, 'recoveryKitMaterial'])->middleware('throttle:admin-auth');
                Route::post('/operations/recovery-kit', [OperationsController::class, 'registerRecoveryKit'])->middleware('throttle:admin-auth');
            });
        });
        // AI エージェントの鍵（ADR 0100）。見るのと取り消しは本人とオーナー、発行は自分の分だけで直前の再確認の後
        Route::get('/agent-tokens', [AgentTokenController::class, 'index']);
        Route::delete('/agent-tokens/{tokenId}', [AgentTokenController::class, 'destroy'])->whereUlid('tokenId');
        Route::post('/agent-tokens', [AgentTokenController::class, 'store'])->middleware(RequireRecentConfirmation::class);
        // OAuth の許可の系統（ADR 0101）。一覧は上の GET /agent-tokens に入る。取り消しは本人かオーナー
        Route::delete('/agent-grants/{grantId}', [AgentTokenController::class, 'destroyGrant'])->whereUlid('grantId');
        // OAuth の許可の画面（ADR 0101）: 頼みの中身・拒否・許可（許可は直前の再確認の後）。{requestId} は頼みの合い言葉（表にはハッシュだけ）
        Route::get('/oauth-requests/{requestId}', [OAuthConsentController::class, 'show'])->where('requestId', '[A-Za-z0-9_-]{43}');
        Route::post('/oauth-requests/{requestId}/deny', [OAuthConsentController::class, 'deny'])->where('requestId', '[A-Za-z0-9_-]{43}');
        Route::post('/oauth-requests/{requestId}/approve', [OAuthConsentController::class, 'approve'])->where('requestId', '[A-Za-z0-9_-]{43}')->middleware(RequireRecentConfirmation::class);
        // ログインの履歴（オーナーは全員、スタッフは自分の分）
        Route::get('/login-history', [MemberController::class, 'logins']);
        // テーマの見本の絵。テーマのパッケージの中の静的な絵で、店をまたがない（どの店の運営にも同じ）。ログイン中の運営だけ
        Route::get('/themes/{theme}/screenshot', [ThemeController::class, 'screenshot'])->where('theme', '[a-z][a-z0-9-]{1,31}');

        Route::prefix('tenants/{tenant}')->middleware(EnterTenant::class)->group(function () {
            Route::get('/overview', [TenantController::class, 'overview']);
            // ホームの開設の手順。読むだけ。「ようこそ」を閉じた印は運営ごと・店ごとに中央へ（店のデータは変えないので閲覧の段階から）
            Route::get('/setup', [HomeController::class, 'setup']);
            Route::put('/welcome', [HomeController::class, 'welcome'])->middleware('tenant-level:read');
            Route::get('/events/verify', [TenantController::class, 'verifyEvents']);
            // 処理の ID で関係する記録を並べる（ADR 0087）。障害の調べ物なのでオーナーだけ。読むだけ。入った店の記録だけを返す
            Route::get('/traces/{traceId}', [TraceController::class, 'show'])->whereUlid('traceId')->middleware(RequireOwner::class);
            // 入れたときのサンプルをまとめて消す。オーナーだけ・直前の再確認の後。消したことは監査ログに残す
            Route::delete('/samples', [SampleController::class, 'destroy'])->middleware([RequireOwner::class, RequireRecentConfirmation::class]);
            // 流し損ねた・止めた知らせを流し直す（ADR 0089）。運営の様子から。オーナーだけ・直前の再確認の後。流し直したことは監査ログに残す
            Route::post('/event-retries', [EventRetryController::class, 'store'])->middleware([RequireOwner::class, RequireRecentConfirmation::class, 'throttle:admin-auth']);

            // 商品と在庫。{listingId} はモデルに結びつけず、テナント文脈の中で探す
            Route::get('/listing-options', [ListingController::class, 'options']);
            Route::get('/listings', [ListingController::class, 'index']);
            Route::post('/listings', [ListingController::class, 'store']);
            Route::get('/listings/{listingId}', [ListingController::class, 'show'])->whereUlid('listingId');
            Route::patch('/listings/{listingId}', [ListingController::class, 'update'])->whereUlid('listingId');
            // 商品のプレビューのリンク。リンクを作るだけで商品は変えないので、閲覧の段階から
            Route::post('/listings/{listingId}/preview', [ListingPreviewController::class, 'store'])->whereUlid('listingId')->middleware('tenant-level:read');
            Route::post('/listings/{listingId}/stock/receive', [StockController::class, 'receive'])->whereUlid('listingId')->name('admin.stock.receive');
            Route::post('/listings/{listingId}/stock/adjust', [StockController::class, 'adjust'])->whereUlid('listingId')->name('admin.stock.adjust');
            // 商品の種類（色・サイズなど。ADR 0036）。商品の編集と同じく運用の段階から
            Route::put('/listings/{listingId}/options', [VariantController::class, 'define'])->whereUlid('listingId');
            Route::patch('/listings/{listingId}/variants/{variantId}', [VariantController::class, 'update'])->whereUlid(['listingId', 'variantId']);
            // 商品のオプション（名入れ・ギフト包装など。ADR 0036）。種類と同じく運用の段階から
            Route::put('/listings/{listingId}/addons', [AddonController::class, 'define'])->whereUlid('listingId');
            // 商品の画面の App の欄（ADR 0050。定期便の「定期でも買える・周期・値引き」など）。この店で使っている App のうち欄を出すものだけ。
            // 読むのは閲覧、保存は運用（EnterTenant の既定）。使っていない・欄の無い App は 404
            Route::get('/listings/{listingId}/apps', [ListingAppController::class, 'index'])->whereUlid('listingId');
            Route::put('/listings/{listingId}/apps/{plugin}', [ListingAppController::class, 'update'])->whereUlid('listingId')->where('plugin', '[a-z][a-z0-9-]{1,31}');
            // 商品の画面の App の欄のファイル（ADR 0084。売るファイル）。大きなファイルを切れ端（8 MB）に分けて受ける。どれも運用の段階から（EnterTenant の既定）。
            // 始める・終えるは写真と同じ回数の制限、切れ端は 1 分に 240 回まで（運営ごと）。{upload}・{fileId} はモデルに結びつけない
            Route::post('/listings/{listingId}/apps/{plugin}/uploads', [ListingFileController::class, 'start'])->whereUlid('listingId')->where('plugin', '[a-z][a-z0-9-]{1,31}')->middleware('throttle:admin-uploads');
            Route::post('/listings/{listingId}/apps/{plugin}/uploads/{upload}', [ListingFileController::class, 'chunk'])->whereUlid(['listingId', 'upload'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->middleware('throttle:admin-file-chunks');
            Route::post('/listings/{listingId}/apps/{plugin}/uploads/{upload}/finish', [ListingFileController::class, 'finish'])->whereUlid(['listingId', 'upload'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->middleware('throttle:admin-uploads');
            Route::delete('/listings/{listingId}/apps/{plugin}/uploads/{upload}', [ListingFileController::class, 'abort'])->whereUlid(['listingId', 'upload'])->where('plugin', '[a-z][a-z0-9-]{1,31}');
            Route::delete('/listings/{listingId}/apps/{plugin}/files/{fileId}', [ListingFileController::class, 'destroy'])->whereUlid(['listingId', 'fileId'])->where('plugin', '[a-z][a-z0-9-]{1,31}');

            // 商品の写真。受け取ったファイルは作り直してから保存する
            Route::post('/listings/{listingId}/images', [ListingImageController::class, 'store'])->whereUlid('listingId')->middleware('throttle:admin-uploads');
            Route::post('/listings/{listingId}/images/order', [ListingImageController::class, 'order'])->whereUlid('listingId');
            Route::patch('/listings/{listingId}/images/{imageId}', [ListingImageController::class, 'update'])->whereUlid(['listingId', 'imageId']);
            Route::delete('/listings/{listingId}/images/{imageId}', [ListingImageController::class, 'destroy'])->whereUlid(['listingId', 'imageId']);
            Route::get('/listings/{listingId}/images/{imageId}/{variant}', [ListingImageController::class, 'show'])->whereUlid(['listingId', 'imageId'])->whereIn('variant', ['large', 'medium']);

            // 取引の閲覧。個人情報は personal だけが返し、見た事実を監査ログに残す
            // 商品カテゴリ
            Route::get('/categories', [CategoryController::class, 'index']);
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::post('/categories/order', [CategoryController::class, 'order']);
            Route::patch('/categories/{categoryId}', [CategoryController::class, 'update'])->whereUlid('categoryId');
            Route::post('/categories/{categoryId}/move', [CategoryController::class, 'move'])->whereUlid('categoryId');
            Route::delete('/categories/{categoryId}', [CategoryController::class, 'destroy'])->whereUlid('categoryId');
            Route::put('/listings/{listingId}/categories', [CategoryController::class, 'assign'])->whereUlid('listingId');

            // クーポン（ADR 0054 の区切り 2）。見るのは閲覧、作る・直す・止める・再開は運用（EnterTenant の既定）。
            // {couponId} はモデルに結びつけず、テナント文脈の中で探す。コードは作った後に変えない
            Route::get('/coupons', [CouponController::class, 'index']);
            Route::post('/coupons', [CouponController::class, 'store']);
            Route::get('/coupons/{couponId}', [CouponController::class, 'show'])->whereUlid('couponId');
            Route::put('/coupons/{couponId}', [CouponController::class, 'update'])->whereUlid('couponId');
            Route::post('/coupons/{couponId}/pause', [CouponController::class, 'pause'])->whereUlid('couponId');
            Route::post('/coupons/{couponId}/resume', [CouponController::class, 'resume'])->whereUlid('couponId');
            // 商品の CSV（書き出し・取り込み。docs/design/product-csv.md）。店の商品をまとめて読む・変えるので、書き出しも記録を読むのも運用の段階から。
            // 上げるのは写真と同じ回数の制限。確かめも反映もキューの仕事で、画面は記録（{importId}。モデルに結びつけない）を読み直して待つ。
            // 反映（待ち・進行中）は店に 1 つだけ（2 つ目は 409）。反映を頼んだら誰が・何件を監査ログに残す。
            // 中身は App（plugins/plugin-product-csv。ADR 0107 の段 1）で、画面と受け口だけが核に残る。
            // 使っていない店では、あるかどうかも見せずに 404（plugin:product-csv。ADR 0025）
            Route::middleware('plugin:product-csv')->group(function (): void {
                Route::get('/listings-csv', [ListingCsvController::class, 'export'])->middleware('tenant-level:operate');
                Route::post('/listings-csv/check', [ListingCsvController::class, 'check'])->middleware('throttle:admin-uploads');
                Route::get('/listings-csv/imports', [ListingCsvController::class, 'index'])->middleware('tenant-level:operate');
                Route::get('/listings-csv/imports/{importId}', [ListingCsvController::class, 'show'])->whereUlid('importId')->middleware('tenant-level:operate');
                Route::post('/listings-csv/imports/{importId}/apply', [ListingCsvController::class, 'apply'])->whereUlid('importId');
                Route::post('/listings-csv/imports/{importId}/retry', [ListingCsvController::class, 'retry'])->whereUlid('importId')->middleware('throttle:admin-uploads');
            });

            // 店のプラグイン（ADR 0025）: 使う・使わない。変えるのは直前の再確認の後
            Route::get('/plugins', [TenantPluginController::class, 'index']);
            Route::put('/plugins/{plugin}', [TenantPluginController::class, 'update'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->middleware(RequireRecentConfirmation::class);
            // App の設定（ADR 0034）。お客さんの情報の送信先（タグの ID など）を決めるので、使う・使わないと同じく運用の段階で直前の再確認の後
            Route::put('/plugins/{plugin}/settings', [TenantPluginController::class, 'settings'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->middleware(RequireRecentConfirmation::class);
            // 止めた App のデータを消す（ADR 0092）。元に戻せないので、オーナーだけ・直前の再確認の後。消したことは監査ログに残す
            Route::delete('/plugins/{plugin}/data', [TenantPluginController::class, 'purge'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->middleware([RequireOwner::class, RequireRecentConfirmation::class]);

            // App の画面（ADR 0051。定期便の一覧など）。この店で使っている App のうち画面を持つものだけ。見るのは閲覧、操作は運用（EnterTenant の既定）。
            // 使っていない・画面の無い App・無い記録・今は押せない操作は同じ 404
            Route::get('/app-pages', [AppPageController::class, 'menu']);
            Route::get('/apps/{plugin}/records', [AppPageController::class, 'index'])->where('plugin', '[a-z][a-z0-9-]{1,31}');
            Route::get('/apps/{plugin}/records/{recordId}', [AppPageController::class, 'show'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->whereUlid('recordId');
            Route::post('/apps/{plugin}/records/{recordId}/actions/{action}', [AppPageController::class, 'act'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->whereUlid('recordId')->where('action', '[a-z][a-z0-9_]{0,31}');
            // 足す・直す（よくある質問など、足す欄を持つ App だけ。ほかは 404）。運用の段階から（EnterTenant の既定）
            Route::post('/apps/{plugin}/records', [AppPageController::class, 'store'])->where('plugin', '[a-z][a-z0-9-]{1,31}');
            Route::put('/apps/{plugin}/records/{recordId}', [AppPageController::class, 'update'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->whereUlid('recordId');
            // 読む・探す（チケットの受付の QR・番号など、欄を持つ App だけ。ほかは 404）。名前で引いた結果に個人情報がつながるので運用の段階から（印を明示）
            Route::post('/apps/{plugin}/lookup', [AppPageController::class, 'lookup'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->middleware('tenant-level:operate');
            // App の注文の書類（納品書・領収書など）。どの書類のボタンを出すかは閲覧から。印刷用のページ（HTML。新しいタブ）は届け先や名前が載るので運用から、
            // 見た事実を監査ログに残す。POST は「発行する」（領収書の宛名を入れて出すなど）。使っていない・書類の無い App は 404
            Route::get('/order-documents', [OrderDocumentController::class, 'index']);
            Route::get('/apps/{plugin}/documents/{document}', [OrderDocumentController::class, 'show'])->where(['plugin' => '[a-z][a-z0-9-]{1,31}', 'document' => '[a-z][a-z0-9_]{0,31}'])->middleware('tenant-level:operate');
            Route::post('/apps/{plugin}/documents/{document}', [OrderDocumentController::class, 'show'])->where(['plugin' => '[a-z][a-z0-9-]{1,31}', 'document' => '[a-z][a-z0-9_]{0,31}']);
            // CSV の書き出しとファイルの取り込み（配送の CSV など、口を持つ App だけ。ほかは 404）。
            // 書き出しは届け先などの個人情報が入るので運用の段階から（印を明示）。書き出したことは監査ログに残す。取り込みは確かめの記録を作るだけで、状態は 1 件の画面の操作で変える
            Route::post('/apps/{plugin}/export', [AppPageController::class, 'export'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->middleware('tenant-level:operate');
            Route::post('/apps/{plugin}/imports', [AppPageController::class, 'import'])->where('plugin', '[a-z][a-z0-9-]{1,31}')->middleware('throttle:admin-uploads');

            // 店の設定: 店のテーマ・注文の案内・特商法の表記（送料は下の送料の表。ADR 0064）
            Route::get('/shop-settings', [ShopSettingsController::class, 'show']);
            Route::put('/shop-settings/theme', [ShopSettingsController::class, 'updateTheme']);
            // テーマを替える前の知らせ（ADR 0039 区切り 3）: 写せない区画のある自由なページ。読むだけなので閲覧の段階から
            Route::get('/shop-settings/theme/impact', [ShopSettingsController::class, 'themeImpact'])->middleware('tenant-level:read');
            // 送料の表（ADR 0064）: 配送の方法ごとの都道府県の額と無料の線。読むのは閲覧から、変えるのはほかの店の設定と同じく運用の段階から
            Route::get('/shipping-methods', [ShippingMethodController::class, 'show'])->middleware('tenant-level:read');
            Route::put('/shipping-methods', [ShippingMethodController::class, 'update']);
            Route::put('/shop-settings/order-terms', [ShopSettingsController::class, 'updateOrderTerms']);
            Route::put('/shop-settings/free-items', [ShopSettingsController::class, 'updateFreeItems']);
            Route::put('/shop-settings/legal', [ShopSettingsController::class, 'updateLegal']);
            // プライバシーポリシーの本文（ADR 0094）。ほかの店の設定と同じく運用の段階から
            Route::put('/shop-settings/privacy-policy', [ShopSettingsController::class, 'updatePrivacyPolicy']);
            // 解析・広告のタグの同意の帯を出すか（既定は出さない）。ほかの店の設定と同じく運用の段階から
            Route::put('/shop-settings/consent-banner', [ShopSettingsController::class, 'updateConsentBanner']);
            // ショップの公開: 準備中・公開の切り替えと、準備中に入るパスワード。ほかの店の設定と同じく運用の段階から。
            // 公開にするときだけ直前の再確認（だれでも見られて本番の鍵で注文を受けるようになる）。準備中に戻すのは求めない
            Route::put('/shop-settings/publish', [ShopSettingsController::class, 'updatePublish'])->middleware(RequireConfirmationToOpenShop::class);
            Route::put('/shop-settings/storefront-password', [ShopSettingsController::class, 'updateStorefrontPassword']);
            // 店の名前。名前は中央の店の行にあり、メールの差出人の名前にも出るので、オーナーだけ・直前のパスキーの再確認の後。変えたことは中央の監査ログと店の記録に残す（名前そのものは残さない）
            Route::put('/shop-settings/name', [ShopNameController::class, 'update'])->middleware([RequireOwner::class, RequireRecentConfirmation::class]);
            // お届け日時の指定（ADR 0059）: 日付・時間帯・発送までの日数・休み・締め切り。読むのは閲覧、変えるのは運用（EnterTenant の既定）
            Route::get('/shop-settings/delivery-dates', [DeliveryDateSettingsController::class, 'show']);
            Route::put('/shop-settings/delivery-dates', [DeliveryDateSettingsController::class, 'update']);
            // AI エージェントの店の設定（ADR 0101）: 書き込みを「すぐ反映」か「承認してから」と、お客さんの情報を渡すか。読むのは閲覧、変えるのは運用（EnterTenant の既定）と直前の再確認。渡し始めるのはお金の段階だけ（コントローラー）。変えたら監査ログに値を残す
            Route::get('/shop-settings/agents', [AgentSettingsController::class, 'show'])->middleware('tenant-level:read');
            Route::put('/shop-settings/agents', [AgentSettingsController::class, 'update'])->middleware(RequireRecentConfirmation::class);
            // AI エージェントの案（承認の一覧。ADR 0101）。見るのも運用から（送り状の番号を含みうる）。承認はその案に要る段階（お金の操作はお金の段階と直前の再確認。コントローラーとサービスで）。
            // {proposalId} はモデルに結びつけず、テナント文脈の中で探す
            Route::middleware('tenant-level:operate')->group(function () {
                Route::get('/agent-proposals', [AgentProposalController::class, 'index']);
                Route::get('/agent-proposals/{proposalId}', [AgentProposalController::class, 'show'])->whereUlid('proposalId');
                Route::post('/agent-proposals/{proposalId}/approve', [AgentProposalController::class, 'approve'])->whereUlid('proposalId');
                Route::post('/agent-proposals/{proposalId}/reject', [AgentProposalController::class, 'reject'])->whereUlid('proposalId');
            });
            // ポイント（ADR 0068 の 7）: 使う／使わないと率（0〜20%）。読むのは閲覧、変えるのは運用（EnterTenant の既定）。変えたら監査ログに値を残す
            Route::get('/shop-settings/points', [PointSettingsController::class, 'show'])->middleware('tenant-level:read');
            Route::put('/shop-settings/points', [PointSettingsController::class, 'update']);
            // 注文の項目（ADR 0066）: 店が注文の入力に足す欄。読むのは閲覧、足す・直す・消す・並べ替えるは運用（EnterTenant の既定）。
            // {fieldId} はモデルに結びつけず、テナント文脈の中で探す。変えたことは店の記録と中央の監査ログに残す
            Route::get('/order-fields', [OrderFieldController::class, 'index']);
            Route::post('/order-fields', [OrderFieldController::class, 'store']);
            Route::post('/order-fields/order', [OrderFieldController::class, 'order']);
            Route::put('/order-fields/{fieldId}', [OrderFieldController::class, 'update'])->whereUlid('fieldId');
            Route::delete('/order-fields/{fieldId}', [OrderFieldController::class, 'destroy'])->whereUlid('fieldId');

            // 設定 › SEO（ADR 0061）: トップの題と説明・共有の既定の写真・転送の一覧。読むのは閲覧から、変えるのは運用から（EnterTenant の既定）。
            // {redirectId} はモデルに結びつけず、テナント文脈の中で探す
            Route::get('/seo', [SeoController::class, 'show']);
            Route::put('/seo', [SeoController::class, 'update']);
            Route::post('/seo/redirects', [SeoController::class, 'storeRedirect']);
            Route::delete('/seo/redirects/{redirectId}', [SeoController::class, 'destroyRedirect'])->whereUlid('redirectId');

            // 入っているテーマの札と、テーマごとの中身の状態（「デザイン」のテーマの画面）。読むだけなので閲覧の段階から
            Route::get('/themes', [ThemeController::class, 'index'])->middleware('tenant-level:read');
            // テーマの区画の値（ADR 0028 区切り 2・ADR 0039）: ページの区画の並びを読む・下書きの並びを置き換える・公開する（公開はバージョンごと）。
            // 入っているテーマならどれでも（切り替える前に準備できる）。前の「種類 => 値」の口（…/content）は で外した（中身はページの編集の画面だけで入れる）
            Route::get('/themes/{theme}/pages/{page}', [ThemeContentController::class, 'showPage'])->where(['theme' => '[a-z][a-z0-9-]{1,31}', 'page' => '[a-z][a-z0-9_-]{0,31}']);
            Route::put('/themes/{theme}/pages/{page}/draft', [ThemeContentController::class, 'updatePage'])->where(['theme' => '[a-z][a-z0-9-]{1,31}', 'page' => '[a-z][a-z0-9_-]{0,31}']);
            Route::post('/themes/{theme}/pages/{page}/publish', [ThemeContentController::class, 'publishPage'])->where(['theme' => '[a-z][a-z0-9-]{1,31}', 'page' => '[a-z][a-z0-9_-]{0,31}']);
            // ページの雛形で並びを作り直す（下書きに入るだけ）。変える操作なので運用の段階から（EnterTenant の既定）
            Route::post('/themes/{theme}/pages/{page}/template', [ThemeContentController::class, 'applyTemplate'])->where(['theme' => '[a-z][a-z0-9-]{1,31}', 'page' => '[a-z][a-z0-9_-]{0,31}']);
            // 下書きのプレビューのリンク（ADR 0039 区切り 2）。見るだけなので閲覧の段階から（商品のプレビューと同じ）
            Route::post('/themes/{theme}/pages/{page}/preview', [ThemeContentController::class, 'previewPage'])->where(['theme' => '[a-z][a-z0-9-]{1,31}', 'page' => '[a-z][a-z0-9_-]{0,31}'])->middleware('tenant-level:read');
            // テーマの見た目（ADR 0097）: 色の組・主な色・字の組・角の丸みの宣言と下書きの値を読む・下書きに保存する（公開は上の publish でバージョンごと）。
            // 読むのは閲覧、変えるのは運用（EnterTenant の既定。テーマの中身と同じ）
            Route::get('/themes/{theme}/style', [ThemeStyleController::class, 'show'])->where('theme', '[a-z][a-z0-9-]{1,31}');
            Route::put('/themes/{theme}/style/draft', [ThemeStyleController::class, 'update'])->where('theme', '[a-z][a-z0-9-]{1,31}');

            // 自由なページ（ADR 0039 区切り 3）: 一覧・作る・題と URL とメニューと公開の状態・並べる・ゴミ箱へ・戻す。中身は上のページの区画の並び（page-{ID}）。
            // {pageId} はモデルに結びつけず、テナント文脈の中で探す。読むのは閲覧、変えるのは運用（EnterTenant の既定）
            Route::get('/shop-pages', [ShopPageController::class, 'index']);
            Route::post('/shop-pages', [ShopPageController::class, 'store']);
            Route::post('/shop-pages/order', [ShopPageController::class, 'order']);
            Route::patch('/shop-pages/{pageId}', [ShopPageController::class, 'update'])->whereUlid('pageId');
            Route::delete('/shop-pages/{pageId}', [ShopPageController::class, 'destroy'])->whereUlid('pageId');
            Route::post('/shop-pages/{pageId}/restore', [ShopPageController::class, 'restore'])->whereUlid('pageId');
            // ゴミ箱のページを完全に消す（戻せない。すべてのテーマ・バージョンの中身も消す）。運用の段階から、画面で確かめてから
            Route::post('/shop-pages/{pageId}/purge', [ShopPageController::class, 'purge'])->whereUlid('pageId');

            // 区画の HTML の確かめ: 保存とショップと同じ決まりで整えた結果と外したものを返す。何も書かないので閲覧の段階から
            Route::post('/section-html', [SectionHtmlController::class, 'check'])->middleware('tenant-level:read');

            // ショップの写真の置き場（ADR 0028 区切り 1）。{mediaId} はモデルに結びつけず、テナント文脈の中で探す
            Route::get('/shop-media', [ShopMediaController::class, 'index']);
            Route::post('/shop-media', [ShopMediaController::class, 'store'])->middleware('throttle:admin-uploads');
            Route::patch('/shop-media/{mediaId}', [ShopMediaController::class, 'update'])->whereUlid('mediaId');
            Route::delete('/shop-media/{mediaId}', [ShopMediaController::class, 'destroy'])->whereUlid('mediaId');
            Route::get('/shop-media/{mediaId}/{variant}', [ShopMediaController::class, 'show'])->whereUlid('mediaId')->whereIn('variant', ['large', 'medium']);

            // 決済代行の鍵。置く・外すは直前の再確認
            Route::get('/payment-settings', [PaymentSettingsController::class, 'show']);
            Route::middleware([RequireRecentConfirmation::class, 'tenant-level:money'])->group(function () {
                Route::put('/payment-settings/stripe', [PaymentSettingsController::class, 'updateStripe']);
                Route::delete('/payment-settings/stripe', [PaymentSettingsController::class, 'destroyStripe']);
                // 公開の前に試すテスト用の鍵（ADR 0096）。お金の段階・直前の再確認はふだんの鍵と同じ
                Route::put('/payment-settings/stripe-test', [PaymentSettingsController::class, 'updateStripeTest']);
                Route::delete('/payment-settings/stripe-test', [PaymentSettingsController::class, 'destroyStripeTest']);
                // 使う支払いの手段（コンビニ払い・PayPay。ADR 0058）
                Route::put('/payment-settings/methods', [PaymentSettingsController::class, 'updateMethods']);
                // 店が受け取る支払い（銀行振込・代金引換。ADR 0074）。振込先は店の鍵で暗号化
                Route::put('/payment-settings/direct', [PaymentSettingsController::class, 'updateDirect']);
                // 「Stripe とつなぐ」（ADR 0022）
                Route::post('/payment-settings/stripe-connect', [PaymentSettingsController::class, 'connectStripe']);
                Route::delete('/payment-settings/stripe-connect', [PaymentSettingsController::class, 'disconnectStripe']);
            });
            // 登録の状態の問い合わせ直し（読むだけ。Stripe の登録の画面から戻ったとき）
            Route::post('/payment-settings/stripe-connect/refresh', [PaymentSettingsController::class, 'refreshConnect'])->middleware('tenant-level:money');

            // お客さん（ADR 0044 の 4）。一覧と 1 人の画面は件数と金額だけ（閲覧から）。個人情報は personal だけが返し、見た事実を監査ログに残す（運用から）。
            // 同じ人かもしれない記録・まとめる・分けるは運用から（自動ではまとめない。注文は書き換えない）
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customerId}', [CustomerController::class, 'show'])->whereUlid('customerId');
            Route::middleware('tenant-level:operate')->group(function () {
                Route::get('/customers/{customerId}/personal', [CustomerController::class, 'personal'])->whereUlid('customerId');
                Route::get('/customers/{customerId}/similar', [CustomerController::class, 'similar'])->whereUlid('customerId');
                Route::post('/customers/{customerId}/merge', [CustomerController::class, 'merge'])->whereUlid('customerId');
                Route::post('/customers/{customerId}/unmerge', [CustomerController::class, 'unmerge'])->whereUlid('customerId');
            });
            // メールの記録（ADR 0095）。一覧と 1 通の様子は閲覧から（宛先は返さない）。宛先はお客さんの名前と連絡先と同じ運用から（見た事実を監査ログに）。
            // 試しのメール（自分のアドレスへ）は運用から。運営ごと・店ごとの回数の上限はコントローラーの中で
            Route::get('/mail-records', [MailRecordController::class, 'index'])->middleware('tenant-level:read');
            Route::get('/mail-records/{recordId}', [MailRecordController::class, 'show'])->whereUlid('recordId')->middleware('tenant-level:read');
            Route::get('/mail-records/{recordId}/personal', [MailRecordController::class, 'personal'])->whereUlid('recordId')->middleware('tenant-level:operate');
            Route::post('/mail-records/test', [MailRecordController::class, 'test'])->middleware('tenant-level:operate');
            // お客さんのポイント（ADR 0068 の 6）。残高と帳簿は閲覧から（数だけ。店の人の理由は運用から）。
            // 手で足す・引くはお金の段階で、直前のパスキーの再確認の後。中央の監査ログに数だけを残す
            Route::get('/customers/{customerId}/points', [CustomerPointsController::class, 'show'])->whereUlid('customerId')->middleware('tenant-level:read');
            Route::post('/customers/{customerId}/points', [CustomerPointsController::class, 'adjust'])->whereUlid('customerId')->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            // ギフトカード（ADR 0076）。一覧と 1 枚の帳簿は閲覧から（末尾 4 文字と額だけ。店の人の理由は運用から、未使用の残高の合計はお金から）。
            // 止める・再開は運用から。手で足す・引くはお金の段階で、直前のパスキーの再確認の後。どれも中央の監査ログに残す（理由は残さない）
            Route::get('/gift-cards', [GiftCardController::class, 'index'])->middleware('tenant-level:read');
            Route::get('/gift-cards/{cardId}', [GiftCardController::class, 'show'])->whereUlid('cardId')->middleware('tenant-level:read');
            Route::post('/gift-cards/{cardId}/stop', [GiftCardController::class, 'stop'])->whereUlid('cardId')->middleware('tenant-level:operate');
            Route::post('/gift-cards/{cardId}/resume', [GiftCardController::class, 'resume'])->whereUlid('cardId')->middleware('tenant-level:operate');
            Route::post('/gift-cards/{cardId}/adjust', [GiftCardController::class, 'adjust'])->whereUlid('cardId')->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);

            // 売上の集計（ADR 0060）。台帳から数えた店の売上・注文の数・商品の上位・お客さんの人数。お金の段階から（ADR 0024）。読むだけ。
            // CSV（日ごと・商品ごと）は書き出したことを監査ログに残す。期間は 366 日まで
            Route::get('/sales', [SalesReportController::class, 'show'])->middleware('tenant-level:money');
            Route::get('/sales/csv', [SalesReportController::class, 'csv'])->middleware('tenant-level:money');

            Route::get('/transactions', [TransactionController::class, 'index']);
            Route::get('/transactions/{transactionId}', [TransactionController::class, 'show'])->whereUlid('transactionId');
            // 注文の画面に出す App の 1 件（ダウンロードの回数など）。読むだけ。個人情報は出さない
            Route::get('/transactions/{transactionId}/apps', [OrderAppController::class, 'index'])->whereUlid('transactionId');
            // 個人情報の表示は運用から（ADR 0024）
            Route::get('/transactions/{transactionId}/personal', [TransactionController::class, 'personal'])->whereUlid('transactionId')->middleware('tenant-level:operate');
            // 注文の操作。発送の登録と完了。二度目は状態で断る
            Route::post('/transactions/{transactionId}/ship', [TransactionController::class, 'ship'])->whereUlid('transactionId');
            Route::post('/transactions/{transactionId}/complete', [TransactionController::class, 'complete'])->whereUlid('transactionId');
            // サービス（役務）だけの注文の「提供済みにする」
            Route::post('/transactions/{transactionId}/deliver-service', [TransactionController::class, 'deliverService'])->whereUlid('transactionId');
            // 取消（払われていれば Stripe で返金）。お金が動くので直前の再確認
            Route::post('/transactions/{transactionId}/cancel', [TransactionController::class, 'cancel'])->whereUlid('transactionId')->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            // 返金が要る支払い（二重に払われた・取り消した注文に届いた）の返金。お金が動くので直前の再確認
            Route::post('/transactions/{transactionId}/stray-payments/{strayId}/refund', [TransactionController::class, 'refundStray'])
                ->whereUlid(['transactionId', 'strayId'])->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            // 止めた返金（額が違う・Stripe の画面でした返金）を、Stripe で確かめた後に片付ける。お金が動くので直前の再確認
            Route::post('/transactions/{transactionId}/refunds/{refundId}/resolve', [TransactionController::class, 'resolveRefund'])
                ->whereUlid(['transactionId', 'refundId'])->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            // 銀行振込・代金引換（ADR 0074）: 入金を確かめた・代引きの受取拒否・自分で振り込んだ返金の記録。お金が動くので直前の再確認
            Route::post('/transactions/{transactionId}/direct-payment/confirm', [TransactionController::class, 'confirmDirectPayment'])
                ->whereUlid('transactionId')->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            Route::post('/transactions/{transactionId}/cod/refuse', [TransactionController::class, 'refuseCod'])
                ->whereUlid('transactionId')->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            Route::post('/transactions/{transactionId}/direct-refunds', [TransactionController::class, 'recordDirectRefund'])
                ->whereUlid('transactionId')->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            // 断られた贈り物の送料の差額の返金を頼み直す（ADR 0064）。お金が動くので直前の再確認
            Route::post('/transactions/{transactionId}/shipping-refund/retry', [TransactionController::class, 'retryShippingRefund'])
                ->whereUlid('transactionId')->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            // 返品・一部返金・済んだ注文の返金（ADR 0048）。お金の段階。確かめの画面は読むだけ、実行は直前の再確認も
            Route::post('/transactions/{transactionId}/returns/preview', [OrderReturnController::class, 'preview'])->whereUlid('transactionId')->middleware('tenant-level:money');
            Route::post('/transactions/{transactionId}/returns', [OrderReturnController::class, 'store'])->whereUlid('transactionId')->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            // 断られた返金（返品・取り消した注文の取消）を頼み直す（ADR 0048 の 3）。お金が動くので直前の再確認
            Route::post('/transactions/{transactionId}/refunds/{refundId}/retry', [OrderReturnController::class, 'retry'])
                ->whereUlid(['transactionId', 'refundId'])->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
            // 使ったギフトカードの額を店の人が選んでカードへ戻す（外でした返金の後。ADR 0076）。お金が動くので直前の再確認
            Route::post('/transactions/{transactionId}/gift-card/release', [GiftCardController::class, 'releaseForOrder'])
                ->whereUlid('transactionId')->middleware([RequireRecentConfirmation::class, 'tenant-level:money']);
        });
    });
});

// 公開: 画面の殻（HTML とビルド済みの JS / CSS への参照だけで、データは入っていない。データは上の API がログインを確かめてから返す）。
// SPA の画面の経路だけを列挙する（それ以外は 404）。画面を足したら resources/admin/router.js とここの両方に足す
Route::get('/{page?}', ShellController::class)
    ->where('page', 'login|recover|recover/email|enroll|passkeys|members|plugins|operations|login-history|oauth/consent(/[A-Za-z0-9_-]{43})?|help(/[a-z][a-z0-9-]{1,31})?|tenants(/[a-z0-9][a-z0-9_-]{0,62}(/listings(/new|/import|/[0-9a-z]{26})?|/transactions(/[0-9a-z]{26})?|/customers(/[0-9a-z]{26})?|/coupons(/new|/[0-9a-z]{26})?|/agent-proposals(/[0-9a-z]{26})?|/sales|/settings(/basic|/legal|/privacy|/order-terms|/order-fields|/payments|/shipping|/delivery-dates|/points|/gift-cards|/consent|/records|/mails|/publish|/seo|/agents)?|/theme(/pages/[a-z][a-z0-9_-]{0,31})?|/pages|/photos|/traces/[0-9a-z]{26}|/categories|/plugins(/[a-z][a-z0-9-]{1,31})?|/apps/[a-z][a-z0-9-]{1,31}(/[0-9a-z]{26})?)?)?')
    ->middleware('public');

// 公開: 一覧に無い経路は 404。管理画面のホストの中で受けて、断る応答にも管理画面の防御ヘッダを付ける
Route::fallback(static fn () => abort(404))->middleware('public');
