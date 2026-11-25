<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks\Http;

use App\Core\Money\Money;
use App\Core\Points\Services\PointSettings;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberPage;
use App\Storefront\View\StorefrontView;
use Carbon\CarbonImmutable;
use EcSugar\PluginMemberRanks\PurchaseAmounts;
use EcSugar\PluginMemberRanks\Rank;
use EcSugar\PluginMemberRanks\Ranks;
use EcSugar\PluginMemberRanks\RankSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * マイページの「会員ランク」（ADR 0079）。会員だけ（ほかはログインの画面へ）。今のランク・直近 12 か月の購入額・次のランクまでの額・特典・ランクの一覧。
 * 額はこの会員の分だけを台帳から数える（ほかの人の額は出さない）。ページはどこにも残さない（MemberPage::private）
 */
final class RankController
{
    public function __construct(
        private readonly StorefrontView $view,
        private readonly RankSettings $settings,
        private readonly Ranks $ranks,
        private readonly PurchaseAmounts $amounts,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $member = $request->user('customer');

        if (! $member instanceof Member || ! $member->belongsHere()) {
            return redirect()->route('storefront.account.login', ['return' => '/p/member-ranks/account']);
        }

        $now = CarbonImmutable::now();
        $ranks = $this->settings->ranks();
        $position = $this->ranks->current($member->account->id);
        $amount = $this->amounts->forAccount($member->account->id, $now);
        $next = $ranks[$position + 1] ?? null;
        $points = app(PointSettings::class)->enabled();

        return MemberPage::private($this->view->renderApp('member-ranks::account', [
            'rank' => $ranks[$position] ?? null,
            'amount' => Money::format($amount),
            'since' => PurchaseAmounts::since($now)->format('Y年n月j日'),
            'next' => $next,
            // 次のランクまでの額（もう届いていれば 0。次の決め直しで上がる）
            'needed' => $next === null ? null : Money::format(max(0, $next->threshold - $amount)),
            'reached' => $next !== null && $amount >= $next->threshold,
            'ranks' => array_values(array_map(static fn (Rank $r): array => ['name' => $r->name, 'threshold' => $r->thresholdLabel(), 'benefits' => $r->benefits($points), 'current' => $r->position === $position], $ranks)),
            'benefits' => isset($ranks[$position]) ? $ranks[$position]->benefits($points) : [],
        ]));
    }
}
