<?php

declare(strict_types=1);

namespace App\Core\Fulfillment\Enums;

/**
 * お届けの時間帯（ADR 0059）。ヤマト運輸・佐川急便・日本郵便のどれでも選べる共通の区分だけ。
 * 店はこの中から受ける時間帯を選ぶ。運送会社ごとの区分の書き方（送り状の CSV の値）は、それを使う App（配送の CSV）が持つ
 */
enum DeliverySlot: string
{
    case Morning = 'am';
    case From14To16 = '14-16';
    case From16To18 = '16-18';
    case From18To20 = '18-20';
    case From19To21 = '19-21';

    /**
     * お客さんとお店の人に見せる言い方
     */
    public function label(): string
    {
        return match ($this) {
            self::Morning => '午前中',
            self::From14To16 => '14時〜16時',
            self::From16To18 => '16時〜18時',
            self::From18To20 => '18時〜20時',
            self::From19To21 => '19時〜21時',
        };
    }
}
