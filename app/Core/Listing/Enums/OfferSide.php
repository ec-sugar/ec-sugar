<?php

declare(strict_types=1);

namespace App\Core\Listing\Enums;

/**
 * 出品物を「出す側」が売るのか買うのか。
 * お金の向きはここではなく Transaction 側で決まる（CONCEPT 5.3）。
 */
enum OfferSide: string
{
    case Sell = 'sell';  // 出品者が売る（物販、サービス）
    case Buy = 'buy';    // 出品者が買う（買取受付）、または買い手が募集する（仕事の募集）
}
