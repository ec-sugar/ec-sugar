<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * 同じ記録への操作が続いている（ADR 0051。お休みと再開のくり返しで、お客さんへの知らせが重ならないように）
 */
final class AdminActionThrottled extends RuntimeException implements UserFacingException
{
    public function __construct()
    {
        parent::__construct('この記録への操作が続いています。しばらくしてから、もう一度お試しください。');
    }

    public function status(): int
    {
        return 429;
    }
}
