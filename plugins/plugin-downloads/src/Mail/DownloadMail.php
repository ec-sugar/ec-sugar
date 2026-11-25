<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads\Mail;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 「ダウンロードのご案内」。注文番号・ファイルの数・期限・回数・ダウンロードの画面への署名つきのリンクだけ（ファイルの名前と中身は載せない）。
 * リンクだけでは開けない（照会か会員の本人。転送されたメールでは受け取れない）
 */
final class DownloadMail extends Mailable
{
    use RecordsMail;

    /**
     * @param  array{number: string, files: int, expires: string, limit: int, url: string}  $facts
     */
    public function __construct(
        public readonly string $shopName,
        public readonly array $facts,
    ) {}

    public function envelope(): Envelope
    {
        $shop = self::line($this->shopName);

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] ダウンロードのご案内（ご注文番号 '.$this->facts['number'].'）');
    }

    public function content(): Content
    {
        return new Content(text: 'downloads::mail.notice-text', with: ['shopName' => $this->shopName, 'facts' => $this->facts]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'downloads.link';
    }
}
