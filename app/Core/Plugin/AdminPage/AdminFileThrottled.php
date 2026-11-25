<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * App の画面の書き出し・取り込みが続いている（届け先の入ったファイルを短い間にくり返し持ち出させない）
 */
final class AdminFileThrottled extends RuntimeException implements UserFacingException
{
    public function __construct()
    {
        parent::__construct('書き出し・取り込みが続いています。しばらくしてから、もう一度お試しください。');
    }

    public function status(): int
    {
        return 429;
    }
}
