<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock\Mail;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 再入荷のお知らせのメール（お客さんが頼んだ知らせ。宣伝のメールではない）。載せるのは商品と種類の名前・ショップのリンク・確かめと取り消しのリンクだけ
 */
final class RestockMail extends Mailable
{
    use RecordsMail;

    public const array SUBJECTS = [
        'confirm' => '入荷のお知らせの登録を確定してください',
        'registered' => '入荷のお知らせを登録しました',
        'restocked' => '入荷のお知らせ',
    ];

    /**
     * @param  array{title: string, variant: string, product: string, confirm: string|null, cancel: string|null, message: string|null, hours: int}  $facts
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $shopName,
        public readonly array $facts,
    ) {}

    public function envelope(): Envelope
    {
        // 差出人の名前は店の名前（改行は取り除く）。アドレスは運営の送信用
        $shop = self::line($this->shopName);
        $title = self::line($this->facts['title']);
        $subject = self::SUBJECTS[$this->kind].($this->kind === 'restocked' && $title !== '' ? '（'.mb_strimwidth($title, 0, 60, '…').'）' : '');

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] '.$subject);
    }

    public function content(): Content
    {
        return new Content(text: 'restock::mail.notice-text', with: ['kind' => $this->kind, 'shopName' => $this->shopName, 'facts' => $this->facts]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'restock.'.$this->kind;
    }
}
