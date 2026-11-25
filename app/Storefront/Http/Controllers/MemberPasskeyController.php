<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerPasskey;
use App\Core\Party\Services\CustomerException;
use App\Core\Party\Services\CustomerPasskeys;
use App\Core\Party\Services\CustomerSessions;
use App\Models\Tenant;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Members\MemberPage;
use App\Storefront\Members\MemberSignIn;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * 会員のパスキー（ADR 0044 の 3、ADR 0023）。任意で足せて、ログインと本人の再確認に使える。
 *
 * - 結びつく先はこのショップのホストとオリジン（要求から。信じる前段の代理は TRUSTED_PROXIES だけ）
 * - オプション（チャレンジ）はこのブラウザのセッションに 1 回分だけ置き、使ったら消す。ブラウザが答えを隠し欄に入れてフォームで送る
 * - 足す・消すは本人の再確認の後だけ。確かめられなかった理由は言わない
 */
final class MemberPasskeyController
{
    public const string ACTOR = 'storefront';

    public const string CREATE = 'member_passkey_create';

    public const string REQUEST = 'member_passkey_request';

    private const string FAILED = 'パスキーを確認できませんでした。もう一度お試しください。';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly CustomerPasskeys $passkeys,
        private readonly CustomerSessions $sessions,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $account = $this->account($request);

        if (! $this->sessions->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'passkeys']);
        }

        return MemberPage::private($this->view->renderCore('account-passkeys', [
            'passkeys' => $this->passkeys->list($account)->map(static fn (CustomerPasskey $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'created' => $p->created_at->timezone('Asia/Tokyo')->format('Y/m/d'),
                'used' => $p->last_used_at?->timezone('Asia/Tokyo')->format('Y/m/d'),
            ])->all(),
            'limit' => CustomerPasskeys::LIMIT,
        ]));
    }

    public function createOptions(Request $request): JsonResponse
    {
        $account = $this->account($request);

        if (! $this->sessions->confirmedRecently(MemberCookie::token($request))) {
            return response()->json(['message' => 'もう一度ご本人の確認をしてください。'], 403);
        }

        $options = $this->passkeys->registrationOptions($account, $request->getHost(), (string) Tenant::current()->name);
        $request->session()->put(self::CREATE, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->account($request);
        $options = $request->session()->pull(self::CREATE);
        $credential = $request->input('credential');
        $name = $request->input('name');

        if (! $this->sessions->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'passkeys']);
        }

        if (! is_string($options) || ! is_string($credential) || strlen($credential) > 20_000 || ! is_string($name)) {
            return $this->back(self::FAILED);
        }

        try {
            $this->passkeys->register($account, $name, $credential, $options, $request->getSchemeAndHttpHost(), $request->getHost(), self::ACTOR);
        } catch (CustomerException $e) {
            return $this->back($e->getMessage());
        }

        return redirect()->route('storefront.account.passkeys')->with('cart_message', 'パスキーを追加しました。次回からは、メールの確認コードの代わりにパスキーでログインできます。');
    }

    public function destroy(Request $request, string $passkeyId): RedirectResponse
    {
        $account = $this->account($request);

        if (! $this->sessions->confirmedRecently(MemberCookie::token($request))) {
            return redirect()->route('storefront.account.confirm', ['next' => 'passkeys']);
        }

        try {
            $this->passkeys->remove($account, $passkeyId, self::ACTOR);
        } catch (CustomerException $e) {
            return $this->back($e->getMessage());
        }

        return redirect()->route('storefront.account.passkeys')->with('cart_message', 'パスキーを削除しました。');
    }

    /**
     * ログインと本人の再確認のオプション（会員なら、その会員のパスキーだけを候補に）
     */
    public function requestOptions(Request $request): JsonResponse
    {
        $member = $request->user('customer');
        $account = $member instanceof Member && $member->belongsHere() ? $member->account : null;
        $options = $this->passkeys->requestOptions($account, $request->getHost());
        $request->session()->put(self::REQUEST, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /**
     * パスキーでログイン（メールを入れずに入れる）
     */
    public function login(Request $request): RedirectResponse
    {
        $account = $this->verified($request, null);

        if ($account === null) {
            return redirect()->route('storefront.account.login')->with('cart_error', self::FAILED);
        }

        return app(MemberSignIn::class)->start($request, $account, 'passkey');
    }

    /**
     * パスキーで本人の再確認（その会員のパスキーだけ）
     */
    public function confirm(Request $request): RedirectResponse
    {
        $account = $this->account($request);
        $next = MemberSecurityController::nextRoute($request->input('next'));

        if ($this->verified($request, $account) === null) {
            return redirect()->route('storefront.account.confirm', ['next' => $next[0]])->with('cart_error', self::FAILED);
        }

        $this->sessions->confirm(MemberCookie::token($request));
        $request->session()->regenerate(true);

        return MemberSecurityController::afterConfirm($request, $next[0]);
    }

    private function verified(Request $request, ?CustomerAccount $expected): ?CustomerAccount
    {
        $options = $request->session()->pull(self::REQUEST);
        $credential = $request->input('credential');

        if (! is_string($options) || ! is_string($credential) || strlen($credential) > 20_000) {
            return null;
        }

        return $this->passkeys->verify($credential, $options, $request->getSchemeAndHttpHost(), $request->getHost(), $expected);
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('storefront.account.passkeys')->with('cart_error', $message);
    }

    private function account(Request $request): CustomerAccount
    {
        $member = $request->user('customer');

        if (! $member instanceof Member || ! $member->belongsHere()) {
            abort(401);
        }

        return $member->account;
    }
}
