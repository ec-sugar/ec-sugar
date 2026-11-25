<?php

declare(strict_types=1);

namespace App\Core\Mail;

use Illuminate\Mail\Mailable;

/**
 * 店が送るメールのクラス（Mailable）に付けると、送った記録（メールの記録。ADR 0095）に残る。
 *
 * - recordKind() で種類（<core か App の名前>.<何のメールか>）を返す
 * - 関係する注文は、送る所で forTransaction($id) を付ける（無くてもよい）
 * - 一斉の配信（メルマガの本番の配信）は recordBulk() で真を返す（送れなかった分だけを残す）
 *
 * 札は Laravel がメールの中身の値に添える（additionalMessageData）。送る直前の MessageSending で札を送るメールに結び、
 * 送る口（RecordingMailer）が送った・送れなかったを記録する。店の文脈の外で送ったメールは記録しない。
 * 店の DB に書くのは店の文脈の中だけで、宛先と件名は店の鍵で暗号化する
 *
 * @mixin Mailable
 */
trait RecordsMail
{
    private ?string $recordTransactionId = null;

    /**
     * 記録の種類（<core か App の名前>.<何のメールか>。例 core.order_paid・restock.available）
     */
    abstract public function recordKind(): string;

    /**
     * 一斉の配信なら真（送れなかった分だけを残す）
     */
    public function recordBulk(): bool
    {
        return false;
    }

    /**
     * ヘッダー（差出人の名前・件名）に入れる文を 1 行にする（改行で別の見出しを書き足させない。MailHeaderText）
     */
    protected static function line(string $text): string
    {
        return MailHeaderText::line($text);
    }

    /**
     * 関係する注文（メールの記録から注文の画面へ移れる）
     */
    public function forTransaction(?string $transactionId): static
    {
        $this->recordTransactionId = $transactionId === null ? null : strtolower($transactionId);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalMessageData(): array
    {
        return [...parent::additionalMessageData(), MailRecords::DATA_KEY => new MailTag($this->recordKind(), $this->recordTransactionId, $this->recordBulk())];
    }
}
