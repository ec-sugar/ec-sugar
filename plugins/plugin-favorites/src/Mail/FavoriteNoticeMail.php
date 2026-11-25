<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites\Mail;

use App\Core\Mail\RecordsMail;
use App\Core\Mail\SenderFacts;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\HtmlString;

/**
 * お気に入りの商品の値下げ・再入荷のお知らせ（店からのお知らせ＝広告・宣伝のメールとして扱う。ADR 0071）。文の形だけ。
 * 載せるのは商品の今の名前・値段の変わり方・商品の画面のリンクだけで、名前・住所・注文は載せない。
 * 特定電子メール法の表示（受け取ると選んだ人へ送っていること・配信の停止のリンク・送り主の名前と住所と問い合わせ先）を終わりに必ず載せ、
 * ワンクリックの配信停止のヘッダー（List-Unsubscribe と List-Unsubscribe-Post。RFC 8058）を付ける
 */
final class FavoriteNoticeMail extends Mailable
{
    use RecordsMail;

    public const string SUBJECT = 'お気に入りの商品のお知らせ';

    /**
     * @param  list<array{title: string, kind: string, before: string|null, after: string|null, url: string}>  $items
     */
    public function __construct(
        public readonly string $shopName,
        public readonly array $items,
        public readonly string $listUrl,
        public readonly string $unsubscribeUrl,
        public readonly SenderFacts $sender,
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
        // 文の形（text/plain）は HTML として読まれないので、& や < をそのまま見せるため HtmlString で渡す
        $raw = static fn (string $text): HtmlString => new HtmlString($text);

        // 公開の項目（$items など）は Blade に同じ名前で渡るので、描く値は text の下にまとめる
        return new Content(text: 'favorites::mail.notice-text', with: ['text' => [
            'shopName' => $raw(self::line($this->shopName)),
            'items' => array_map(static fn (array $item): array => [
                'title' => $raw(self::line($item['title'])),
                'line' => $raw($item['kind'] === 'price_drop' ? '値下げ: '.$item['before'].' → '.$item['after'].'（税込）' : '再入荷しました'),
                'url' => $raw($item['url']),
            ], $this->items),
            'listUrl' => $raw($this->listUrl),
            'unsubscribe' => $raw($this->unsubscribeUrl),
            'senderName' => $raw(self::line($this->sender->name)),
            'senderAddress' => $raw(str_replace("\n", ' ', $this->sender->address)),
            'contacts' => array_map($raw, $this->sender->contacts),
        ]]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'favorites.notice';
    }
}
