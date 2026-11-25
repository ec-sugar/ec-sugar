<?php

declare(strict_types=1);

namespace App\Storefront\View;

/**
 * ヘッダーの会員の入口（テーマ API 2 の $account。ADR 0044 の 3）。
 * ログイン中の会員なら「マイページ」（/account）、そうでなければ「ログイン」（/account/login）。
 * 誰の会員かは渡さない（メールなどの個人情報はテーマに出さない）。
 *
 * - url: ショップの中の道筋
 * - label: 「ログイン」か「マイページ」（文字のテーマは見せ、絵柄のテーマは読み上げの文に使う）
 * - member: 今の店の会員としてログインしているか
 * - current: 今、会員の画面（ログイン・コード・マイページ）を見ているか（aria-current に使う）
 */
final readonly class AccountLink
{
    public function __construct(
        public string $url,
        public string $label,
        public bool $member = false,
        public bool $current = false,
    ) {}
}
