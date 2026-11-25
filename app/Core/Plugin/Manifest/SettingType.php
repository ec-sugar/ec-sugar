<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

/**
 * App の設定の項目の種類（ADR 0034 の 3）。核が決めた種類だけで、App は足せない（テーマの区画の欄と同じ考え方。ADR 0028）
 */
enum SettingType: string
{
    case Text = 'text';     // 1 行の文（形の決まり〔正規表現〕と長さを必ず書く。ID など）
    case Toggle = 'toggle'; // 切り替え（真偽）
    case Choice = 'choice'; // 決めた選択肢から 1 つ
    case Media = 'media';   // ショップの写真から 1 枚（値は写真の ID。ロゴなど。App の設定だけ。2026-11-04「納品書・領収書」）

    /** 文の長さの核の上限 */
    public const int TEXT_CAP = 200;
}
