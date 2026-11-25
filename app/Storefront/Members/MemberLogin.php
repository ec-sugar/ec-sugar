<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Crypto\BlindIndex;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerLogin;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\ClientSource;

/**
 * ショップの会員のログイン（ADR 0044 の 3）。コードを出して送る数の制限。コードそのものは核（CustomerLogin）、送るのは SendLoginCode。
 *
 * - 送る数: メール × 接続元で 1 時間に 5 通、接続元だけで 1 時間に 20 通、メールごとに（接続元をまたいで）1 時間に 10 通・1 日に 20 通。
 *   超えても答えは同じ（送らないだけ）。店全体で 1 つの枠にはしない（誰かが使い切ると、店のすべてのお客さんが入れなくなる）
 * - 当てにくる数は「出したコードの数 × 1 つのコードで 5 回」で決まる（メールごとに 1 日 100 回まで。当てにくると本人にコードのメールが届き続ける）。
 *   外れの数での締め出しは置かない（他人が外れを重ねて本人を締め出せるため。見直し 2026-11-01）
 * - 数えるキーには店の ID を入れる（キャッシュの置き場によらず店ごと）。メールは索引で数える（平文を鍵に入れない）
 */
final class MemberLogin
{
    public const int PAIR_HOURLY_SENDS = 5;

    public const int SOURCE_HOURLY_SENDS = 20;

    public const int EMAIL_HOURLY_SENDS = 10;

    public const int EMAIL_DAILY_SENDS = 20;

    public function __construct(private readonly CustomerLogin $login) {}

    /**
     * @param  ?string  $linking  App のログインの手段をつなぐためのコードの時の、その手段の名前（「LINE」。ADR 0070）。
     *                            メールの文面を「LINE をつなぐためのコード」にし、心当たりが無ければ誰にも伝えないよう添える
     */
    public function send(string $email, ?string $ip, ?string $linking = null): void
    {
        $shop = (string) tenant()?->getTenantKey();
        $index = BlindIndex::of('email', $email);
        $source = ClientSource::of($ip);
        $keys = [
            ["member-code|{$shop}|pair|".hash('sha256', $source.'|'.$index), self::PAIR_HOURLY_SENDS, 3600],
            ["member-code|{$shop}|source|".$source, self::SOURCE_HOURLY_SENDS, 3600],
            ["member-code|{$shop}|email-hour|".$index, self::EMAIL_HOURLY_SENDS, 3600],
            ["member-code|{$shop}|email-day|".$index, self::EMAIL_DAILY_SENDS, 86_400],
        ];

        // 先に数えてから比べる（狭い上限から。同時に送っても上限を越えない）
        if (Throttle::firstOver($keys) !== null) {
            return;
        }

        SendLoginCode::dispatch($this->login->issue($email), $linking);
    }

    public function verify(string $email, string $code): ?CustomerAccount
    {
        return $this->login->verify($email, $code);
    }

    /**
     * コードだけを確かめる（メールの変更の新しいアドレス。台帳は触らない）
     */
    public function check(string $email, string $code): bool
    {
        return $this->login->check($email, $code);
    }
}
