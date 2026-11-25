<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 会員への事後の知らせ（AccountNotice）。何をしたかの一言と日時だけ。リンクは載せない（偽のメールのリンクに慣れさせない）
 */
final class AccountNoticeMail extends Mailable
{
    use RecordsMail;

    public function __construct(
        public readonly string $shopName,
        public readonly string $what,
        public readonly string $when,
    ) {}

    public function envelope(): Envelope
    {
        $shop = self::line($this->shopName);

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $shop),
            subject: '['.$shop.'] 会員アカウントの操作のお知らせ',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.customer-account-notice-text', with: ['shopName' => $this->shopName, 'what' => $this->what, 'when' => $this->when]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'core.account_notice';
    }
}
