<?php

declare(strict_types=1);

namespace App\Core\Party\Services;

use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerSession;
use Carbon\CarbonImmutable;

/**
 * 会員の印（ADR 0044 の 3）。コードで入った後、ブラウザに合い言葉を渡し、DB には SHA-256 だけを置く。
 *
 * - 30 日。使うたびに延ばす（書くのは 1 時間に 1 回まで）。取り消した・期限切れ・止めた台帳の印は効かない
 * - 店の DB にあるので、ほかの店の印は引けない（Cookie を別の店のホストへ持ち込んでも入れない）
 */
final class CustomerSessions
{
    public const int DAYS = 30;

    /** 使い続けても、ログインからこの日数で切れる（盗まれた印を延ばし続けさせない） */
    public const int MAX_DAYS = 90;

    /** 本人の再確認が効く時間（メールの変更・パスキー・退会の前。ADR 0044 の 3） */
    public const int CONFIRM_MINUTES = 10;

    /**
     * @param  ?string  $via  入り方（code・passkey・App の名前。印の行に残す。ADR 0070）
     */
    public function start(CustomerAccount $account, ?string $via = null): string
    {
        $token = bin2hex(random_bytes(32));
        $now = CarbonImmutable::now();
        CustomerSession::create([
            'customer_account_id' => $account->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $now->addDays(self::DAYS),
            'last_used_at' => $now,
            'via' => $via !== null && preg_match('/\A[a-z][a-z0-9-]{0,31}\z/', $via) === 1 ? $via : null,
        ]);

        return $token;
    }

    /**
     * 印から会員を引く。効かなければ null
     */
    public function resolve(?string $token): ?CustomerAccount
    {
        return $this->resolveAndRenew($token)[0] ?? null;
    }

    /**
     * 印から会員を引き、使ったので期限を延ばす（書くのは 1 時間に 1 回まで）。延ばした時は true（呼ぶ側が Cookie も出し直す）。
     * ログインから 90 日を過ぎた印は、使っていても効かない
     *
     * @return array{0: CustomerAccount, 1: bool}|null
     */
    public function resolveAndRenew(?string $token): ?array
    {
        $now = CarbonImmutable::now();
        $session = $this->live($token);
        $account = $session === null ? null : CustomerAccount::query()->whereKey($session->customer_account_id)->whereNull('disabled_at')->whereNotNull('member_since')->first();

        if ($session === null || $account === null || $session->created_at->lessThan($now->subDays(self::MAX_DAYS))) {
            return null;
        }

        if ($session->last_used_at->greaterThanOrEqualTo($now->subHour())) {
            return [$account, false];
        }

        $expires = min($now->addDays(self::DAYS), $session->created_at->addDays(self::MAX_DAYS));
        CustomerSession::query()->whereKey($session->id)->whereNull('revoked_at')->update(['last_used_at' => $now, 'expires_at' => $expires]);

        return [$account, true];
    }

    /**
     * この印で本人を確かめた（再確認のコードかパスキーが合った）
     */
    public function confirm(?string $token): void
    {
        $session = $this->live($token);

        if ($session !== null) {
            CustomerSession::query()->whereKey($session->id)->update(['confirmed_at' => CarbonImmutable::now()]);
        }
    }

    /**
     * この印で、10 分のうちに本人を確かめたか
     */
    public function confirmedRecently(?string $token): bool
    {
        $confirmed = $this->live($token)?->confirmed_at;

        return $confirmed !== null && $confirmed->greaterThan(CarbonImmutable::now()->subMinutes(self::CONFIRM_MINUTES));
    }

    public function end(?string $token): void
    {
        if ($token === null || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return;
        }

        CustomerSession::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]);
    }

    /**
     * その会員のすべての印を取り消す（メールの変更・止めた時など）
     */
    public function endAll(CustomerAccount $account): void
    {
        CustomerSession::query()->where('customer_account_id', $account->id)->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]);
    }

    /**
     * その会員の印を、この印（$keepToken）のほかは取り消す（メールの変更の後）
     */
    public function endAllBut(CustomerAccount $account, ?string $keepToken): void
    {
        $query = CustomerSession::query()->where('customer_account_id', $account->id)->whereNull('revoked_at');

        if ($keepToken !== null) {
            $query->where('token_hash', '!=', hash('sha256', $keepToken));
        }

        $query->update(['revoked_at' => CarbonImmutable::now()]);
    }

    private function live(?string $token): ?CustomerSession
    {
        if ($token === null || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return null;
        }

        return CustomerSession::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', CarbonImmutable::now())->first();
    }
}
