<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * App の画面（ADR 0051）が無い・記録が無い: 入っていない・この店で使っていない・画面を持たない App、無い ID、今は押せない操作
 * （どれも同じ答えにして、使っていない App の有無やほかの記録の有無を見分けさせない）
 */
final class AdminRecordNotFound extends RuntimeException implements UserFacingException
{
    public function __construct()
    {
        parent::__construct('見つかりませんでした。');
    }

    public function status(): int
    {
        return 404;
    }
}
