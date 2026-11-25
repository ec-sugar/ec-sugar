<?php

declare(strict_types=1);

namespace App\Core\Transaction\Enums;

/**
 * 取引の中での立場。お金の向き（payer / payee）はこれで表す（CONCEPT 5.3）。
 * 物販は customer → seller、買取は seller → customer。
 */
enum PartyRole: string
{
    case Customer = 'customer';
    case Seller = 'seller';
}
