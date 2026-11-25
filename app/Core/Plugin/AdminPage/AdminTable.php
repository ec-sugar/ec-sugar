<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * 1 件の画面の表（回の履歴など。ADR 0051）。personal は個人情報（お客さんの書いた本文など）の印（AdminFact と同じ。2026-11-05）
 */
final readonly class AdminTable
{
    /**
     * @param  list<AdminColumn>  $columns
     * @param  list<AdminRow>  $rows
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
        public bool $personal = false,
    ) {}
}
