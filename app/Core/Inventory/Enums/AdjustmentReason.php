<?php

declare(strict_types=1);

namespace App\Core\Inventory\Enums;

/**
 * 在庫を直接調整する理由。自由記述にしない（イベントに個人情報や長文を入れない。ADR 0010）。
 */
enum AdjustmentReason: string
{
    case Stocktake = 'stocktake';    // 棚卸しの結果に合わせる
    case Damage = 'damage';          // 破損・不良
    case Loss = 'loss';              // 紛失・盗難
    case Correction = 'correction';  // 入庫の入力の誤りを直す
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Stocktake => '棚卸し',
            self::Damage => '破損・不良',
            self::Loss => '紛失・盗難',
            self::Correction => '入力の訂正',
            self::Other => 'その他',
        };
    }
}
