<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\LoginEntry;

/**
 * 会員のログインの画面に App の入口を置く口（ADR 0070）。呼ばれるのは、この店で使っている App だけ・店の文脈の中だけ。
 * 設定が足りない（外の認証の鍵がまだ）なら null を返す（入口を出さない）。
 * 入口を押した後に会員としてログインさせるのは、核の App\Storefront\Members\MemberSignIn::forApp だけ（App が会員の印を自分で作らない）
 */
interface LoginEntryProvider
{
    public function loginEntry(): ?LoginEntry;

    /**
     * ログインの途中で App がこのブラウザのセッションに置く値の名前（外の認証の state・確かめ待ちの値など）。
     * 核は、ログインの画面を開いた時と会員としてログインさせた時に、名指しで消す（途中の値を次のログインに持ち越さない）
     *
     * @return list<string>
     */
    public function sessionKeys(): array;

    /**
     * この会員がこの App のログインの手段をつないでいるときの一言（「LINE をつないでいます」。20 文字まで）。つないでいなければ null。
     * 核はマイページの見出しと、メールアドレスを変えた知らせ（前のアドレスへ）に載せる
     */
    public function linkedLabel(string $accountId): ?string;
}
