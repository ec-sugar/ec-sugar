<?php

declare(strict_types=1);

namespace App\Core\Party\Enums;

/**
 * 当事者の種類。
 * Customer は取引相手（買い手にも、買取では売り手にもなる）。Staff は運営側の人。
 * 出品者（Seller）は別のモデルで、Customer に紐づくこともある（マーケットモード）。
 */
enum PartyKind: string
{
    case Customer = 'customer';
    case Staff = 'staff';
}
