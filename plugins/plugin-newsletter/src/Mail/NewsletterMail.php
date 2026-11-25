<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter\Mail;

use App\Core\Mail\RecordsMail;
use App\Core\Mail\SenderFacts;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\HtmlString;

/**
 * 配信のメール（店からのお知らせ。文だけ）。特定電子メール法の表示（送り主の名前・住所・問い合わせ先・配信の停止のリンクと、止められること）を
 * 本文の終わりに必ず載せ、ワンクリックの配信停止のヘッダー（List-Unsubscribe と List-Unsubscribe-Post。RFC 8058）を付ける。
 * 試しのメール（$test）は題の頭に【試し】を付け、停止のリンクは働かない合い言葉にする
 */
final class NewsletterMail extends Mailable
{
    use RecordsMail;

    public function __construct(
        public readonly string $shopName,
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly SenderFacts $sender,
        public readonly string $unsubscribeUrl,
        public readonly bool $test = false,
    ) {}

    public function envelope(): Envelope
    {
        // 差出人の名前は店の名前（改行は取り除く）。アドレスは運営の送信用。題の改行も取り除く（ヘッダーの差し込みを止める）
        $shop = self::line($this->shopName);
        $subject = self::line($this->subjectLine);

        return new Envelope(from: new Address((string) config('mail.from.address'), $shop), subject: ($this->test ? '【テスト】' : '').$subject);
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
        // 文だけのメール（text/plain）なので、店の人が書いた文を HTML の形に直さずそのまま届ける（& や < を &amp; にしない）。
        // テンプレートは {{ }} だけで書き（App の画面の決まり。ThemeTest）、生の文は HtmlString で渡す。HTML のメールにする時は、ここを消毒に替える
        $raw = static fn (string $text): HtmlString => new HtmlString($text);

        return new Content(text: 'newsletter::mail.campaign-text', with: [
            'shopName' => $raw(self::line($this->shopName)),
            'body' => $raw($this->bodyText),
            'senderName' => $raw(self::line($this->sender->name)),
            'senderAddress' => $raw(str_replace("\n", ' ', $this->sender->address)),
            'contacts' => array_map($raw, $this->sender->contacts),
            'unsubscribe' => $raw($this->unsubscribeUrl),
            'test' => $this->test,
        ]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return $this->test ? 'newsletter.test' : 'newsletter.campaign';
    }

    /**
     * 本番の配信は一斉の配信（送れなかった分だけを記録に残す。送った数は配信の画面に出る）。試しのメールは 1 通ずつ残す
     */
    public function recordBulk(): bool
    {
        return ! $this->test;
    }
}
