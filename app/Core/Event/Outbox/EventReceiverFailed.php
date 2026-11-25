<?php

declare(strict_types=1);

namespace App\Core\Event\Outbox;

use Throwable;

/**
 * 記録の受け手が落ちた・何度も落ちたので止めた（ADR 0089）。送り出し役が、店の文脈の中・元の記録の処理の ID の下で出す。
 * 壊れたら知らせる仕組み（ADR 0043）が聞いて、中央のエラーの記録に数える（核は管理画面を知らないので、つなぐのは管理画面のサービスプロバイダ）。
 * 例外の報告（report）は要求の中のエラーしか数えないので、見回り（CLI）・裏の仕事の中で落ちた受け手もここで数える
 */
final readonly class EventReceiverFailed
{
    public function __construct(
        public string $receiver,
        public int $sequence,
        public Throwable $error,
        public bool $stopped = false,
    ) {}
}
