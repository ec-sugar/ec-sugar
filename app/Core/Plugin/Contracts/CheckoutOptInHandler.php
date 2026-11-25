<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * 注文の入力の「お知らせのメールを受け取る」（App「メルマガ・一斉メール」）。核は注文の入力に切り替えを 1 つ出し、
 * 選んだ人の注文が確定した後に、その人のメールを App に渡すだけ。同意の記録・確かめのメール・配信は App が持つ。
 * 切り替えは**いつも選んでいない状態から**（同意は本人が選ぶ。特定電子メール法のオプトイン）。呼ばれるのは、この店で使っている時の店の文脈の中だけ
 */
interface CheckoutOptInHandler
{
    /** 切り替えの文字（「ショップからのお知らせをメールで受け取る」など。60 文字まで） */
    public function label(): string;

    /** 切り替えの下に添える一言（届く中身・止め方。200 文字まで。無ければ空） */
    public function note(): string;

    /**
     * 選んだ人の注文が確定した（注文のトランザクションの外。落ちても注文は変わらない）。
     * $memberId はログインした会員で、そのメールが注文のメールと同じ時だけ（確かめ済みのメール）。ほかは null
     */
    public function accepted(string $email, ?string $memberId, string $transactionId): void;
}
