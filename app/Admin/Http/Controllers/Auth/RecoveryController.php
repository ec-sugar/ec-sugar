<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Auth;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorEmailRecovery;
use App\Admin\Services\OperatorRecoveryCodes;
use App\Admin\Services\RecoveryThrottled;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * パスキーをなくした時の回復用のコード（ADR 0038）。
 *
 * - 一覧と作り直しは、ログインした本人だけ。作り直しは直前の再確認（RequireRecentConfirmation）が要る
 * - 使うのはログインの前（パスキーが無い人の入口）。合えば登録のリンクを返し、画面がそこへ移ってパスキーを足す
 * - 合わない理由（メールが無い・コードが違う・使用済み・止めた運営）は言わない
 */
final class RecoveryController
{
    public function __construct(
        private readonly OperatorRecoveryCodes $codes,
        private readonly OperatorEmailRecovery $email,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->codes->summary($this->operator()));
    }

    public function store(Request $request): JsonResponse
    {
        if ($this->operator()->recentlyRecovered()) {
            return response()->json([
                'message' => '回復用のコードでログインしてから 24 時間は、コードを作り直せません。',
                'errors' => ['codes' => ['回復用のコードでログインしてから 24 時間は、コードを作り直せません。']],
            ], 422);
        }

        $codes = $this->codes->issue($this->operator(), $request->ip());

        return response()->json(['codes' => $codes] + $this->codes->summary($this->operator()))
            ->header('Cache-Control', 'no-store');
    }

    public function redeem(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        try {
            $result = $this->codes->redeem((string) $input['email'], (string) $input['code'], $request->ip());
        } catch (RecoveryThrottled $e) {
            $minutes = (int) ceil($e->retryAfter / 60);

            return response()->json([
                'message' => "間違えた回数が多いため、{$minutes} 分ほど待ってからお試しください。",
                'retry_after' => $e->retryAfter,
            ], 429)->header('Retry-After', (string) $e->retryAfter);
        }

        if ($result === null) {
            return response()->json([
                'message' => 'メールか回復用のコードが違います。',
                'errors' => ['code' => ['メールか回復用のコードが違います。使用済みのコードは使えません。']],
            ], 422);
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    /**
     * メールでの戻しの申し込み（ADR 0038 の区切り 2）。運営がいてもいなくても同じ答え
     */
    public function requestEmail(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'string', 'max:254']]);
        $this->email->request((string) $input['email'], $request->ip());

        return response()->json(['message' => '登録のあるメールアドレスなら、申し込みのリンクを送りました。15 分以内に、このブラウザで開いてください。'], 202);
    }

    /**
     * メールのリンクを開いた: 24 時間の待ちに入り、このブラウザに印を渡す
     */
    public function confirmEmail(Request $request): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string', 'max:128']]);
        $result = $this->email->confirm((string) $input['token'], $request->ip());

        if ($result === null) {
            return response()->json(['message' => 'リンクが違うか、期限（15 分）が切れています。もう一度申し込んでください。'], 422);
        }

        return response()->json(['ready_at' => $result['ready_at']->utc()->format('Y-m-d\TH:i:s\Z')])
            ->header('Cache-Control', 'no-store')
            ->withCookie(cookie(OperatorEmailRecovery::COOKIE, $result['browser'], (OperatorEmailRecovery::WAIT_HOURS + 72) * 60, '/auth/recovery', null, $request->isSecure() || app()->isProduction(), true, false, 'strict'));
    }

    /**
     * 24 時間の後に、同じブラウザで登録のリンクを受け取る
     */
    public function finishEmail(Request $request): JsonResponse
    {
        $cookie = $request->cookie(OperatorEmailRecovery::COOKIE);
        $result = $this->email->finish(is_string($cookie) ? $cookie : null, $request->ip());

        if ($result === null) {
            return response()->json(['message' => 'このブラウザには、受け付け中の申し込みがありません（取り消されたか、期限が過ぎたか、別のブラウザで開いたか、すでに使われています）。'], 422)
                ->withoutCookie(OperatorEmailRecovery::COOKIE, '/auth/recovery');
        }

        if ($result instanceof CarbonImmutable) {
            return response()->json(['message' => 'まだ待機期間中です。', 'ready_at' => $result->utc()->format('Y-m-d\TH:i:s\Z')], 409);
        }

        return response()->json($result)->header('Cache-Control', 'no-store')->withoutCookie(OperatorEmailRecovery::COOKIE, '/auth/recovery');
    }

    /**
     * ログイン中の本人が、ホームの知らせからメールでの戻しの待ちを取り消す
     */
    public function cancelEmail(Request $request): JsonResponse
    {
        $this->email->cancelFor($this->operator(), 'self', $request->ip());

        return response()->json(null, 204);
    }

    private function operator(): Operator
    {
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);

        return $operator;
    }
}
