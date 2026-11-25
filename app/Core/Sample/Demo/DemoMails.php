<?php

declare(strict_types=1);

namespace App\Core\Sample\Demo;

use App\Core\Sample\Models\DemoMail;
use Illuminate\Support\Collection;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * デモの店のメールを送らずに止め、来訪者の受け箱に置く（ADR 0086）。
 *
 * - メールを送る直前（Laravel の MessageSending）に、店の文脈がデモの店なら必ず止める（受け箱に置けなくても送らない）
 * - 宛先が来訪者のアドレス（DemoVisitors）なら、その受け箱に件名と本文の文字を置く。ほかの宛先（店の人への知らせ・打ち込まれた他人のアドレス）は
 *   どこにも置かずに捨てる（デモの店の入力のアドレスは来訪者のアドレスに置き換わるので、ふだんは無い）
 * - 置く数には上限（受け箱ごとに新しい 50 通。古いものから消す）。店全体の上限（20,000 通）は最後の守りで、ふだんは届かない
 *   （小さいと、受け箱を作り続けてほかの来訪者のメールを押し出せる）。毎晩の戻しで全部消える
 * - 状態でもお金でもなく、監査の源泉にもならない（見本のための写し）ので、連鎖のロックとイベントは使わない（conventions 3 の例外）
 */
final class DemoMails
{
    /** 受け箱ごとに残す数（超えたら、その受け箱の古いものから消す） */
    public const int PER_MAILBOX = 50;

    /** 店全体の最後の守り（ふだんは届かない大きさ。ほかの来訪者のメールを押し出させないため、受け箱ごとの上限で先に止める） */
    public const int PER_SHOP = 20000;

    /** 本文の文字の上限（長いメールは切る） */
    public const int BODY_MAX = 20000;

    /**
     * 送る直前のメールを止める（デモの店の文脈でだけ呼ぶ）。受け箱に置けなくても、止めることは変えない
     */
    public function capture(Email $message): void
    {
        try {
            $this->store($message);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * 受け箱のメール（新しい順）
     *
     * @return Collection<int, DemoMail>
     */
    public function inbox(string $mailbox): Collection
    {
        return DemoMail::query()->where('mailbox', $mailbox)->orderByDesc('created_at')->orderByDesc('id')->limit(self::PER_MAILBOX)->get();
    }

    private function store(Email $message): void
    {
        $recipients = [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];
        $mailboxes = array_values(array_unique(array_filter(array_map(
            static fn (Address $address): ?string => DemoVisitors::mailboxOf($address->getAddress()),
            $recipients,
        ))));

        if ($mailboxes === []) {
            return;
        }

        $subject = mb_substr((string) $message->getSubject(), 0, 200);
        $body = mb_substr(self::text($message), 0, self::BODY_MAX);

        foreach ($mailboxes as $mailbox) {
            DemoMail::create(['mailbox' => $mailbox, 'subject' => $subject, 'body' => $body]);
            $this->trim($mailbox);
        }
    }

    /**
     * 本文の文字（文字の本文が無ければ HTML から文字だけを取り出す。HTML のまま見せない）
     */
    private static function text(Email $message): string
    {
        $text = self::read($message->getTextBody());

        if ($text !== '') {
            return $text;
        }

        $html = self::read($message->getHtmlBody());
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>|</p>|</div>|</tr>|</h[1-6]>#i', "\n", $html);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * @param  resource|string|null  $body
     */
    private static function read(mixed $body): string
    {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        return is_string($body) ? trim($body) : '';
    }

    /**
     * 受け箱ごとと店全体の上限を超えた古いメールを消す
     */
    private function trim(string $mailbox): void
    {
        $keep = DemoMail::query()->where('mailbox', $mailbox)->orderByDesc('created_at')->orderByDesc('id')->limit(self::PER_MAILBOX)->pluck('id');
        DemoMail::query()->where('mailbox', $mailbox)->whereNotIn('id', $keep)->delete();

        $count = DemoMail::query()->count();

        if ($count > self::PER_SHOP) {
            $old = DemoMail::query()->orderBy('created_at')->orderBy('id')->limit($count - self::PER_SHOP)->pluck('id');
            DemoMail::query()->whereIn('id', $old)->delete();
        }
    }
}
