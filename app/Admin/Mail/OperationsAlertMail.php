<?php

declare(strict_types=1);

namespace App\Admin\Mail;

use App\Core\Mail\MailHeaderText;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 動きに問題があることのオーナーへの知らせ（ADR 0043）。operations:alert が定期の処理の中で、キューを通さず直に送る。
 * 載せるのは「どこで（種類・店の名前）・何が（例外のクラスの短い名前と場所）・何回・いつから」と、運営の様子の要対応の新しい困りごと、
 * 「近いうちに」のうち 1 回だけ知らせると決めたもの（APP_KEY の控え）だけ。
 * 例外のメッセージ・お客さん・注文の中身は載せない（持っていない）。中身はログインした運営が管理画面の運営の様子で見る
 */
final class OperationsAlertMail extends Mailable
{
    /**
     * @param  list<array{kind: string, exception: string, location: string, shop: string|null, count: int, added: int|null, first_at: string}>  $incidents
     * @param  list<string>  $issues  困りごとの文（画面と同じ言い方）
     * @param  int  $more  載せきれなかったエラーの数
     * @param  list<string>  $reminders  「近いうちに」の困りごとの文（急ぎではないが、1 回だけ知らせるもの）
     */
    public function __construct(
        public readonly string $operatorName,
        public readonly array $incidents,
        public readonly array $issues,
        public readonly int $more = 0,
        public readonly array $reminders = [],
    ) {}

    public function envelope(): Envelope
    {
        $total = count($this->incidents) + $this->more + count($this->issues);

        // 急ぎのものが無く「近いうちに」だけの時は、壊れたと読める題にしない
        if ($total === 0) {
            return new Envelope(subject: '[EC-SUGAR] 近いうちに確認してほしいことがあります（'.count($this->reminders).' 件）');
        }

        return new Envelope(subject: "[EC-SUGAR] 動作に問題があります（{$total} 件）");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.operations-alert-text', with: [
            'name' => MailHeaderText::line($this->operatorName),
            'items' => $this->incidents,
            'troubles' => $this->issues,
            'reminders' => $this->reminders,
            'more' => $this->more,
            'adminUrl' => rtrim((string) config('admin.url'), '/').'/operations',
        ]);
    }
}
