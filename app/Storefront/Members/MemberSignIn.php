<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerSessions;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\ClientSource;
use App\Storefront\Http\Controllers\AccountController;
use App\Storefront\Http\Controllers\MemberPasskeyController;
use App\Storefront\Http\Controllers\MemberSecurityController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 会員としてログインさせる、ただ 1 つの終わり方（ADR 0044 の 3・ADR 0070）。メールのコード・パスキー・App（LINE でログインなど）が同じ所を通る。
 *
 * - このブラウザの前の会員の印（別の人として入っていた時も）を取り消す。ログインの後の戻り先を取り出してから、ログインと本人の再確認の途中の値
 *   （コードを送ったメール・再確認のコード・戻り先・パスキーのチャレンジ）と App の途中の値（LoginEntryProvider::sessionKeys。LINE の state など）を
 *   名指しで消し、セッションの番号を作り直して、新しい印を渡す。セッションごと消さないのは、注文の入力の途中の値を残すため
 * - ログインの記録は会員の印の行（customer_sessions。いつ・どの会員・入り方〔code・passkey・App の名前〕）。DB には合い言葉のハッシュだけ。
 *   新しい印は本人を確かめた時刻が空なので、どの入り方でも、入った直後の大事な操作は再確認を求める
 * - App から入るとき（forApp）は、核がもう一度確かめる: その App をこの店で使っている・呼んだのがその App のショップの経路
 *   （storefront.plugin.<名前>.*）・台帳が会員で、止めていない・まとめられていない。回数の上限も核が数える（接続元ごとに 1 分 10 回・
 *   会員ごとに 1 時間 20 回。外の認証が通っても、印を出し続けさせない）。App は台帳の ID を渡すだけで、会員の印を自分で作らない
 */
final class MemberSignIn
{
    public const int SOURCE_PER_MINUTE = 10;

    public const int ACCOUNT_PER_HOUR = 20;

    /** ログインと本人の再確認の途中で核がセッションに置く値（ログインした時に消す） */
    public const array CORE_KEYS = [
        AccountController::PENDING,
        MemberSecurityController::SENT,
        MemberSecurityController::NEW_EMAIL,
        MemberSecurityController::APP_RETURN,
        MemberPasskeyController::CREATE,
        MemberPasskeyController::REQUEST,
    ];

    public function __construct(
        private readonly CustomerSessions $sessions,
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
        private readonly LoginEntries $entries,
    ) {}

    /**
     * 確かめ終わった会員を入れる（核のコードとパスキーのログイン。台帳は確かめた側が渡す）
     *
     * @param  string  $via  入り方（code・passkey・App の名前）
     */
    public function start(Request $request, CustomerAccount $account, string $via): RedirectResponse
    {
        $this->sessions->end(MemberCookie::token($request));
        // 戻り先（App の画面から来た時）を先に取り出す
        $redirect = AccountController::afterLogin($request);
        $this->forgetPending($request);
        $request->session()->regenerate(true);

        return $redirect->withCookie(MemberCookie::make($request, $this->sessions->start($account, $via)));
    }

    /**
     * ログインと App の途中の値を消す（ログインした時）
     */
    public function forgetPending(Request $request): void
    {
        $request->session()->forget([...self::CORE_KEYS, ...$this->entries->sessionKeys()]);
    }

    /**
     * App のログインの途中の値だけを消す（核のログインの画面を開いた時。途中でやめた LINE の確かめ待ちなどを持ち越さない。
     * 核のコードを送ったメールの控えは残す: コードの画面へ戻れるように）
     */
    public function forgetAppPending(Request $request): void
    {
        $request->session()->forget($this->entries->sessionKeys());
    }

    /**
     * App が外の認証（LINE など）で確かめた会員を入れる。入れられなければ null（理由は言わない。App は「ログインできませんでした」とだけ出す）
     */
    public function forApp(Request $request, string $plugin, string $accountId): ?RedirectResponse
    {
        if (! $this->plugins->has($plugin) || $this->plugins->get($plugin)->loginEntry === null || ! $this->states->enabled($plugin)
            || ! $request->routeIs("storefront.plugin.{$plugin}.*")) {
            return null;
        }

        $shop = (string) tenant()?->getTenantKey();
        $keys = [
            ["member-signin|{$shop}|source|".ClientSource::of($request->ip()), self::SOURCE_PER_MINUTE, 60],
            ["member-signin|{$shop}|account|".hash('sha256', $accountId), self::ACCOUNT_PER_HOUR, 3600],
        ];

        // 先に数えてから比べる（狭い上限から。同時に来ても上限を越えない）
        if (Throttle::firstOver($keys) !== null) {
            return null;
        }

        // 台帳は核が引き直す（App の覚えた ID が、退会・止めた・まとめた後の台帳を指していても入れない）
        $account = preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $accountId) === 1
            ? CustomerAccount::query()->whereKey($accountId)->whereNotNull('member_since')->whereNull('disabled_at')->whereNull('merged_into_id')->first()
            : null;

        return $account === null ? null : $this->start($request, $account, $plugin);
    }
}
