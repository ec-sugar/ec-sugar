<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 会員のメールアドレスが変わった知らせ（ADR 0044 の 3）。前のアドレスへ送る。新しいアドレスは載せない（前のアドレスの持ち主でない人に見せない）
 */
final class EmailChangedMail extends Mailable
{
    use RecordsMail;

    /**
     * @param  list<string>  $linked  つないでいる App のログインの手段の一言（「LINE をつないでいます」）
     */
    public function __construct(
        public readonly string $shopName,
        public readonly array $linked = [],
    ) {}

    public function envelope(): Envelope
    {
        $shop = self::line($this->shopName);

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $shop),
            subject: '['.$shop.'] 会員アカウントのメールアドレスが変更されました',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.customer-email-changed-text', with: ['shopName' => $this->shopName, 'linked' => $this->linked]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'core.email_changed';
    }
}
