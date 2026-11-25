<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * App の画面の 1 件（ADR 0051）。名前・メール・住所・電話は入れない（お客さんはリンクで核の画面へ）。
 * 例外は、お客さんが店に宛てて書いたもの（お問い合わせの名前・メール・本文）を個人情報の印（AdminFact・AdminTable の personal）を付けて渡すときだけ
 * （核が運用の段階の人にだけ出し、見たことを監査ログに残す。2026-11-05）
 */
final readonly class AdminRecord
{
    /**
     * @param  list<AdminFact>  $facts
     * @param  list<AdminTable>  $tables
     * @param  list<AdminAction>  $actions
     */
    public function __construct(
        public string $title,
        public array $facts,
        public array $tables = [],
        public array $actions = [],
    ) {}
}
