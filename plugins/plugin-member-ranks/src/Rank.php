<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks;

use App\Core\Money\Money;

/**
 * 店が決めたランク 1 つ（ADR 0079）。position は下からの順（1〜3）、threshold は直近 12 か月の購入額（円）の下限、
 * discount は会員価格（商品の代金の割引 %）、bonusBp はポイントの率の上乗せ（万分率。0.5% は 50）
 */
final readonly class Rank
{
    public function __construct(
        public int $position,
        public string $name,
        public int $threshold,
        public int $discount,
        public int $bonusBp,
    ) {}

    public function thresholdLabel(): string
    {
        return Money::format($this->threshold);
    }

    /**
     * ポイントの上乗せの見せ方（「+0.5%」。無ければ null）
     */
    public function bonusLabel(): ?string
    {
        return $this->bonusBp === 0 ? null : '+'.rtrim(rtrim(number_format($this->bonusBp / 100, 1, '.', ''), '0'), '.').'%';
    }

    /**
     * 特典の短い説明（「会員価格 5% 引き・ポイント +1%」。ポイントを使わない店は、ポイントの分を出さない）
     *
     * @return list<string>
     */
    public function benefits(bool $points): array
    {
        return array_values(array_filter([
            $this->discount > 0 ? "会員価格 {$this->discount}% 引き" : null,
            $points && $this->bonusBp > 0 ? 'ポイント '.$this->bonusLabel() : null,
        ]));
    }
}
