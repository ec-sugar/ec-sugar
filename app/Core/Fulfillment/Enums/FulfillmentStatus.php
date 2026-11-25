<?php

declare(strict_types=1);

namespace App\Core\Fulfillment\Enums;

enum FulfillmentStatus: string
{
    case Pending = 'pending';
    case Shipped = 'shipped';      // 発送済み（送付・持込では「送った」と申告された）
    case Completed = 'completed';  // 相手に届いた・渡した
    case Cancelled = 'cancelled';
}
