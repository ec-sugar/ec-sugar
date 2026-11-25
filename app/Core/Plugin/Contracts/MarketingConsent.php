<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * 店からのお知らせ（広告・宣伝のメール）の受け取りに、そのメールの持ち主が同意しているか（ADR 0067）。
 * 同意の記録を持つ App（「メルマガ・一斉メール」）が答え、ほかの App（「カゴ落ちのお知らせ」など）は核の MarketingConsents を通して聞く
 * （App 同士を直につながない）。答える App は注文の入力の「お知らせのメールを受け取る」の係（CheckoutOptInHandler）と同じもので、
 * その係がこの口も実装したときだけ使う（店で 1 つ。PluginDefinition は変えない）。呼ばれるのは店の文脈の中だけ
 */
interface MarketingConsent
{
    /**
     * このメールの持ち主が、お知らせのメールの受け取りに同意していて（確かめ済み）、止めていないか
     */
    public function consents(string $email): bool;

    /**
     * 受け取りを止める（ほかの App のお知らせのメールの配信の停止のリンクから）。$via は link（停止の画面のボタン）か one_click（メールのソフトの停止）。
     * 止めたら真、もともと届けていなければ偽
     */
    public function withdraw(string $email, string $via): bool;
}
