<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * App の画面の一覧の絞り込み（状態ごとのタブ。ADR 0051）。件数を添える
 */
final readonly class AdminFilter
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
    ) {}
}
