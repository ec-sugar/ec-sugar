<?php

declare(strict_types=1);

namespace App\Admin\Mail;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 設定 › メールの記録の「試しのメールを送る」（ADR 0095）。店の人が自分のアドレスへ送り、お客さんへのメールと同じ道（キュー・送り方）で
 * 届くか・記録に出るかを確かめる。差出人の名前は店の名前で、ほかのメールと同じ。載せるのは店の名前と送った時刻だけ
 */
final class ShopTestMail extends Mailable
{
    use RecordsMail;

    public function __construct(
        public readonly string $shopName,
        public readonly string $when,
    ) {}

    public function envelope(): Envelope
    {
        $shop = self::line($this->shopName);

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] テストメール');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.shop-test-text', with: ['shopName' => $this->shopName, 'when' => $this->when]);
    }

    /**
     * メールの記録の種類
     */
    public function recordKind(): string
    {
        return 'core.test';
    }
}
