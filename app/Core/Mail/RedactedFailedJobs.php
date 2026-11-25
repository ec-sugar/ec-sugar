<?php

declare(strict_types=1);

namespace App\Core\Mail;

use DateTimeInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;

/**
 * 失敗した処理の記録（failed_jobs）に残す例外の文から、メールアドレスを伏せる（ADR 0112）。
 *
 * メールの送信サービスは、宛先を断る時に「550 5.1.1 <owner@example.com>: Recipient address rejected」のように宛先を返すことがあり、
 * Laravel は例外の全文を中央 DB の failed_jobs.exception にそのまま書く。積んだ中身（payload）は暗号化していても（ShouldBeEncrypted）、
 * ここから運営やお客さんのアドレスが平文で残ってしまう。メールの記録（MailRecords）が生の文を残さず分類だけにしているのと同じ理由。
 * 伏せるのは記録する時だけで、ほかの操作（一覧・やり直し・消す）は元の部品にそのまま任せる。
 */
final readonly class RedactedFailedJobs implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    public const string MASK = '<メールアドレス>';

    public function __construct(private FailedJobProviderInterface $inner) {}

    /**
     * 文の中のメールアドレスの形を伏せる（<…> で囲んだもの・囲んでいないものの両方）
     */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/u', self::MASK, $text);
    }

    /**
     * @param  \Throwable  $exception
     */
    public function log($connection, $queue, $payload, $exception)
    {
        return $this->inner->log($connection, $queue, $payload, new RedactedJobFailure($exception));
    }

    public function ids($queue = null)
    {
        return $this->inner->ids($queue);
    }

    public function all()
    {
        return $this->inner->all();
    }

    public function find($id)
    {
        return $this->inner->find($id);
    }

    public function forget($id)
    {
        return $this->inner->forget($id);
    }

    public function flush($hours = null)
    {
        $this->inner->flush($hours);
    }

    public function count($connection = null, $queue = null)
    {
        return $this->inner instanceof CountableFailedJobProvider ? $this->inner->count($connection, $queue) : count($this->inner->all());
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->inner instanceof PrunableFailedJobProvider ? $this->inner->prune($before) : 0;
    }
}
