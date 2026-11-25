<?php

declare(strict_types=1);

namespace App\Core\Mail;

use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * 送る口で、送った・送れなかったを記録する Laravel の Mailer（ADR 0095）。
 *
 * 送る直前の MessageSending（デモの店はここで止まる）を通った後、実際に送る所（sendSymfonyMessage）だけを包む。
 * Laravel には「送れなかった」の出来事が無いので、送る所の例外を見て記録し、そのまま投げ直す（キューの仕事のやり直し・呼んだ所の扱いは今のまま）。
 * 記録が失敗しても、送ることは止めない（MailRecords が例外を飲む）
 */
final class RecordingMailer extends Mailer
{
    protected function sendSymfonyMessage(Email $message): ?SentMessage
    {
        MailRecords::sending($message);

        try {
            $sent = parent::sendSymfonyMessage($message);
        } catch (Throwable $e) {
            MailRecords::failed($message, $e);

            throw $e;
        }

        MailRecords::sent($message);

        return $sent;
    }
}
