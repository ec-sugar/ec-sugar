<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * 表の 1 行（ADR 0051）。id は 1 件の画面へ進む記録の ULID（回の履歴のように進まない行は null）。
 * cells は列の名前 => 値（形は列の CellType）
 */
final readonly class AdminRow
{
    /**
     * @param  array<string, mixed>  $cells
     */
    public function __construct(
        public ?string $id,
        public array $cells,
    ) {}
}
