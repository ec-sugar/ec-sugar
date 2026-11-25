<?php

declare(strict_types=1);

namespace App\Admin\Services;

use RuntimeException;

/**
 * 回復用のコードを間違え続けたので、決まった時間まで試させない（ADR 0038）。
 */
final class RecoveryThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('回復用のコードを間違えた回数が多いので、しばらく試せません');
    }
}
