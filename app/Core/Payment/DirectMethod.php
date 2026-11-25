<?php

declare(strict_types=1);

namespace App\Core\Payment;

/**
 * 店が受け取る支払いの手段（ADR 0074）。Stripe を通さない。
 * 銀行振込は先払い（入金待ちで待ち、店の人が入金を確かめて確定）、代金引換は届けた時に払う（払う前でも発送でき、店の人が入金を確かめて確定）。
 * 当日払い（ADR 0056）は予約の当日にお店で払う（代金引換と同じく払う前に受け付け、来店の時に店の人が入金を確かめる）。
 * 当日払いは注文の入力では選べず、App「予約」の 1 品の注文だけが使う
 */
enum DirectMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cod = 'cod';
    case OnSite = 'on_site';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => '銀行振込',
            self::Cod => '代金引換',
            self::OnSite => '当日払い',
        };
    }

    /**
     * 受け渡しの時に払う手段か（払う前に受け付けて、支払いの段へ進める。代金引換と当日払い）
     */
    public function paysOnHandover(): bool
    {
        return $this !== self::BankTransfer;
    }
}
