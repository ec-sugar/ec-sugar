<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks;

use App\Core\Plugin\Contracts\MemberBenefitsHandler;
use App\Core\Plugin\MemberDiscount;

/**
 * 核の会員の特典の口（ADR 0079）の、会員ランクの答え。ランクは毎日の決め直しで書いたもの（Ranks::current。会員でない台帳は 0）
 *
 * - 会員価格: ランクの割引 %（0 なら無し）。印は「rank{位置}-{%}」で、確かめた後にランクや率が変われば指紋が合わずに確定しない
 * - ポイントの上乗せ: ランクの万分率
 * - 見せない商品: ゲスト・会員でない人には会員だけ・ランクだけの商品の全部、会員にはランクの下限が今のランクより上の商品
 */
final class MemberRankBenefits implements MemberBenefitsHandler
{
    public function discountFor(string $customerAccountId): ?MemberDiscount
    {
        $rank = app(Ranks::class)->rankOf($customerAccountId);

        if ($rank === null || $rank->discount === 0) {
            return null;
        }

        return new MemberDiscount("rank{$rank->position}-{$rank->discount}", mb_substr($rank->name, 0, 20)."の会員価格（{$rank->discount}% 引き）", $rank->discount);
    }

    public function pointBonusBp(string $customerAccountId): int
    {
        return app(Ranks::class)->rankOf($customerAccountId)->bonusBp ?? 0;
    }

    public function hiddenListings(?string $customerAccountId, bool $enabled): array
    {
        $viewer = $enabled && $customerAccountId !== null ? app(Ranks::class)->viewerRank($customerAccountId) : -1;

        return app(RankListings::class)->hiddenFrom($viewer, $enabled);
    }

    public function badgeFor(string $customerAccountId): ?string
    {
        return app(Ranks::class)->rankOf($customerAccountId)?->name;
    }
}
