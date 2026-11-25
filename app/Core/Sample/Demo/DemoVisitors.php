<?php

declare(strict_types=1);

namespace App\Core\Sample\Demo;

/**
 * デモの店の来訪者（ADR 0086）。来訪者はセッションに合い言葉（token）を 1 つ持ち、そこから自分だけのメールアドレスと受け箱を作る。
 *
 * - アドレスは `demo-<受け箱>@example.com`（example.com は例のために取り置かれたドメインで、送っても誰にも届かない。そもそも送らない）
 * - 受け箱は合い言葉とアプリの鍵の HMAC の 24 文字。アドレスや受け箱を知っても合い言葉は分からないので、ほかの人の受け箱は開けない
 * - ショップの入力のメールアドレスは、どれも来訪者のアドレスに置き換わる（DemoVisitor の関所）。だから、デモの店の注文・会員・申し込みに
 *   付くメールは来訪者ごとに分かれ、止めたメールもその来訪者の受け箱にだけ入る
 */
final class DemoVisitors
{
    /** セッションに置く名前 */
    public const string SESSION = 'demo.visitor';

    /** 来訪者のアドレスのドメイン */
    public const string DOMAIN = 'example.com';

    private const string ADDRESS = '/\Ademo-([0-9a-f]{24})@example\.com\z/';

    /**
     * 新しい合い言葉（128 ビット）
     */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 合い言葉の形か（セッションの値を信じる前に確かめる）
     */
    public static function validToken(mixed $token): bool
    {
        return is_string($token) && preg_match('/\A[0-9a-f]{32}\z/', $token) === 1;
    }

    /**
     * 合い言葉から受け箱の名前
     */
    public static function mailbox(string $token): string
    {
        return substr(hash_hmac('sha256', 'demo-visitor|'.$token, (string) config('app.key')), 0, 24);
    }

    /**
     * 合い言葉から来訪者のメールアドレス
     */
    public static function address(string $token): string
    {
        return 'demo-'.self::mailbox($token).'@'.self::DOMAIN;
    }

    /**
     * アドレスが来訪者のアドレスなら、その受け箱の名前（ほかは null）
     */
    public static function mailboxOf(string $address): ?string
    {
        return preg_match(self::ADDRESS, strtolower(trim($address)), $m) === 1 ? $m[1] : null;
    }
}
