<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorSessions;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\StripeConnectService;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 店の決済代行の鍵（EnterTenant の後）と「Stripe とつなぐ」（ADR 0022）。置く・外す・つなぐ・やめるは直前の再確認（RequireRecentConfirmation）。鍵そのものは返さない。
 * 鍵を置く・外す・つなぐ・やめるは、済んだ後に中央の監査ログへ「どの鍵を・どうした・いつもと違う接続元のセッションからか」だけを残す
 * （深夜かいつもと違う接続元からの変更を知らせる。ADR 0103。鍵の中身は残さない）
 */
final class PaymentSettingsController
{
    public function __construct(
        private readonly PaymentGatewayService $gateways,
        private readonly StripeConnectService $connect,
        private readonly PaymentMethodSettings $methods,
        private readonly DirectMethodSettings $direct,
        private readonly AuditLog $audit,
        private readonly OperatorSessions $sessions,
    ) {}

    /** Stripe のダッシュボードで Webhook の送信先に選ぶ通知の種類（payment_intent.* は預けたカードの請求〔定期便〕の確定と断り。ADR 0045。async_payment_* はコンビニ払いの入金と期限切れ。ADR 0058） */
    public const array STRIPE_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired', 'payment_intent.succeeded', 'payment_intent.payment_failed', 'refund.created', 'refund.updated', 'refund.failed'];

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->present($request));
    }

    public function updateStripe(Request $request): JsonResponse
    {
        $this->gateways->configureStripe($request->only(['secret_key', 'webhook_secret']), TenantActor::of($request));
        $this->keysChanged($request, 'stripe', 'set');

        return response()->json($this->present($request));
    }

    /**
     * 店が受け取る支払い（銀行振込・代金引換。ADR 0074）。お金の段階・直前の再確認（経路）。振込先は店の鍵で暗号化して持つ。
     * 「会員だけ」（members_only。ADR 0085）も同じ画面で
     */
    public function updateDirect(Request $request): JsonResponse
    {
        $this->direct->update($request->only(['bank_transfer', 'bank_days', 'bank_account', 'cod', 'cod_fees', 'cod_limit', 'members_only']), TenantActor::of($request));

        return response()->json($this->present($request));
    }

    /**
     * 使う支払いの手段（カードのほかにコンビニ払い・PayPay。ADR 0058）。Stripe のダッシュボードでも有効にする必要がある（画面とヘルプで案内）
     */
    public function updateMethods(Request $request): JsonResponse
    {
        $this->methods->update($request->only(['konbini', 'paypay', 'konbini_days']), TenantActor::of($request));

        return response()->json($this->present($request));
    }

    public function destroyStripe(Request $request): JsonResponse
    {
        $this->gateways->removeStripe(TenantActor::of($request));
        $this->keysChanged($request, 'stripe', 'removed');

        return response()->json($this->present($request));
    }

    /**
     * 公開の前に試すテスト用の鍵を置く（ADR 0096）。テストのモードの鍵だけ。準備中で、ふだんの鍵が本番か無いときだけ新しい支払いに使う
     */
    public function updateStripeTest(Request $request): JsonResponse
    {
        $this->gateways->configureStripeTest($request->only(['secret_key', 'webhook_secret']), TenantActor::of($request));
        $this->keysChanged($request, 'stripe_test', 'set');

        return response()->json($this->present($request));
    }

    public function destroyStripeTest(Request $request): JsonResponse
    {
        $this->gateways->removeStripeTest(TenantActor::of($request));
        $this->keysChanged($request, 'stripe_test', 'removed');

        return response()->json($this->present($request));
    }

    /**
     * 「Stripe とつなぐ」（ADR 0022）。Stripe の登録の画面の 1 回だけのリンクを返す（SPA がそこへ移る）。戻り先は店の設定の画面
     */
    public function connectStripe(Request $request): JsonResponse
    {
        $tenant = Tenant::current();
        $settings = rtrim((string) config('admin.url'), '/').'/tenants/'.rawurlencode((string) $tenant->getTenantKey()).'/settings';

        $url = $this->connect->connect($tenant, "{$settings}?stripe=return", "{$settings}?stripe=refresh", TenantActor::of($request));
        $this->keysChanged($request, 'connect', 'started');

        return response()->json(['url' => $url]);
    }

    /**
     * 登録の状態を Stripe に問い合わせ直す（登録の画面から戻ったとき）
     */
    public function refreshConnect(Request $request): JsonResponse
    {
        $this->connect->refresh(TenantActor::of($request));

        return response()->json($this->present($request));
    }

    public function disconnectStripe(Request $request): JsonResponse
    {
        $this->connect->disconnect(Tenant::current(), TenantActor::of($request));
        $this->keysChanged($request, 'connect', 'removed');

        return response()->json($this->present($request));
    }

    /**
     * 支払いの設定の鍵を変えた事実を中央の監査ログへ（ADR 0103）。どの鍵（stripe・stripe_test・connect）を・どうした（set・removed・started）・
     * いつもと違う接続元から入ったセッションか（ADR 0083 の印）だけ。鍵の中身・Webhook の秘密・連結アカウントの ID は残さない
     */
    private function keysChanged(Request $request, string $target, string $action): void
    {
        $operator = Auth::guard('operator')->user();

        $this->audit->record('operator.payment_keys_changed', $operator instanceof Operator ? $operator : null, [
            'target' => $target,
            'action' => $action,
            'unusual' => $this->sessions->unusual($request),
        ], tenantId: (string) Tenant::current()->getTenantKey(), ip: $request->ip());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request): array
    {
        $stripe = $this->gateways->status();
        $direct = $this->direct->status();

        // 振込先はお金の段階の人にだけ返す（閲覧・運用の人は「振込先がある」ことだけ。ADR 0074）
        if ($request->attributes->get(EnterTenant::LEVEL) !== 'money') {
            $direct = ['bank_account' => null, 'bank_account_set' => $direct['bank_account'] !== null] + $direct;
        } else {
            $direct['bank_account_set'] = $direct['bank_account'] !== null;
        }
        // 鍵を貼る手順で開く Stripe のダッシュボードの画面（管理画面のソースに外の URL を書かない。テスト用の鍵の店はテスト環境の画面へ）
        $dashboard = 'https://dashboard.stripe.com/'.($stripe['mode'] === 'live' ? '' : 'test/');

        return [
            'stripe' => $stripe,
            'connect' => $this->connect->status(),
            'webhook' => $this->webhook(),
            'methods' => $this->methods->status(),
            'direct' => $direct + ['account_types' => DirectMethodSettings::ACCOUNT_TYPES],
            'dashboard' => ['apikeys' => $dashboard.'apikeys', 'webhooks' => $dashboard.'webhooks', 'payment_methods' => $dashboard.'settings/payment_methods'],
            // テスト用の鍵の欄の手順は、いつも Stripe のテスト環境の画面
            'test_dashboard' => ['apikeys' => 'https://dashboard.stripe.com/test/apikeys', 'webhooks' => 'https://dashboard.stripe.com/test/webhooks'],
        ];
    }

    /**
     * Stripe のダッシュボードに登録する Webhook の送信先（ショップの URL。StorefrontUrls）
     *
     * @return array{url: string|null, events: list<string>}
     */
    private function webhook(): array
    {
        return [
            'url' => StorefrontUrls::of(Tenant::current(), '/webhooks/stripe'),
            'events' => self::STRIPE_EVENTS,
        ];
    }
}
