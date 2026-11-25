<?php

declare(strict_types=1);

namespace EcSugar\PluginShopNotify\Mail;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 店への知らせのメール（お店の人あて。文字だけ）。中身は ShopNotices::render の文で、個人情報は入らない
 */
final class ShopNoticeMail extends Mailable
{
    use RecordsMail;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $subjectLine,
        public readonly array $lines,
    ) {}

    public function envelope(): Envelope
    {
        // 件名の改行は取り除く（件名に別の見出しを書き足させない）
        return new Envelope(
            from: new Address((string) config('mail.from.address'), 'EC-SUGAR'),
            subject: self::line($this->subjectLine),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'shop-notify::mail.notice-text', with: ['lines' => $this->lines]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'shop-notify.'.$this->kind;
    }
}
