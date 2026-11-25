<?php

declare(strict_types=1);

namespace App\Core\Inventory\Enums;

enum LocationKind: string
{
    case Warehouse = 'warehouse';
    case Store = 'store';      // 店頭受取・店舗在庫
    case Virtual = 'virtual';  // デジタル商品・役務など実体を持たないもの
}
