<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * 入っていないプラグインを使おうとした（外したプラグイン・知らない名前）
 */
final class PluginNotInstalled extends RuntimeException implements UserFacingException
{
    public function __construct()
    {
        parent::__construct('この App はインストールされていません。');
    }

    public function status(): int
    {
        return 404;
    }
}
