<?php

declare(strict_types=1);

namespace App\Core\Theme\Services;

use RuntimeException;

/**
 * 読んだあとに、テーマの値のバージョンが別の操作で変わっていた（または、無いと思って作ろうとしたバージョンがもうあった）
 */
final class StaleThemeVersionException extends RuntimeException
{
    public function __construct(public readonly ?string $versionId, public readonly int $currentRevision)
    {
        parent::__construct("テーマの値は改訂 {$currentRevision} に進んでいます");
    }
}
