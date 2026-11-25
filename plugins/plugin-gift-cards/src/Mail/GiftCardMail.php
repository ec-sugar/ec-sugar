<?php

declare(strict_types=1);

namespace EcSugar\PluginGiftCards\Mail;

use App\Core\Mail\RecordsMail;
use App\Core\Money\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * ギフトカードのコードのメール（ADR 0076）。コードを載せるのはこのメールだけ（買った人と、贈る相手）。キューに載せない（コードを保存しないため。GiftCardIssuer が直に送る）
 */
final class GiftCardMail extends Mailable
{
    use RecordsMail;

    public const array SUBJECTS = [
        'buyer' => 'ギフトカードのコードをお届けします',
        'recipient' => 'ギフトカードが届きました',
    ];

    /**
     * @param  'buyer'|'recipient'  $kind
     * @param  array{code: string, amount: int, expiresAt: string, shopUrl: string, buyerName: string, message: string|null, gift: bool}  $facts
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $shopName,
        public readonly array $facts,
    ) {}

    public function envelope(): Envelope
    {
        // 差出人の名前は店の名前（改行は取り除く）。アドレスは運営の送信用。件名にコードは入れない
        $shop = self::line($this->shopName);

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] '.self::SUBJECTS[$this->kind]);
    }

    public function content(): Content
    {
        return new Content(text: 'gift-cards::mail.code-text', with: ['kind' => $this->kind, 'shopName' => $this->shopName, 'facts' => $this->facts, 'amountLabel' => Money::format($this->facts['amount'])]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'gift-cards.'.$this->kind;
    }
}
