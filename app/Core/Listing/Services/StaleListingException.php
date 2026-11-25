<?php

declare(strict_types=1);

namespace App\Core\Listing\Services;

use RuntimeException;

/**
 * 楽観ロックの衝突。画面が読んだ後に、別の人（または別の画面）が先に保存していた。
 */
final class StaleListingException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct("出品物は別の操作で更新されています（現在のバージョン {$currentVersion}）");
    }
}
