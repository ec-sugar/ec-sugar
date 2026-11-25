<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter\Mail;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

/**
 * 購読の確かめのメール（ダブルオプトイン。宣伝のメールではない）。載せるのは確かめのリンクと期限だけ
 */
final class ConfirmMail extends Mailable
{
    use RecordsMail;

    public function __construct(
        public readonly string $shopName,
        public readonly string $confirmUrl,
        public readonly int $hours,
    ) {}

    public function envelope(): Envelope
    {
        $shop = self::line($this->shopName);

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] お知らせのメールの登録を確定してください');
    }

    public function content(): Content
    {
        // 文だけのメール（text/plain）なので、店の名前を HTML の形に直さずそのまま届ける（テンプレートは {{ }} だけ。NewsletterMail と同じ）
        return new Content(text: 'newsletter::mail.confirm-text', with: [
            'shopName' => new HtmlString(self::line($this->shopName)),
            'confirm' => new HtmlString($this->confirmUrl),
            'hours' => $this->hours,
        ]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'newsletter.confirm';
    }
}
