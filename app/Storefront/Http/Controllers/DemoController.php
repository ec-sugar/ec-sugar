<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Party\Services\CustomerException;
use App\Core\Party\Services\CustomerLogin;
use App\Core\RateLimit\Throttle;
use App\Core\Sample\Demo\DemoMails;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Sample\Demo\DemoVisitors;
use App\Core\Sample\Models\DemoMail;
use App\Storefront\Access\ClientSource;
use App\Storefront\Http\Middleware\DemoVisitor;
use App\Storefront\Members\MemberPage;
use App\Storefront\Members\MemberSignIn;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * デモの店だけの画面（ADR 0086）。デモの店でなければどれも「見つかりません」。
 *
 * - 送るはずだったメール: この来訪者の受け箱（セッションの合い言葉から作る）のメールだけを見せる。ほかの来訪者のメールは見えない
 * - デモの会員でログイン: この来訪者だけのアドレスの会員を作って（あれば同じ会員に）入る。ほかの来訪者と会員を分け合わない。
 *   接続元ごとに 1 時間 20 回まで（使い捨ての会員を作り続けさせない）
 */
final class DemoController
{
    public const int MEMBER_PER_HOUR = 20;

    public function __construct(private readonly StorefrontView $view) {}

    public function mails(Request $request, DemoMails $mails): Response
    {
        abort_unless(DemoShop::active(), 404);

        $mailbox = DemoVisitors::mailbox(DemoVisitor::token($request));

        return MemberPage::private($this->view->renderCore('demo-mails', [
            'mails' => $mails->inbox($mailbox)->map(static fn (DemoMail $mail): array => [
                'id' => $mail->id,
                'subject' => $mail->subject,
                'body' => $mail->body,
                'at' => $mail->created_at,
            ])->all(),
            'address' => DemoVisitors::address(DemoVisitor::token($request)),
        ]));
    }

    public function member(Request $request, CustomerLogin $login, MemberSignIn $signIn): RedirectResponse
    {
        abort_unless(DemoShop::active(), 404);

        // 接続元ごとに 1 時間 20 回まで（核の Throttle で先に数えてから比べる。断った回も数える。ADR 0081）
        if (! Throttle::allow(Throttle::shopKey('demo-member', ClientSource::of($request->ip())), self::MEMBER_PER_HOUR, 3600)) {
            return redirect()->route('storefront.account.login')->with('cart_error', 'デモの会員を作る回数が多すぎます。しばらくしてからお試しください。');
        }

        try {
            $account = $login->joinVerified(DemoVisitors::address(DemoVisitor::token($request)));
        } catch (CustomerException) {
            $account = null;
        }

        if ($account === null) {
            return redirect()->route('storefront.account.login')->with('cart_error', 'デモの会員でログインできませんでした。');
        }

        return $signIn->start($request, $account, 'demo');
    }
}
