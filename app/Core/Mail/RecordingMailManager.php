<?php

declare(strict_types=1);

namespace App\Core\Mail;

use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;

/**
 * Laravel のメールの係（mail.manager）を、記録する Mailer を作る形に差し替える（ADR 0095。CoreServiceProvider が extend で差し替える）。
 * 送り方（transport）は設定のまま。テストの array の transport もそのまま読める
 */
final class RecordingMailManager extends MailManager
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function build($config): Mailer
    {
        $mailer = new RecordingMailer(
            (string) ($config['name'] ?? 'ondemand'),
            $this->app['view'],
            $this->createSymfonyTransport($config),
            $this->app['events'],
        );

        if ($this->app->bound('queue')) {
            $mailer->setQueue($this->app['queue']);
        }

        return $mailer;
    }
}
