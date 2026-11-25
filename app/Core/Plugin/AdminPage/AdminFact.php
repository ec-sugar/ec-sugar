<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * 1 件の画面の事実（名前と値。ADR 0051）。copy は、値の横に「写す」ボタンを出す印（文の形だけ。商品フィードの URL のように、
 * 店の人がほかのサービスの画面に貼る値。後の App「商品フィード」で足した）。
 * personal は個人情報（名前・メールなど）の印（App「お問い合わせ」、2026-11-05）。核は運用の段階の人にだけ出し、見た事実を監査ログに残す。
 * prominent は、1 件の画面のいちばん上に大きく出す印（チケットの受付で、券面の名前を身分証と見比べる）。文の形だけで、1 件に 2 つまで
 */
final readonly class AdminFact
{
    public function __construct(
        public string $label,
        public CellType $type,
        public mixed $value,
        public bool $copy = false,
        public bool $personal = false,
        public bool $prominent = false,
    ) {}
}
