<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Party\Models\CustomerAddress;
use App\Storefront\Checkout\CheckoutInput;

/**
 * 会員の届け先を画面に出す形（ADR 0044 の 3）。出すのは会員自身が入れた届け先だけ
 */
final class AddressView
{
    /**
     * 届け先の入力欄（核の部品 address-fields が並べる）: 項目 => [見出し, 種類, autocomplete, 最大の長さ, 必須か]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int, 4: bool}>
     */
    public const array FIELDS = [
        'name' => ['お名前', 'text', 'name', 60, true],
        'phone' => ['電話番号', 'tel', 'tel', 13, true],
        'postal' => ['郵便番号', 'text', 'postal-code', 8, true],
        'prefecture' => ['都道府県', 'select', 'address-level1', 0, true],
        'city' => ['市区町村', 'text', 'address-level2', 50, true],
        'line1' => ['番地', 'text', 'address-line1', 100, true],
        'line2' => ['建物名・部屋番号（任意）', 'text', 'address-line2', 100, false],
    ];

    /**
     * @return array{id: string, default: bool, name: string, phone: string, postal: string, prefecture: string, city: string, line1: string, line2: string|null, summary: string}
     */
    public static function of(CustomerAddress $address): array
    {
        $a = $address->address;

        return [
            'id' => $address->id,
            'default' => $address->is_default,
            ...$a,
            'summary' => '〒'.CheckoutInput::postalLabel($a['postal']).' '.$a['prefecture'].$a['city'].$a['line1'].($a['line2'] === null ? '' : ' '.$a['line2']),
        ];
    }

    /**
     * 住所の項目だけ（同じ届け先かを比べるため）
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    public static function fields(array $address): array
    {
        return array_intersect_key($address, array_flip(CheckoutInput::ADDRESS_FIELDS));
    }
}
