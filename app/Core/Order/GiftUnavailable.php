<?php

declare(strict_types=1);

namespace App\Core\Order;

use RuntimeException;

/**
 * 贈り物の App をこの店で使っていない（使うのをやめた後に、贈り物の注文を確定しようとした。ADR 0055）
 */
final class GiftUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('現在、贈り物として送ることはできません。お届け先を入力して、もう一度お進みください。');
    }
}
