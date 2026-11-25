<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Models\Operator;
use App\Admin\Services\AppKeyBackup;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperationsOverview;
use App\Admin\Services\RecoveryKit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * 運営全体の画面（ADR 0042）。オーナーだけ（経路の RequireOwner）。中央の表だけを読み、店の DB には入らない。
 * 書くのは「APP_KEY の控えを取りました」の答えだけ（直前の再確認の後）
 */
final class OperationsController
{
    public function show(OperationsOverview $overview): JsonResponse
    {
        return response()->json($overview->present());
    }

    /**
     * 要対応の件数だけ（ADR 0043）。店のホームの上のオーナーへの赤い帯に使う。店の文脈の外の中央の API（店の中から中央の表を読まない）
     */
    public function alerts(OperationsOverview $overview): JsonResponse
    {
        return response()->json(['urgent' => $overview->urgentCount()]);
    }

    /**
     * 「APP_KEY の控えをサーバーの外に取りました」の答え。オーナーだけ・直前のパスキーの再確認の後（経路の印）。入力は無い。
     * 残すのは「いつ・誰が」だけで、鍵・設定の値は読まない・受け取らない・記録しない。最初の答えの時だけ中央の監査ログに残す（押し直しても増えない）
     */
    public function confirmAppKeyBackup(Request $request, AuditLog $audit): JsonResponse
    {
        $operator = $request->user('operator');

        if (! $operator instanceof Operator) {
            throw new LogicException('ログインした運営の経路です');
        }

        AppKeyBackup::confirm($operator, CarbonImmutable::now(), $audit, $request->ip());

        return response()->json(['confirmed_at' => AppKeyBackup::confirmedAt()?->toIso8601String()]);
    }

    /**
     * 復元のキットの今の様子（ADR 0111）。オーナーだけ。返すのは公開してよい値だけ（開ける鍵の指紋・いつ受け取ったか・作れる形か）
     */
    public function recoveryKit(): JsonResponse
    {
        $recipient = RecoveryKit::recipient();

        return response()->json([
            'available' => RecoveryKit::appKey() !== null,
            'fingerprint' => $recipient['fingerprint'] ?? null,
            'registered_at' => isset($recipient) ? $recipient['registered_at']->toIso8601String() : null,
            // ホストがいまバックアップの暗号化に使っている公開鍵の指紋（知らせが無ければ null）。上と違えば、このキットではバックアップを開けない
            'host_fingerprint' => RecoveryKit::hostFingerprint(),
            'confirmed_at' => AppKeyBackup::confirmedAt()?->toIso8601String(),
        ]);
    }

    /**
     * 復元のキットに入れる APP_KEY を、オーナーの画面へ渡す（ADR 0111）。オーナーだけ・直前のパスキーの再確認の後（経路の印）。
     * 渡すたびに監査ログに残す（誰が・いつ・どこから）。答えはブラウザにも途中の機械にも残させない（no-store）。入力は無い
     */
    public function recoveryKitMaterial(Request $request, AuditLog $audit): JsonResponse
    {
        $material = RecoveryKit::material($this->operator($request), $audit, $request->getHost(), $request->ip());

        if ($material === null) {
            return response()->json(['message' => 'このサーバーの APP_KEY は、復元のキットに入れられる形式ではありません（サーバーで sudo ec-sugar app-key を実行してください）'], 409);
        }

        // 1 回の本人確認で渡すのは 1 回だけ（別の用事の再確認から 5 分の間に、乗っ取られたセッションが黙って持ち出す道を塞ぐ）
        $request->session()->forget('auth.password_confirmed_at');

        return response()->json($material)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    /**
     * 保存したキットを開け直して確かめた後に、開ける鍵の公開の半分を受け取る（ADR 0111）。オーナーだけ・直前の再確認の後。
     * 鍵そのものから数えた指紋と、開け直したキットの APP_KEY から作った印が合う時だけ受ける。受けたら APP_KEY の控えの答えも残す
     */
    public function registerRecoveryKit(Request $request, AuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:8192'],
            'fingerprint' => ['required', 'string', 'regex:/\A[0-9A-F]{40}\z/'],
            'proof' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
        ]);

        if (! RecoveryKit::register($this->operator($request), $data['public_key'], $data['fingerprint'], $data['proof'], CarbonImmutable::now(), $audit, $request->ip())) {
            return response()->json(['message' => '保存したキットを確認できませんでした（キットを作り直してください）'], 422);
        }

        return $this->recoveryKit();
    }

    private function operator(Request $request): Operator
    {
        $operator = $request->user('operator');

        if (! $operator instanceof Operator) {
            throw new LogicException('ログインした運営の経路です');
        }

        return $operator;
    }
}
