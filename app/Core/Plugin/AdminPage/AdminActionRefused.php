<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

use App\Core\UserFacingException;
use DomainException;

/**
 * App の画面の操作を、今の状態では行えない（ADR 0051）。文はそのまま店の人に見せる（内部の事情を含めない）
 */
final class AdminActionRefused extends DomainException implements UserFacingException
{
    public function status(): int
    {
        return 409;
    }
}
