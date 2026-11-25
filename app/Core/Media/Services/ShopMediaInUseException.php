<?php

declare(strict_types=1);

namespace App\Core\Media\Services;

use RuntimeException;

/**
 * 使っている所があるショップの写真は消さない（ADR 0028 区切り 1）
 */
final class ShopMediaInUseException extends RuntimeException
{
    /**
     * @param  list<string>  $usages  使っている所（'hero' など）
     */
    public function __construct(public readonly array $usages)
    {
        parent::__construct('この写真は使われているため削除できません（'.implode('・', $usages).'）。');
    }
}
