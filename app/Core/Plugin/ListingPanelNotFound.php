<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * 商品の画面の App の欄（ADR 0050）が無い: 入っていない・この店で使っていない・欄を出さない App
 * （どれも同じ答えにして、使っていない App の有無を見分けさせない）
 */
final class ListingPanelNotFound extends RuntimeException implements UserFacingException
{
    public function __construct()
    {
        parent::__construct('この App は、この店の商品の画面に欄を表示していません。');
    }

    public function status(): int
    {
        return 404;
    }
}
