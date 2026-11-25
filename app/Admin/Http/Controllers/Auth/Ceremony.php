<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Auth;

use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Webauthn\Exception\WebauthnException;

/**
 * パスキーの儀式（登録・検証）を包む。
 *
 * パッケージは署名の不一致などで WebauthnException をそのまま投げる（500 になる）。
 * ここで 422 に直し、失敗を監査ログに残す。利用者への文言は理由を区別しない（攻撃者に手がかりを与えない）。
 */
final class Ceremony
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $ceremony
     * @return T
     */
    public function run(Request $request, string $failureType, ?Operator $operator, Closure $ceremony): mixed
    {
        try {
            return $ceremony();
        } catch (WebauthnException $e) {
            $this->audit->record($failureType, $operator, ['reason' => 'webauthn', 'detail' => mb_substr($e->getMessage(), 0, 200)], ip: $request->ip());

            throw ValidationException::withMessages(['credential' => 'パスキーを確認できませんでした。もう一度お試しください。']);
        } catch (ValidationException $e) {
            $this->audit->record($failureType, $operator, ['reason' => 'rejected', 'detail' => mb_substr($e->getMessage(), 0, 200)], ip: $request->ip());

            throw $e;
        }
    }
}
