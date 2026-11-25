<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder\Mail;

use App\Core\Mail\RecordsMail;
use App\Core\Mail\SenderFacts;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\HtmlString;

/**
 * カゴ落ちのお知らせのメール（店からのお知らせ＝広告・宣伝のメールとして扱う。ADR 0067）。HTML と文の 2 つの形。
 * 載せるのは商品の今の名前と写真・「カートに戻す」のリンク・書き出しの一文だけで、値段・数・注文番号・名前・住所は載せない。
 * 特定電子メール法の表示（受け取ると選んだ人へ送っていること・配信の停止のリンク・送り主の名前と住所と問い合わせ先）を終わりに必ず載せ、
 * ワンクリックの配信停止のヘッダー（List-Unsubscribe と List-Unsubscribe-Post。RFC 8058）を付ける
 */
final class CartReminderMail extends Mailable
{
    use RecordsMail;

    public const string SUBJECT = 'お買い物の続きのご案内';

    public const string OPENING = 'ご注文の手続きが、お支払いの前で止まっている商品があります。下の「カートに戻す」から商品をカートに戻して、お買い物を続けられます。';

    /**
     * @param  list<array{title: string, photo: string|null}>  $items  photo は写真の URL（無ければ null）
     */
    public function __construct(
        public readonly string $shopName,
        public readonly ?string $opening,
        public readonly array $items,
        public readonly string $cartUrl,
        public readonly string $unsubscribeUrl,
        public readonly SenderFacts $sender,
        public readonly int $days,
    ) {}

    public function envelope(): Envelope
    {
        // 差出人の名前は店の名前（改行は取り除く）。アドレスは運営の送信用
        $shop = self::line($this->shopName);

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] '.self::SUBJECT);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        // HTML の形は {{ }} でエスケープして描く。文の形は & や < をそのまま見せるため、同じ値を HtmlString で渡す（文の形は HTML として読まれない）
        $raw = static fn (string $text): HtmlString => new HtmlString($text);
        $shop = self::line($this->shopName);
        $opening = self::line($this->opening ?? self::OPENING);

        return new Content(view: 'cart-reminder::mail.reminder', text: 'cart-reminder::mail.reminder-text', with: [
            'shopName' => $shop,
            'opening' => $opening,
            'items' => array_map(static fn (array $item): array => ['title' => self::line($item['title']), 'photo' => $item['photo']], $this->items),
            'cartUrl' => $this->cartUrl,
            'unsubscribe' => $this->unsubscribeUrl,
            'senderName' => self::line($this->sender->name),
            'senderAddress' => str_replace("\n", ' ', $this->sender->address),
            'contacts' => $this->sender->contacts,
            'days' => $this->days,
            'text' => [
                'shopName' => $raw($shop),
                'opening' => $raw($opening),
                'titles' => array_map(static fn (array $item): HtmlString => $raw(self::line($item['title'])), $this->items),
                'cartUrl' => $raw($this->cartUrl),
                'unsubscribe' => $raw($this->unsubscribeUrl),
                'senderName' => $raw(self::line($this->sender->name)),
                'senderAddress' => $raw(str_replace("\n", ' ', $this->sender->address)),
                'contacts' => array_map($raw, $this->sender->contacts),
            ],
        ]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'cart-reminder.reminder';
    }
}
