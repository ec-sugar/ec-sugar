<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\RateLimit\Throttle;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Models\Tenant;
use App\Storefront\Access\ClientSource;
use App\Storefront\Access\ShopPasswordCookie;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 準備中のショップの「準備中です」の画面とパスワード。合えば、その店・今の世代の Cookie を渡して元の画面へ戻す。
 * 回数の制限（先に数えてから判断する。同時に送っても上限を超えない）:
 *   - 接続元ごと（この店で 10 分に 5 回。IPv6 は /64 にまとめる）。超えたら比べずに断る。合えば数え直す
 *   - ショップごとは締め出さず、遅らせるだけ: 店全体で比べるのは 3 秒に 1 回まで。超えたら比べずに「少し待ってから」と返す
 *     （接続元を変えて総当たりしても速さはこれで頭打ち。正しいパスワードの関係者は少し待てば入れる）
 * 公開中のショップでは使わない（トップへ送る）
 */
final class PasswordController
{
    public const int PER_SOURCE = 5;

    public const int PER_SOURCE_SECONDS = 600;

    /** 店全体で、パスワードを比べる間隔（秒）。この間に比べるのは 1 回だけ */
    public const int SHOP_INTERVAL_SECONDS = 3;

    public function __construct(
        private readonly StorefrontAccessService $access,
        private readonly StorefrontView $view,
    ) {}

    public function show(): Response|RedirectResponse
    {
        if ($this->access->status() === ShopStatus::Open) {
            return redirect()->route('storefront.home');
        }

        return $this->view->renderPassword();
    }

    public function unlock(Request $request): RedirectResponse
    {
        if ($this->access->status() === ShopStatus::Open) {
            return redirect()->route('storefront.home');
        }

        $tenant = (string) Tenant::current()->getTenantKey();
        $sourceKey = 'shop-password|source|'.$tenant.'|'.ClientSource::of($request->ip());
        $shopKey = 'shop-password|shop|'.$tenant;
        $refuse = static fn (string $message): RedirectResponse => redirect()->route('storefront.password')->with('cart_error', $message);

        if (! Throttle::allow($sourceKey, self::PER_SOURCE, self::PER_SOURCE_SECONDS)) {
            return $refuse('パスワードを間違えた回数が多すぎます。しばらく時間をおいてから、もう一度お試しください。');
        }

        if (! Throttle::allow($shopKey, 1, self::SHOP_INTERVAL_SECONDS)) {
            // 比べていないので、接続元の回数には数えない
            Throttle::refund($sourceKey, self::PER_SOURCE_SECONDS);

            return $refuse('ただいま確認が混み合っています。少し待ってから、もう一度お試しください。');
        }

        $password = $request->input('password');
        $pass = is_string($password) && $password !== '' ? $this->access->verify($password) : null;

        if ($pass === null) {
            return $refuse('パスワードが違います。');
        }

        Throttle::clear($sourceKey);

        // 戻る先は関所が覚えた元の画面（このショップのホストの URL だけ。ほかは使わずトップへ）
        $intended = $request->session()->pull('url.intended');
        $root = $request->root();
        $to = is_string($intended) && ($intended === $root || str_starts_with($intended, $root.'/')) ? $intended : route('storefront.home');

        return redirect()->to($to)->withCookie(ShopPasswordCookie::make($request, $tenant, $pass['generation'], $pass['key']));
    }
}
