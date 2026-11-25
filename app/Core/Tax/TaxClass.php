<?php

declare(strict_types=1);

namespace App\Core\Tax;

/**
 * 消費税の区分（ADR 0063）。商品が区分を持ち、率（basis point。10% = 1000）は区分から核がここで決める。
 * 率を区分から引く表はここ 1 か所だけ（将来の率の変更はここを直す。直した時は、前の率で残った明細を読めるよう isReducedRate に前の率を足す）。
 *
 * - 送料・手数料など、飲食料品の譲渡でないものは標準（SHIPPING）
 * - 明細は注文の時の率（tax_rate_bp）を写して持つ。区分を後で変えても、作った注文の明細は変わらない
 */
enum TaxClass: string
{
    case Standard = 'standard'; // 標準税率 10%
    case Reduced = 'reduced';   // 軽減税率 8%（飲食料品〔酒類・外食を除く〕・定期購読の新聞）

    /** 送料は「配送」という役務の対価なので標準（商品と別に受け取る送料。ADR 0063） */
    public const self SHIPPING = self::Standard;

    public function rateBp(): int
    {
        return match ($this) {
            self::Standard => 1000,
            self::Reduced => 800,
        };
    }

    /**
     * 画面の言い方（「標準 10%」「軽減 8%（食品・飲み物）」）
     */
    public function label(): string
    {
        return match ($this) {
            self::Standard => '標準 10%',
            self::Reduced => '軽減 8%（食品・飲み物）',
        };
    }

    /**
     * 明細に写した率が軽減税率か（領収書の「※」・内訳の印）。
     * 軽減の率を変える時は、前の率で残った明細も軽減と読めるよう、前の率もここで真にする（作った注文の明細は注文の時の率のまま）
     */
    public static function isReducedRate(int $rateBp): bool
    {
        return $rateBp === self::Reduced->rateBp();
    }

    /**
     * 率の見せ方（800 → 「8%」、1000 → 「10%」）
     */
    public static function rateLabel(int $rateBp): string
    {
        return rtrim(rtrim(number_format($rateBp / 100, 2, '.', ''), '0'), '.').'%';
    }
}
