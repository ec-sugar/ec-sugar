<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Auth;

use App\Admin\Http\Middleware\EnsureOperatorIsActive;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorEmailRecovery;
use App\Admin\Services\OperatorSecurityNotices;
use App\Admin\Services\OperatorSessions;
use App\Admin\Services\RevokedSessions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * パスキーでのログインとログアウト。
 * 挑戦（challenge）はセッションに 1 回分だけ置き、検証で取り出して消す（同じ応答を再送しても通らない）。
 */
final class LoginController
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly Ceremony $ceremony,
    ) {}

    public function options(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();
        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(PasskeyVerificationRequest $request, VerifyPasskey $verify): JsonResponse
    {
        $passkey = $this->ceremony->run($request, 'operator.login_failed', null,
            static fn () => $verify($request->credential(), $request->verificationOptions()));

        $operator = $passkey->user;

        if (! $operator instanceof Operator || ! $operator->isActive()) {
            $this->audit->record('operator.login_failed', $operator instanceof Operator ? $operator : null, ['reason' => 'disabled'], ip: $request->ip());

            throw ValidationException::withMessages(['credential' => 'このアカウントではログインできません。']);
        }

        Auth::guard('operator')->login($operator);
        $request->session()->regenerate();
        $request->session()->put(EnsureOperatorIsActive::AUTHENTICATED_AT, CarbonImmutable::now()->getTimestamp());

        $operator->last_login_at = CarbonImmutable::now();
        $operator->save();

        // いつもと違う接続元なら本人へ知らせる（記録を残す前に見る。今回のログインを前の接続元に数えない。2）
        $unusual = app(OperatorSecurityNotices::class)->loginSucceeded($operator, $request->ip(), CarbonImmutable::now());
        // ログイン中のセッションの行（入ったパスキーを添える。パスキーを消したらこのセッションも切れる）
        app(OperatorSessions::class)->start($request, $operator, (string) $passkey->getKey(), $unusual);
        $this->audit->record('operator.login_succeeded', $operator, ['method' => 'passkey', 'passkey_id' => (string) $passkey->getKey()], ip: $request->ip());
        // 元のパスキーで入れたので、メールでの戻しの待ちは取り消す（乗っ取りの申し込みならここで止まる。ADR 0038）。
        // 戻ってから 24 時間のうちは取り消さない（盗んだコードで入った人に、本人の申し込みを消させない）
        if (! $operator->recentlyRecovered()) {
            app(OperatorEmailRecovery::class)->cancelFor($operator, 'login', $request->ip());
        }

        return response()->json(['operator' => SessionController::present($operator)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $operator = Auth::guard('operator')->user();

        if ($operator instanceof Operator) {
            $this->audit->record('operator.logout', $operator, ip: $request->ip());
        }

        // 先に番号を取り消し済みにする（ログアウトの前に始まったリクエストが、後からログインを書き戻しても締め出す）
        app(RevokedSessions::class)->revoke($request->session()->getId());
        $current = app(OperatorSessions::class)->current($request);

        if ($operator instanceof Operator && $current !== null) {
            app(OperatorSessions::class)->end($operator, $current, 'logout');
        }
        Auth::guard('operator')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }
}
