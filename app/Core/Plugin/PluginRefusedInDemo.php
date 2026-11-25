<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * デモの店（ADR 0086）で、外へ送る App（店への知らせ・LINE・計測タグ）を使おうとした。
 * App のデータを消そうとした時も（ADR 0092。毎晩作り直す見本の店なので、来訪者に消させない）
 */
final class PluginRefusedInDemo extends RuntimeException implements UserFacingException
{
    public function __construct(string $message = 'デモのお店では、外部へ送信する App（店への通知・LINE・計測のタグ）は使えません。')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }
}
