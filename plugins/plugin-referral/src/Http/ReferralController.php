<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral\Http;

use App\Core\Party\Models\CustomerAccount;
use App\Models\Tenant;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberPage;
use App\Storefront\View\StorefrontUrls;
use App\Storefront\View\StorefrontView;
use Carbon\CarbonImmutable;
use EcSugar\PluginReferral\ReferralCodes;
use EcSugar\PluginReferral\ReferralCookie;
use EcSugar\PluginReferral\Referrals;
use EcSugar\PluginReferral\ReferralSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ショップの友だち紹介（ADR 0075 の 1・2）。
 *
 * - 紹介のリンク（/p/referral/r/{印}）: 開いた人のブラウザに 30 日の印（Cookie）を置いて、ショップのトップへ。印が無い・紹介を受け付けていない時も、同じくトップへ（印が有るかを答えで見せない）
 * - マイページの「友だちを紹介する」（会員だけ）: 自分のリンク・写す・LINE で送る・作り直す。付くポイントと決まりの説明
 */
final class ReferralController
{
    private const string LINE_SHARE = 'https://line.me/R/share?text=';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly ReferralCodes $codes,
        private readonly ReferralSettings $settings,
        private readonly Referrals $referrals,
    ) {}

    public function open(Request $request, string $code): RedirectResponse
    {
        $home = redirect('/');
        $member = $request->user('customer');

        // 会員はもう紹介されない（自分のリンクを開いた時も）。受け付けていない店・無い印も、答えは同じ
        if (($member instanceof Member && $member->belongsHere()) || ! $this->settings->active() || $this->codes->owner($code) === null) {
            return $home;
        }

        return $home->withCookie(ReferralCookie::make($request, $code, CarbonImmutable::now()));
    }

    public function invite(Request $request): Response|RedirectResponse
    {
        $account = $this->member($request);

        if ($account === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/referral/invite']);
        }

        if (! $this->settings->active()) {
            return MemberPage::private($this->view->renderApp('referral::unavailable'));
        }

        $code = $this->codes->forMember($account, 'storefront');
        abort_if($code === null, 404);
        $url = (StorefrontUrls::of(Tenant::current(), '/p/referral/r/'.$code) ?? '/p/referral/r/'.$code);
        $shop = (string) Tenant::current()->name;

        return MemberPage::private($this->view->renderApp('referral::invite', [
            'url' => $url,
            'lineUrl' => self::LINE_SHARE.rawurlencode(($shop === '' ? 'このお店' : '「'.$shop.'」').'を紹介します。このリンクから会員登録すると、最初のご注文が発送された時にポイントがもらえます。'."\n".$url),
            'referrerPoints' => $this->settings->referrerPoints(),
            'friendPoints' => $this->settings->friendPoints(),
            'monthlyLimit' => $this->settings->monthlyLimit(),
            'rewardedThisMonth' => $this->referrals->rewardedThisMonth($account->id),
            'message' => session('referral_message'),
        ]));
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $account = $this->member($request);

        if ($account === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/referral/invite']);
        }

        if ($this->settings->active()) {
            $this->codes->regenerate($account, 'storefront');
        }

        return redirect('/p/referral/invite')->with('referral_message', 'リンクを作り直しました。前のリンクは使えなくなりました。');
    }

    private function member(Request $request): ?CustomerAccount
    {
        $member = $request->user('customer');

        return $member instanceof Member && $member->belongsHere() ? $member->account : null;
    }
}
