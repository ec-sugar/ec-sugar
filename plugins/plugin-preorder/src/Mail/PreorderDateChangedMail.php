<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder\Mail;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 予約の商品の発売日が変わった知らせ（ADR 0073 の 5）。載せるのは注文番号・品物・前の日と新しい日・まとめて送る日だけ（名前とお届け先は載せない）
 */
final class PreorderDateChangedMail extends Mailable
{
    use RecordsMail;

    public const string SUBJECT = '予約の商品の発売日が変わりました';

    /**
     * @param  array{number: string, items: list<string>, from: string, to: string, shipsOn: string, later: bool, listing: string}  $facts
     */
    public function __construct(
        public readonly string $shopName,
        public readonly array $facts,
    ) {}

    public function envelope(): Envelope
    {
        $shop = self::line($this->shopName);

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] '.self::SUBJECT.'（ご注文番号 '.$this->facts['number'].'）');
    }

    public function content(): Content
    {
        return new Content(text: 'preorder::mail.changed-text', with: ['shopName' => $this->shopName, 'facts' => $this->facts]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'preorder.date_changed';
    }
}
