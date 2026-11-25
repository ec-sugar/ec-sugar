<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * 管理画面のホームの「やること」に App が出す 1 行（ADR 0051。定期便の「止まった定期便」など）。
 * 押すと App の画面の一覧の、その絞り込みへ進む。件数が 0 なら出さない。tone は CellType::TONES のどれか
 */
final readonly class AdminAttention
{
    public function __construct(
        public string $filter,
        public string $label,
        public string $description,
        public int $count,
        public string $tone = 'warn',
    ) {}
}
