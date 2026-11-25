<?php

declare(strict_types=1);

namespace EcSugar\PluginGift\Mail;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 贈り物の買う人への知らせ（ADR 0055 の 5）。載せるのは注文番号・品物・期限・リンクだけ（受け取る人の名前とお届け先は載せない）
 */
final class GiftMail extends Mailable
{
    use RecordsMail;

    public const array SUBJECTS = [
        'claimed' => '贈り物が受け取られました',
        'expiring' => '贈り物の受け取りの期限が近づいています',
        'expired' => '贈り物の期限が過ぎたため、ご注文を取り消して返金しました',
        'cancelled' => '贈り物のご注文を取り消しました',
    ];

    /**
     * @param  array{number: string, items: list<string>, expiresOn: string|null, link: string|null, manage: string|null}  $facts
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $shopName,
        public readonly array $facts,
    ) {}

    public function envelope(): Envelope
    {
        $shop = self::line($this->shopName);

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] '.self::SUBJECTS[$this->kind].'（ご注文番号 '.$this->facts['number'].'）');
    }

    public function content(): Content
    {
        return new Content(text: 'gift::mail.notice-text', with: ['kind' => $this->kind, 'shopName' => $this->shopName, 'facts' => $this->facts]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'gift.'.$this->kind;
    }
}
