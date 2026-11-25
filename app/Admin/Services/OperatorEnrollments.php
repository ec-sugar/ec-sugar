<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Models\Operator;
use App\Admin\Models\OperatorEnrollment;
use App\Admin\Models\OperatorPasskey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * パスキーの登録トークン（ADR 0013）。パスワードを持たない運営が、最初の 1 本（または失くした後の 1 本）を登録する唯一の入口。
 *
 * - 発行は CLI・オーナーのメンバーの画面（スタッフの分）・回復用のコード（本人、ADR 0038）。平文は発行時に 1 度だけ返し、保存は SHA-256
 * - 新しく発行すると、同じ運営の未使用のトークンは即座に失効する
 * - 使うときは行ロックを取って「未使用・期限内・運営が有効」を確かめ直し、使用済みにする（同時に 2 回使えない）
 */
final class OperatorEnrollments
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * まだ誰もパスキーを持っていない（入れたばかりの）ときだけ、期限を長くする（ADR 0031）。
     *
     * @return array{enrollment: OperatorEnrollment, token: string, url: string, minutes: int}
     */
    public function issue(Operator $operator, string $issuedBy): array
    {
        $token = bin2hex(random_bytes(32));
        $minutes = OperatorPasskey::query()->exists()
            ? (int) config('admin.enrollment_minutes')
            : (int) config('admin.first_enrollment_minutes');

        $enrollment = DB::connection($operator->getConnectionName())->transaction(function () use ($operator, $token, $minutes): OperatorEnrollment {
            OperatorEnrollment::query()
                ->where('operator_id', $operator->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', CarbonImmutable::now())
                ->update(['expires_at' => CarbonImmutable::now()]);

            return OperatorEnrollment::create([
                'operator_id' => $operator->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => CarbonImmutable::now()->addMinutes($minutes),
            ]);
        });

        $this->audit->record('operator.enrollment_issued', $operator, [
            'enrollment_id' => $enrollment->id,
            'issued_by' => $issuedBy,
            'expires_at' => $enrollment->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        // トークンは URL の断片（#）に載せる。断片はサーバーのアクセスログにも Referer にも出ない
        return [
            'enrollment' => $enrollment,
            'token' => $token,
            'url' => config('admin.url').'/enroll#'.$token,
            'minutes' => $minutes,
        ];
    }

    /**
     * トークンから有効な登録を探す（読むだけ。使用済みにはしない）。
     */
    public function find(string $token): ?OperatorEnrollment
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return null;
        }

        $enrollment = OperatorEnrollment::query()->where('token_hash', hash('sha256', $token))->first();

        return $enrollment !== null && $this->usable($enrollment) ? $enrollment : null;
    }

    /**
     * 行ロックを取って確かめ直し、使用済みにする。呼び出し側のトランザクションの中で呼ぶ。
     */
    public function consume(string $enrollmentId): ?OperatorEnrollment
    {
        $enrollment = OperatorEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->first();

        if ($enrollment === null || ! $this->usable($enrollment)) {
            return null;
        }

        $enrollment->used_at = CarbonImmutable::now();
        $enrollment->save();

        return $enrollment;
    }

    private function usable(OperatorEnrollment $enrollment): bool
    {
        return $enrollment->isUsable() && ($enrollment->operator()->first()?->isActive() ?? false);
    }
}
