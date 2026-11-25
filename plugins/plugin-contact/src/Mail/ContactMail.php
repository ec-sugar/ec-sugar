<?php

declare(strict_types=1);

namespace EcSugar\PluginContact\Mail;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

/**
 * お問い合わせのメール（受け付けの知らせ・店からの返信・店への知らせ）。文だけのメール（HTML にしない）。
 * 受け付けの知らせと店への知らせには、お客さんの書いた文（名前・本文）を載せない（SendContactMail）
 */
final class ContactMail extends Mailable
{
    use RecordsMail;

    public const array SUBJECTS = [
        'received' => 'お問い合わせを受け付けました',
        'reply' => 'お問い合わせへのお返事',
        'shop' => '新しいお問い合わせが 1 件あります',
    ];

    /**
     * @param  array<string, mixed>  $facts
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
        $number = is_string($this->facts['number'] ?? null) ? '（受付番号 '.$this->facts['number'].'）' : '';

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] '.self::SUBJECTS[$this->kind].($this->kind === 'reply' ? $number : ''));
    }

    public function content(): Content
    {
        // 文だけのメール（text/plain）なので、店の名前・商品の名前・返信の文を HTML の形に直さずそのまま届ける（& や < を &amp; にしない）。
        // テンプレートは {{ }} だけで書き（App の画面の決まり。ThemeTest）、生の文は HtmlString で渡す（メルマガの App と同じ形）
        $raw = static fn (mixed $text): ?HtmlString => is_string($text) ? new HtmlString($text) : null;

        return new Content(text: 'contact::mail.'.($this->kind === 'shop' ? 'shop' : 'customer').'-text', with: [
            'kind' => $this->kind,
            'shopName' => $raw($this->shopName),
            'listing' => $raw($this->facts['listing'] ?? null),
            'reply' => $raw($this->facts['reply'] ?? null),
            'facts' => $this->facts,
        ]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'contact.'.$this->kind;
    }
}
