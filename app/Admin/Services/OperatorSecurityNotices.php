<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Mail\OperatorSecurityMail;
use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * 運営のログインまわりの本人への知らせ（2、ADR 0038 の続き）。パスキーを足した・消した・いつもと違う接続元から入った、を本人のメールへ。
 * メールには接続元・パスキーの中身などの秘密や個人情報を載せない（OperatorSecurityMail の決まり）。知らせは記録（監査ログ）の後で、キューに積むだけ
 *
 * - 足した: 前から 1 本以上持っている運営にだけ（初めての登録は本人がしたばかりなので知らせない）
 * - 消した: いつも
 * - いつもと違う接続元: 過去 90 日の成功したログインに、同じ回線の範囲（IPv4 は /24、IPv6 は /64）が無いとき。初めてのログインは知らせない
 */
final class OperatorSecurityNotices
{
    /** いつもと違う接続元を見る期間（日） */
    public const int LOOKBACK_DAYS = 90;

    public function passkeyAdded(Operator $operator, string $passkeyName, int $passkeysBefore): void
    {
        if ($passkeysBefore < 1) {
            return;
        }

        $this->send($operator, OperatorSecurityMail::PASSKEY_ADDED, $passkeyName);
    }

    public function agentTokenIssued(Operator $operator, string $tokenName): void
    {
        $this->send($operator, OperatorSecurityMail::AGENT_TOKEN_ISSUED, $tokenName);
    }

    public function passkeyRemoved(Operator $operator, string $passkeyName): void
    {
        $this->send($operator, OperatorSecurityMail::PASSKEY_REMOVED, $passkeyName);
    }

    /**
     * 成功したログインの記録を残す前に呼ぶ（今回のログインを「前の接続元」に数えないため）。知らせたら true（ログイン中の端末の一覧とホームにも印を出す）
     */
    public function loginSucceeded(Operator $operator, ?string $ip, CarbonImmutable $at): bool
    {
        $network = self::network($ip);

        if ($network === null) {
            return false;
        }

        $seen = AuditEvent::query()
            ->where('operator_id', $operator->id)
            ->where('type', 'operator.login_succeeded')
            ->where('occurred_at', '>=', $at->subDays(self::LOOKBACK_DAYS)->utc())
            ->whereNotNull('ip')
            ->pluck('ip')
            ->map(static fn (mixed $previous): ?string => self::network(is_string($previous) ? $previous : null))
            ->filter();

        // 初めてのログイン（前の記録が無い）は知らせない。同じ回線の範囲から入ったことがあれば知らせない
        if ($seen->isEmpty() || $seen->contains($network)) {
            return false;
        }

        $this->send($operator, OperatorSecurityMail::NEW_LOCATION_LOGIN, null, $at);

        return true;
    }

    /**
     * 回線の範囲（IPv4 は /24、IPv6 は /64）。IP でなければ null
     */
    public static function network(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        // IPv4 を IPv6 に写した形（::ffff:a.b.c.d）は IPv4 として扱う
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return strlen($packed) === 4
            ? '4:'.bin2hex(substr($packed, 0, 3))
            : '6:'.bin2hex(substr($packed, 0, 8));
    }

    private function send(Operator $operator, string $kind, ?string $passkeyName, ?CarbonImmutable $at = null): void
    {
        Mail::to($operator->email)->queue(new OperatorSecurityMail($kind, $operator->name, subjectName: $passkeyName, readyAt: $at));
    }
}
