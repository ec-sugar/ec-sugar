<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * App のデータを消せない（ADR 0092）: 消す口を持たない App・この店で使っている App・App がまだ消せないと言った（払ったお客さんの受け取りが残っているなど）
 */
final class PluginPurgeRefused extends RuntimeException implements UserFacingException
{
    public static function noPurger(): self
    {
        return new self('この App には、データを削除する機能がありません。');
    }

    public static function inUse(): self
    {
        return new self('この店で使っている App のデータは削除できません。先に「使わない」にしてください。');
    }

    public function status(): int
    {
        return 422;
    }
}
