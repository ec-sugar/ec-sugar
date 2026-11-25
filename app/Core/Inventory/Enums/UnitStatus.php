<?php

declare(strict_types=1);

namespace App\Core\Inventory\Enums;

/**
 * 個体在庫の状態。買取 → 検品 → 出品 → 販売 の履歴がそのまま古物台帳の実体になる（CONCEPT 5.6）。
 */
enum UnitStatus: string
{
    case Received = 'received';      // 受け入れ（買取で届いた等）
    case Inspecting = 'inspecting';  // 検品・査定中
    case Available = 'available';    // 販売可能
    case Reserved = 'reserved';      // 取引で確保中
    case Sold = 'sold';
    case Returned = 'returned';      // 顧客へ返送（買取不成立など）
    case Disposed = 'disposed';
}
