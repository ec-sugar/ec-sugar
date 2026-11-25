<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks\Mail;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

/**
 * 会員ランクが上がった知らせ（ADR 0079）。会員の今の様子の知らせで、商品の宣伝は載せない（広告・宣伝のメールにしない）。文の形だけ。
 * 載せるのは店の名前・新しいランクの名前と特典・マイページの会員ランクの画面のリンクだけで、購入の額・名前・注文は載せない
 */
final class RankUpMail extends Mailable
{
    use RecordsMail;

    /**
     * @param  list<string>  $benefits
     */
    public function __construct(
        public readonly string $shopName,
        public readonly string $rankName,
        public readonly array $benefits,
        public readonly string $pageUrl,
    ) {}

    public function envelope(): Envelope
    {
        // 差出人の名前は店の名前（改行は取り除く）。アドレスは運営の送信用
        $shop = self::line($this->shopName);

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: '['.$shop.'] 会員ランクが「'.self::line($this->rankName).'」になりました');
    }

    public function content(): Content
    {
        // 文の形（text/plain）は HTML として読まれないので、& や < をそのまま見せるため HtmlString で渡す
        $raw = static fn (string $text): HtmlString => new HtmlString($text);

        return new Content(text: 'member-ranks::mail.rank-up-text', with: ['text' => [
            'shopName' => $raw(self::line($this->shopName)),
            'rankName' => $raw(self::line($this->rankName)),
            'benefits' => array_map(static fn (string $b): HtmlString => $raw(self::line($b)), $this->benefits),
            'pageUrl' => $raw($this->pageUrl),
        ]]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'member-ranks.rank_up';
    }
}
