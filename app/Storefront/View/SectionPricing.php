<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Core\Section\CoreSections;

/**
 * 核の区画「値段の表」（basic_pricing）の出すプランと、プランごとの箇条・札を決める（核の部品 basic_pricing.blade.php が使う）。
 * プランはブロック plan で、名前か値段のあるものだけを出す。値段は店の人が書いた文字のまま出す（注文・支払いとは結びつかない見せ方）。
 * おすすめの印は保存のときに 1 つまでに確かめている（CoreSections::blockRules）が、描くときも最初の 1 つだけを目立たせる
 */
final class SectionPricing
{
    /**
     * 出すプラン（名前か値段のあるブロックだけ。並びの順）
     *
     * @return list<SectionView>
     */
    public static function plans(SectionView $section): array
    {
        return array_values(array_filter($section->blocks,
            static fn (SectionView $plan): bool => $plan->key === 'plan' && ($plan['name'] !== null || $plan['price'] !== null)));
    }

    /**
     * 目立たせるプランの並びの中の番号（おすすめの印の最初の 1 つ。無ければ null）
     */
    public static function featured(SectionView $section): ?int
    {
        foreach (self::plans($section) as $i => $plan) {
            if ($plan['featured'] !== null) {
                return $i;
            }
        }

        return null;
    }

    /**
     * おすすめの札の文字（選択肢の名前。店の人が書く文字ではない）。印が無い・知らない値なら null
     */
    public static function badge(SectionView $plan): ?string
    {
        $value = $plan['featured'];
        $options = CoreSections::section(CoreSections::PRICING)?->blocks['plan']->fields['featured']->options ?? [];

        return is_string($value) ? ($options[$value] ?? null) : null;
    }

    /**
     * 含むものの箇条（空でない行。多くても決まった行の数まで）
     *
     * @return list<string>
     */
    public static function includes(SectionView $plan): array
    {
        $includes = $plan['includes'];

        return array_slice(CoreSections::lines(is_string($includes) ? $includes : null), 0, CoreSections::PRICING_LINES);
    }

    /**
     * 表のパターンで段を出すか（どれかのプランにその欄の中身があるとき）。欄は price・description・includes・button
     */
    public static function hasRow(SectionView $section, string $row): bool
    {
        foreach (self::plans($section) as $plan) {
            $filled = match ($row) {
                'price' => $plan['price'] !== null || $plan['price_note'] !== null,
                'includes' => self::includes($plan) !== [],
                'button' => $plan['button_label'] !== null && $plan['button_link'] !== null,
                default => $plan[$row] !== null,
            };

            if ($filled) {
                return true;
            }
        }

        return false;
    }
}
