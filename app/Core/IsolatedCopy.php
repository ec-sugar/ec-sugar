<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\RequestInterface;

/**
 * 隔離した写し（ADR 0082）。本番のバックアップを使い捨ての VM に戻して確かめる時の形で、外へ何も出さない。
 * 印は env と compose（docker-compose.isolated.yml）の EC_SUGAR_ISOLATED（config の app.isolated）。ec-sugar restore --isolated が付ける。
 *
 * 網の守りは compose（app・queue・scheduler を外へ出る網から外し、定期の処理を止める）で、ここはアプリの側の守り（網の守りが外れても外へ送らない）:
 *   - Http の口（LINE・Slack と Webhook の知らせ・写真の取り込み・見本の写真・郵便番号）は、送る前に断る。網が届かない時と同じ
 *     ConnectionException にして、呼ぶ側のふだんの失敗の道（知らせの送り直し・郵便番号は手で入れる）に乗せる
 *   - Stripe の口（Core\Payment\Stripe\StripeApi::client）は支払いの代行へ送らない（店の鍵は店の DB にあり、写しでも開ける）
 *   - メールは Mailpit（ホストが mailpit の smtp）か、送らずに捨てる（array）。記録（log）には書かない（個人情報が記録に残る）
 *   - ショップにタグの App（解析・広告の計測）を差し込まない（写しを開いた人のブラウザから、本番の計測へ送らない。Storefront\Apps\StorefrontApps）
 *   - 管理画面に帯を出す（Admin の /auth/me の isolated）
 */
final class IsolatedCopy
{
    public const string REFUSED = '隔離した複製環境なので、外へは送りません';

    /** 写しでも送ってよいメールの形（ホストが mailpit の smtp・送らずに手元にためる array） */
    private const string MAILPIT_HOST = 'mailpit';

    public static function enabled(): bool
    {
        return config('app.isolated') === true;
    }

    /**
     * 隔離した写しなら、外への送信の口を閉じる（CoreServiceProvider の boot から。写しでなければ何もしない）
     */
    public static function guard(Factory $http): void
    {
        if (! self::enabled()) {
            return;
        }

        // 送る前の段（送信先を決めた後・送る前）で断る。偽の答え（Http::fake）より外側に積まれるので、テストの偽物にも届かない
        $http->globalRequestMiddleware(static function (RequestInterface $request): never {
            throw new ConnectionException(self::REFUSED.'（'.$request->getUri()->getHost().'）');
        });

        $mailer = config('mail.default');
        $mailpit = $mailer === 'smtp' && config('mail.mailers.smtp.host') === self::MAILPIT_HOST;

        if (! $mailpit && $mailer !== 'array') {
            config(['mail.default' => 'array']);
        }
    }
}
