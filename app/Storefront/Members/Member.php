<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Party\Models\CustomerAccount;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * ショップのログイン（auth:customer）が渡す会員。核の台帳を包むだけで、パスワードも「次回から自動で」も持たない（印は MemberCookie）
 */
final class Member implements Authenticatable
{
    /**
     * @param  string  $tenant  引いた店。今の店と違えば使わない（常駐のアプリで前の要求の会員が残っても、別の店では誰でもない）
     */
    public function __construct(
        public readonly CustomerAccount $account,
        public readonly string $tenant,
    ) {}

    /**
     * ログインした今の店の会員の台帳の ID（会員の特典で「見る人」にする。ADR 0079）。ゲスト・会員でなくなった台帳は null
     */
    public static function viewerId(): ?string
    {
        $member = Auth::guard('customer')->user();

        return $member instanceof self && $member->belongsHere() && $member->account->member_since !== null ? $member->account->id : null;
    }

    /**
     * 今の店で引いた会員か
     */
    public function belongsHere(): bool
    {
        return tenancy()->initialized && hash_equals($this->tenant, (string) tenant()?->getTenantKey());
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->account->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        throw new LogicException('会員はパスワードを持ちません（ADR 0023）');
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
