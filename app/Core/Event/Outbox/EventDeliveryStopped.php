<?php

declare(strict_types=1);

namespace App\Core\Event\Outbox;

use RuntimeException;

/**
 * 受け手が同じ記録で何度も落ちたので、その記録を止めた（ADR 0089）。壊れたら知らせる仕組み（ADR 0043）へ報告するための印で、投げない。
 * 文には受け手の名前と記録の番号だけを入れる（個人情報・例外の文は入れない）
 */
final class EventDeliveryStopped extends RuntimeException
{
    public function __construct(public readonly string $receiver, public readonly int $sequence, public readonly string $error)
    {
        parent::__construct("イベントの処理 {$receiver} が記録 {$sequence} で何度も落ちたので止めた（{$error}）");
    }
}
