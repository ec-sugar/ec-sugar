<?php

declare(strict_types=1);

namespace App\Core\Payment\Enums;

/**
 * 返品・一部返金の理由（ADR 0048）。自由な文にしない（記録とイベントに個人情報や長い文を入れない。ADR 0010）
 */
enum ReturnReason: string
{
    case Returned = 'returned';                // 返品（お客さんの都合）
    case Defective = 'defective';              // 不良・破損
    case WrongItem = 'wrong_item';             // 違う品を送った
    case Goodwill = 'goodwill';                // お詫び・お見舞い
    case PriceAdjustment = 'price_adjustment'; // 値段の訂正・あとからの値引き
    case External = 'external';                // 決済代行の画面でした返金を記録した（店の人は選ばない）
    case Other = 'other';
    case Resale = 'resale';                    // チケットのリセールで売れた分を、手数料を引いて元の人へ返す（App「デジタルチケット」が付ける。店の人は選ばない）

    public function label(): string
    {
        return match ($this) {
            self::Returned => '返品',
            self::Defective => '不良・破損',
            self::WrongItem => '誤った商品を送った',
            self::Goodwill => 'お詫び・お見舞い',
            self::PriceAdjustment => '値段の訂正・値引き',
            self::External => 'Stripe の画面で行った返金',
            self::Other => 'その他',
            self::Resale => 'チケットのリセール（手数料を引いた額）',
        };
    }

    /**
     * 店の人が返金の画面で選べる理由（決済代行の画面の返金の記録と、App が付けるリセールの返金は除く）
     *
     * @return list<self>
     */
    public static function choosable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $r): bool => $r !== self::External && $r !== self::Resale));
    }
}
