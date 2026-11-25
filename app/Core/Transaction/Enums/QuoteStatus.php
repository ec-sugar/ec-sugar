<?php

declare(strict_types=1);

namespace App\Core\Transaction\Enums;

enum QuoteStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';    // 相手が断った、または別の提示が採られた
    case Withdrawn = 'withdrawn';  // 出した側が取り下げた
}
