<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * お客さんへの注文の知らせ（支払いの確定・発送・コンビニ払いの入金待ちと期限切れ・返金。ADR 0058・0048）。載せるのは注文番号・商品・合計・発送・照会のリンクだけ（住所・電話は載せない）
 */
final class OrderMail extends Mailable
{
    use RecordsMail;

    public const array SUBJECTS = [
        'order_paid' => 'ご注文ありがとうございます',
        'order_shipped' => 'ご注文の商品を発送しました',
        // コンビニ払い（ADR 0058）
        'order_awaiting_payment' => 'ご注文ありがとうございます（お支払いをお待ちしています）',
        'order_payment_lapsed' => 'お支払いの期限が過ぎたため、ご注文を取り消しました',
        // 返品・一部返金（ADR 0048）
        'order_refunded' => '返金しました',
    ];

    /**
     * @param  array{amountLabel: string, card: bool}|null  $refund  返金の知らせの中身（返した額の見せ方と、カードなど決済代行への返金か。ADR 0048）
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $shopName,
        public readonly OrderView $order,
        public readonly string $link,
        public readonly ?array $refund = null,
    ) {}

    public function subjectLine(): string
    {
        return '['.self::line($this->shopName).'] '.self::SUBJECTS[$this->kind].'（ご注文番号 '.$this->order->number().'）';
    }

    public function envelope(): Envelope
    {
        // 差出人の名前は店の名前（改行は取り除く）。アドレスは運営の送信用
        return new Envelope(
            from: new Address((string) config('mail.from.address'), self::line($this->shopName)),
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order', text: 'mail.order-text', with: ['kind' => $this->kind, 'shopName' => $this->shopName, 'order' => $this->order, 'link' => $this->link, 'refund' => $this->refund]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'core.'.$this->kind;
    }
}
