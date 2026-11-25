<?php

declare(strict_types=1);

namespace App\Core\Transaction\Enums;

enum LineType: string
{
    case Item = 'item';              // 出品物・個体・役務
    case Adjustment = 'adjustment';  // 値引き・送料・手数料など（金額は負になりうる）
}
