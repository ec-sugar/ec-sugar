<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * 1 件の画面の直す欄の言葉（App「お問い合わせ」の「返信する」。2026-11-05）。直す欄（AdminFormHandler）を持つ係が、これも実装したときだけ使う。
 * 直す欄が「保存する」でなく「送る」ような欄（返信をメールで送る）で、見出し・ボタン・送った後の知らせの言葉を App が決める。
 * 実装しない係は今までどおり（見出しは「直す」・ボタンは「保存する」・知らせは「保存しました。」）。どれも 1〜40 文字の 1 行
 */
interface AdminFormWording
{
    /** 欄の見出し（「返信する」など） */
    public function editTitle(): string;

    /** 送るボタンの文字（「返信を送る」など） */
    public function editSubmit(): string;

    /** 送った後の知らせ（「返信を送りました。」など） */
    public function editDone(): string;
}
