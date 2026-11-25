<?php

declare(strict_types=1);

namespace App\Core\Fulfillment\Enums;

/**
 * 受渡の方法（CONCEPT 5.7）。inbound だけ向きが逆（顧客 → 店。買取の送付・持込、返品）。
 */
enum FulfillmentMethod: string
{
    case Shipping = 'shipping';
    case Pickup = 'pickup';        // 店頭受取
    case Digital = 'digital';
    case InPerson = 'in_person';   // 役務
    case Inbound = 'inbound';      // 顧客からの送付・持込
    case None = 'none';

    public function isInbound(): bool
    {
        return $this === self::Inbound;
    }
}
