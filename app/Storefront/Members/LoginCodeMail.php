<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Mail\RecordsMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 会員のログインのコード（ADR 0044 の 3）。載せるのはコードと期限だけ。リンクは載せない（偽のメールのリンクに慣れさせない）
 */
final class LoginCodeMail extends Mailable
{
    use RecordsMail;

    public function __construct(
        public readonly string $shopName,
        public readonly string $code,
        public readonly int $minutes,
        // App のログインの手段をつなぐためのコードなら、その手段の名前（「LINE」。ADR 0070）。形の違う名前は使わない
        public readonly ?string $linking = null,
    ) {}

    /**
     * つなぐ手段の名前（1 行の 20 文字まで。違えば null で、ふつうのログインのコードの文面にする）
     */
    public function linkingName(): ?string
    {
        return $this->linking !== null && $this->linking !== '' && mb_strlen($this->linking) <= 20 && preg_match('/\p{C}/u', $this->linking) !== 1 ? $this->linking : null;
    }

    public function envelope(): Envelope
    {
        // 差出人の名前は店の名前（改行は取り除く）。アドレスは運営の送信用。コードは件名に入れない（通知の画面に出さない）
        $shop = self::line($this->shopName);

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $shop),
            subject: '['.$shop.'] '.($this->linkingName() === null ? 'ログイン用の確認コード' : $this->linkingName().' との連携用の確認コード'),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.customer-login-code-text', with: ['shopName' => $this->shopName, 'code' => $this->code, 'minutes' => $this->minutes, 'linking' => $this->linkingName()]);
    }

    /**
     * メールの記録の種類（ADR 0095）
     */
    public function recordKind(): string
    {
        return 'core.login_code';
    }
}
