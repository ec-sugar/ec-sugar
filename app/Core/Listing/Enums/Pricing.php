<?php

declare(strict_types=1);

namespace App\Core\Listing\Enums;

/**
 * 価格の決まり方。
 */
enum Pricing: string
{
    case Fixed = 'fixed';          // 固定（unit_amount）
    case Quoted = 'quoted';        // 査定・見積・提案で決まる（Transaction の Quote フェーズ）
    case Recurring = 'recurring';  // 固定 × 周期（定期購入）
}
