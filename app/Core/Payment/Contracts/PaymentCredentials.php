<?php

declare(strict_types=1);

namespace App\Core\Payment\Contracts;

/**
 * 決済代行の鍵の抽象（ADR 0026）。核に見せるのは「どのアカウントで受けるか」だけで、鍵そのものは見せない。
 * 中身（Stripe の秘密鍵など）はドライバだけが知る。核のサービスはこれをドライバへ渡すだけ
 */
interface PaymentCredentials
{
    /**
     * どのアカウントで受けるか（決済代行の中のサブアカウント。既定なら null）。
     * 支払いの試み（PaymentAttempt）に控え、返金や問い合わせを同じアカウントの鍵で行うために使う
     */
    public function account(): ?string;

    /**
     * 本物のお金が動く鍵か、試しの鍵か（'live' か 'test'）。
     * 核は「本番の鍵でないなら準備中のショップでしか使わない」「支払いの試みに test_mode を控える」のためにこれだけを見る（ADR 0096）。
     * 決済代行ごとの言い方（Stripe の sk_live_ / sk_test_ など）はドライバの中に閉じる
     */
    public function mode(): string;
}
