<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * App の画面の表の列（ADR 0051）。名前は英小文字・数字・_
 */
final readonly class AdminColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public CellType $type,
    ) {}
}
