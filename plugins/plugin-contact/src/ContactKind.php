<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

/**
 * お問い合わせの種類（お客さんがフォームで選ぶ）
 */
enum ContactKind: string
{
    case Product = 'product';
    case Order = 'order';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Product => '商品について',
            self::Order => 'ご注文について',
            self::Other => 'そのほか',
        };
    }
}
