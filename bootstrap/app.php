<?php

use App\Admin\Http\Middleware\AdminSecurityHeaders;
use App\Admin\Http\Middleware\ConfigureAdminSession;
use App\Admin\Http\Middleware\RestrictAdminIp;
use App\Admin\Http\Middleware\TenantLevel;
use App\Admin\Services\Incidents;
use App\Core\UserFacingException;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\HostPrefixedCookies;
use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RejectMalformedInput;
use App\Http\Middleware\RequirePlugin;
use App\Http\Middleware\SecurityHeaders;
use App\Storefront\Http\Middleware\DemoNoindex;
use App\Storefront\Http\Middleware\DemoVisitor;
use App\Storefront\Http\Middleware\FollowRedirects;
use App\Storefront\Http\Middleware\HostOnlySessionCookie;
use App\Storefront\Http\Middleware\NavigationOnly;
use App\Storefront\Http\Middleware\RefuseJsonBody;
use App\Storefront\Http\Middleware\RequireShopAccess;
use App\Storefront\Http\Middleware\SecretLink;
use App\Storefront\Http\Middleware\StorefrontHostPrefixedCookies;
use App\Storefront\Http\Middleware\StorefrontSecurityHeaders;
use App\Storefront\View\StorefrontView;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // 公開: 確かめ口（ok か down の文字だけ。接続元を絞らない理由は HealthController）。管理画面の SPA の経路より先に置く
            Route::get('/up', HealthController::class)->middleware('public');

            // 運営の管理画面は専用のホストでだけ受ける（ADR 0013）
            Route::domain((string) config('admin.domain'))
                ->middleware('admin')
                ->group(base_path('routes/admin.php'));

            // 決済代行の通知を運営で受ける（ADR 0022）。管理画面のホストでは受けない
            foreach (array_diff(config('tenancy.central_domains'), [config('admin.domain')]) as $domain) {
                Route::domain($domain)->middleware('central-webhooks')->group(base_path('routes/webhooks.php'));
            }
        },
    )
    ->withCommands([
        __DIR__.'/../app/Admin/Console',
        __DIR__.'/../app/Storefront/Console',
        // App のひな形（make:plugin）
        __DIR__.'/../app/Core/Plugin/Console',
        // 流し損ねた記録の見回り（events:relay）
        __DIR__.'/../app/Core/Event/Console',
        // 古いメールの記録の掃除（mail-records:prune）
        __DIR__.'/../app/Core/Mail/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // 信じる前段の代理は config/trustedproxy.php（TRUSTED_PROXIES に書いたものだけ、既定は無し）。ここで at() を決めない（決めると設定より優先される）。
        // 私設の住所を丸ごと信じていた頃は、LAN から X-Forwarded-For を偽るだけで接続元の制限を抜けられた

        // ログインなしで開いてよい経路の印（付け忘れはテストが落とす）
        // tenant-level: 管理画面の店の API に要る権限の段階（ADR 0024。確かめるのは EnterTenant）。alias は 1 回で（2 回呼ぶと前の分が消える）
        // secret-link: URL に署名か合い言葉を持つショップの画面の宣言（ADR 0081。参照元を渡さない・残さない・検索に載せないを核がまとめて掛ける。付け忘れはテストが落とす）
        // navigation-only: ショップの個人情報の画面は、ブラウザが画面を開く時だけ通す（ADR 0088。付け忘れは NavigationOnlyRoutesTest が落とす）
        $middleware->alias(['public' => PublicRoute::class, 'tenant-level' => TenantLevel::class, 'plugin' => RequirePlugin::class, 'secret-link' => SecretLink::class, 'navigation-only' => NavigationOnly::class]);

        // 個人情報の画面の関所は、回数の上限より先に（タグの JavaScript の fetch で、お客さんの回数の枠を減らさせない）。ログインの確かめの後
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: NavigationOnly::class);

        // 処理を追う ID（ADR 0087）。いちばん外側で、要求ごとに新しく決める（外から来る X-Request-Id は信じない）
        $middleware->prepend(AssignTraceId::class);

        // 防御ヘッダは全応答に付ける
        $middleware->append(SecurityHeaders::class);

        // 管理画面: 厳しい防御ヘッダ（拒否の 404 にも付ける） → 接続元の制限 → 専用のセッション Cookie → セッション → CSRF → 壊れた文字の拒否（ADR 0013）
        $middleware->group('admin', [
            AdminSecurityHeaders::class,
            RestrictAdminIp::class,
            // https では Cookie を __Host- の名前でだけやり取りする（隣のホストから置かれた Cookie を読まない）。
            // 管理画面のセッションの設定より外（その応答の後の Cookie の取り消しを、内側の名前のうちに済ませる）
            HostPrefixedCookies::class,
            ConfigureAdminSession::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidateCsrfToken::class,
            RejectMalformedInput::class,
            SubstituteBindings::class,
        ]);

        // 店頭: 防御ヘッダ（断った応答にも付ける） → テナントを特定（中央のホストは断る。知らないホストは 404 のまま）
        // → JSON の本文を 415 で断る（ショップはフォームの形だけ。LINE の Webhook だけ経路で外す。大きさは入口の JsonBodyLimit）
        // → 壊れた文字の拒否 → Cookie・セッション・CSRF（ADR 0018）
        // → 準備中のショップの関所（パスワードを入れた人だけを通す。群の最後なので、この群の経路はすべて内側）。
        // テナントを先に特定するので、セッションもテナントの文脈で始まる。経路のモデルの結びつけ（SubstituteBindings）は使わない
        $middleware->group('storefront', [
            StorefrontSecurityHeaders::class,
            InitializeTenancyByDomain::class,
            PreventAccessFromCentralDomains::class,
            RefuseJsonBody::class,
            RejectMalformedInput::class,
            // セッションと CSRF の Cookie はこのショップのホストだけ（env の SESSION_DOMAIN によらない。複数の店の形で店をまたがせない）
            HostOnlySessionCookie::class,
            // https では Cookie を __Host- の名前でだけやり取りする（隣の店・同じドメインのほかのサイトから置かれた Cookie を読まない）
            StorefrontHostPrefixedCookies::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            // デモの店（ADR 0086）: 来訪者の合い言葉・入力のメールアドレスの置き換え・noindex。デモでない店では何もしない
            DemoVisitor::class,
            RequireShopAccess::class,
            // 店が足した転送（ADR 0061）。いちばん内側で、ショップの答えが「見つかりません」の時だけ見る
            FollowRedirects::class,
        ]);

        // 店頭の写真。セッションも Cookie も持たない（共有のキャッシュに載せられるように）。守りは店頭と同じ
        $middleware->group('storefront-media', [
            StorefrontSecurityHeaders::class,
            InitializeTenancyByDomain::class,
            PreventAccessFromCentralDomains::class,
            RejectMalformedInput::class,
            // デモの店（ADR 0086）の写真にも noindex。デモでない店では何もしない
            DemoNoindex::class,
        ]);

        // 運営で受ける決済代行の通知（ADR 0022）。セッション・Cookie・CSRF を持たず、署名で確かめる
        $middleware->group('central-webhooks', [
            SecurityHeaders::class,
            RejectMalformedInput::class,
        ]);

        // 未ログインは、ショップの会員の経路ならログインの画面へ。ほかはリダイレクトせず 401（管理画面のログインの画面は SPA が持つ）
        $middleware->redirectGuestsTo(static fn (Request $request): ?string => $request->routeIs('storefront.*') ? route('storefront.account.login') : null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 誤りで戻すときに、入れた値をセッションへ残さない項目（決済代行の鍵。ギフトカードのコード。ADR 0076。生年月日。ADR 0077）
        $exceptions->dontFlash(['secret_key', 'webhook_secret', 'code', 'gift_card.code', 'age.birthdate']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson() || $request->getHost() === config('admin.domain'),
        );

        // 壊れたこと（HTTP の処理の中の 500）を、中央に「どこで・何が・何回」だけ記録する（ADR 0043）。
        // メッセージは置かない。記録が失敗しても投げない。ログへの報告はこの後も続く（false を返さない）
        $exceptions->report(static function (Throwable $e): void {
            Incidents::reported($e);
        });

        // 見せてよい文の例外（取引の決まり・支払いに進めない・権限の決まり）は、JSON を求める呼び出しと管理画面に {message, user_message} で返す。
        // 画面の受け口（ショップ）はそれぞれ受け止めてリダイレクトする
        $exceptions->render(static function (UserFacingException $e, Request $request) {
            if (! $request->expectsJson() && $request->getHost() !== config('admin.domain')) {
                return null;
            }

            return response()->json(['message' => $e->getMessage(), 'user_message' => $e->getMessage()], $e->status());
        });

        // 店頭（テナントの文脈の中で、画面を求める呼び出し）のエラーは、テーマのエラーの画面で返す
        $exceptions->render(static function (HttpExceptionInterface $e, Request $request) {
            if (! tenancy()->initialized || $request->expectsJson() || $request->getHost() === config('admin.domain')) {
                return null;
            }

            return app(StorefrontView::class)->render('error', ['status' => $e->getStatusCode()], $e->getStatusCode());
        });

        // テナントを特定できないホストは 404 に写像する（HTML / JSON はコンテンツ交渉に従う）。
        // 500 のまま返すと、存在するテナントを推測する材料になる
        $exceptions->map(
            TenantCouldNotBeIdentifiedException::class,
            fn (TenantCouldNotBeIdentifiedException $e) => new NotFoundHttpException('Not Found', $e),
        );
    })->create();
