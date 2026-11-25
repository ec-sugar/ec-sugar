<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Auth;

use App\Admin\Http\Middleware\EnsureOperatorIsActive;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorEnrollment;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorEmailRecovery;
use App\Admin\Services\OperatorEnrollments;
use App\Admin\Services\OperatorSecurityNotices;
use App\Admin\Services\OperatorSessions;
use App\Admin\Services\SystemVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * 登録トークンでパスキーを登録する（最初の 1 本、または失くした後の 1 本）。
 *
 * 1. POST /auth/enrollment       トークンを確かめ、セッションに「登録中の運営」を置く（まだログインしていない）
 * 2. GET  /auth/enrollment/options  その運営の登録オプションを発行
 * 3. POST /auth/enrollment/passkey  パスキーを検証して保存し、トークンを使用済みにしてログインする
 *
 * 3 はトークンの行ロックの中で行う。パスキーの検証に失敗したらトークンは使用済みにならない（やり直せる）。
 */
final class EnrollmentController
{
    private const string SESSION_KEY = 'admin.enrollment_id';

    public function __construct(
        private readonly OperatorEnrollments $enrollments,
        private readonly AuditLog $audit,
        private readonly Ceremony $ceremony,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'size:64']])['token'];
        $enrollment = $this->enrollments->find($token);

        if ($enrollment === null) {
            $this->audit->record('operator.enrollment_rejected', null, ['reason' => 'invalid_or_expired'], ip: $request->ip());

            throw ValidationException::withMessages(['token' => '登録用のリンクが無効か、期限が切れています。']);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $enrollment->id);

        // バージョンは登録用のリンクを確かめた後にだけ返す（誰でも見られる所に出すと、狙う弱さのバージョンを教えることになる）
        return response()->json(['operator' => SessionController::present($this->operatorOf($enrollment)), 'system' => SystemVersion::present()]);
    }

    public function options(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $operator = $this->operatorOf($this->current($request));
        $options = $generate($operator);
        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(PasskeyRegistrationRequest $request, StorePasskey $store): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:100']])['name'];
        $enrollmentId = $this->current($request)->id;
        $operator = $this->operatorOf($this->current($request));
        $before = $operator->passkeys()->count();

        $passkey = $this->ceremony->run($request, 'operator.enrollment_failed', $operator,
            fn () => DB::connection($operator->getConnectionName())->transaction(function () use ($request, $store, $operator, $name, $enrollmentId) {
                if ($this->enrollments->consume($enrollmentId) === null) {
                    throw ValidationException::withMessages(['token' => '登録用のリンクが無効か、期限が切れています。']);
                }

                return $store($operator, $name, $request->credential(), $request->registrationOptions());
            }));

        $request->session()->forget(self::SESSION_KEY);
        Auth::guard('operator')->login($operator);
        $request->session()->regenerate();
        $request->session()->put(EnsureOperatorIsActive::AUTHENTICATED_AT, CarbonImmutable::now()->getTimestamp());
        app(OperatorSessions::class)->start($request, $operator, (string) $passkey->getKey());

        $this->audit->record('operator.passkey_added', $operator, ['passkey_id' => (string) $passkey->getKey(), 'via' => 'enrollment', 'enrollment_id' => $enrollmentId], ip: $request->ip());
        $this->audit->record('operator.login_succeeded', $operator, ['method' => 'enrollment'], ip: $request->ip());
        // 前からパスキーがある運営に、登録のリンクで足された: 本人へ知らせる（初めての登録は知らせない。2）
        app(OperatorSecurityNotices::class)->passkeyAdded($operator, $name, $before);

        // サーバーやオーナーが出した登録のリンクで入り直した: メールでの戻しの待ちを取り消す（戻ってから 24 時間のうちは取り消さない。ADR 0038）
        if (! $operator->recentlyRecovered()) {
            app(OperatorEmailRecovery::class)->cancelFor($operator, 'login', $request->ip());
        }

        return response()->json(['operator' => SessionController::present($operator)], 201);
    }

    private function current(Request $request): OperatorEnrollment
    {
        $id = $request->session()->get(self::SESSION_KEY);
        $enrollment = is_string($id) ? OperatorEnrollment::query()->find($id) : null;

        if ($enrollment === null || ! $enrollment->isUsable()) {
            throw ValidationException::withMessages(['token' => '登録用のリンクが無効か、期限が切れています。']);
        }

        return $enrollment;
    }

    private function operatorOf(OperatorEnrollment $enrollment): Operator
    {
        $operator = $enrollment->operator()->first();

        if ($operator === null || ! $operator->isActive()) {
            throw ValidationException::withMessages(['token' => '登録用のリンクが無効か、期限が切れています。']);
        }

        return $operator;
    }
}
