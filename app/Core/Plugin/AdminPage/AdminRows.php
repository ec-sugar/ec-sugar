<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * 一覧の 1 ページ分の行と、絞り込みに合う全部の件数（ADR 0051）
 */
final readonly class AdminRows
{
    /**
     * @param  list<AdminRow>  $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
    ) {}
}
