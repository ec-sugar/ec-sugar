<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerException;
use App\Core\Party\Services\CustomerLogin;
use App\Core\Party\Services\CustomerMerges;
use App\Core\Party\Services\CustomerSessions;
use App\Core\Points\Models\PointEntry;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Members\AccountLinks;
use App\Storefront\Members\LoginEntries;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Members\MemberLogin;
use App\Storefront\Members\MemberPage;
use App\Storefront\Members\MemberSignIn;
use App\Storefront\Orders\OrderReader;
use App\Storefront\View\StorefrontView;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * 会員のログインとマイページ（ADR 0044 の 3）。
 *
 * - メールを入れる → いつも「送りました」でコードの欄へ（メールの有る無しで答えを変えない）。どのメールかはこのブラウザのセッションに置く
 * - コードが合えば会員の印（Cookie。DB にはハッシュだけ）を渡し、セッションを作り直す
 * - マイページは印のある会員だけ（auth:customer）。注文の履歴は中身だけ（届け先・電話・名前・追跡番号は出さない）
 */
final class AccountController
{
    public const string PENDING = 'member_login';

    private const string WRONG = 'コードが違うか、期限が切れました。もう一度お試しください。';

    private const int HISTORY = 50;

    public function __construct(
        private readonly StorefrontView $view,
        private readonly MemberLogin $login,
        private readonly CustomerSessions $sessions,
        private readonly OrderReader $orders,
    ) {}

    /** ログインの後に戻る先（App の画面だけ。外の URL や核の画面へは戻さない。ADR 0050） */
    public const string RETURN_TO = 'member_return_to';

    public function login(Request $request): Response|RedirectResponse
    {
        $return = $request->query('return');

        if (is_string($return) && self::returnable($return)) {
            $request->session()->put(self::RETURN_TO, $return);
        }

        $member = $request->user('customer');

        if ($member instanceof Member && $member->belongsHere()) {
            return self::afterLogin($request);
        }

        $old = $request->session()->getOldInput('email');
        // 途中でやめた App のログイン（LINE の確かめ待ちなど）は持ち越さない（ADR 0070）
        app(MemberSignIn::class)->forgetAppPending($request);

        return MemberPage::private($this->view->renderCore('account-login', [
            'email' => is_string($old) ? $old : '',
            // App のログインの入口（「LINE でログイン」など。ADR 0070）。使っていて設定のそろった App だけ
            'loginEntries' => app(LoginEntries::class)->all(),
        ]));
    }

    public function send(Request $request): RedirectResponse
    {
        $email = $request->input('email');
        $email = is_string($email) ? trim($email) : '';

        // 注文の入力と同じ決まり（email:rfc）。注文できたメールでログインできない、を作らない
        if ($email === '' || mb_strlen($email) > 254 || Validator::make(['email' => $email], ['email' => 'email:rfc'])->fails()) {
            return redirect()->route('storefront.account.login')->withInput(['email' => mb_substr($email, 0, 254)])
                ->with('cart_error', 'メールアドレスを確認してください。');
        }

        $this->login->send($email, $request->ip());
        $request->session()->put(self::PENDING, ['email' => $email, 'at' => CarbonImmutable::now()->getTimestamp()]);

        return redirect()->route('storefront.account.code');
    }

    public function code(Request $request): Response|RedirectResponse
    {
        $email = $this->pending($request);

        if ($email === null) {
            return redirect()->route('storefront.account.login');
        }

        return MemberPage::private($this->view->renderCore('account-code', ['email' => $email, 'minutes' => CustomerLogin::CODE_MINUTES]));
    }

    public function verify(Request $request): RedirectResponse
    {
        $email = $this->pending($request);

        if ($email === null) {
            return redirect()->route('storefront.account.login')->with('cart_error', 'もう一度メールアドレスを入力してください。');
        }

        // 全角の数字と間の空白は受ける（スマホの入力で混ざる）
        $code = $request->input('code');
        $code = is_string($code) && mb_strlen($code) <= 16 ? (string) preg_replace('/\s+/u', '', mb_convert_kana($code, 'ns')) : '';
        try {
            $account = $this->login->verify($email, $code);
        } catch (CustomerException $e) {
            // まとめられた記録（コードは使用済み）: 入れずに、メールを入れる画面へ戻す
            $request->session()->forget(self::PENDING);

            return redirect()->route('storefront.account.login')->with('cart_error', $e->getMessage());
        }

        if ($account === null) {
            return redirect()->route('storefront.account.code')->with('cart_error', self::WRONG);
        }

        // このブラウザの前の印（別の人として入っていた時も）は取り消し、セッションを作り直してから、新しい印を渡す（MemberSignIn）
        $request->session()->forget(self::PENDING);

        return app(MemberSignIn::class)->start($request, $account, 'code');
    }

    /**
     * ログインの後の行き先: App の画面から来たならそこへ（1 回だけ）、ほかはマイページ
     */
    public static function afterLogin(Request $request): RedirectResponse
    {
        $return = $request->session()->pull(self::RETURN_TO);

        return is_string($return) && self::returnable($return) ? redirect($return) : redirect()->route('storefront.account');
    }

    public static function returnable(string $path): bool
    {
        return preg_match('#\A/p/[a-z][a-z0-9-]{1,31}/[A-Za-z0-9/_-]{1,200}\z#', $path) === 1 && ! str_contains($path, '//');
    }

    public function show(Request $request): Response
    {
        $account = $this->account($request);
        // 店の人が同じお客さんとしてまとめた記録の注文もいっしょに（ADR 0044 の 2。中身だけを出すのは同じ）
        $transactions = Transaction::query()->whereIn('customer_account_id', CustomerMerges::family($account))->where('kind', 'sale')
            ->whereIn('id', CheckoutOrder::query()->select('transaction_id'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::HISTORY)->get();

        return MemberPage::private($this->view->renderCore('account', [
            'email' => $account->email,
            // App のリンク（領収書など）つき。ログインした会員の自分の注文だけなので付けてよい
            'orders' => $transactions->map(fn (Transaction $transaction) => $this->orders->readWithLinks($transaction))->all(),
            'appLinks' => app(AccountLinks::class)->all(),
            // つないでいる App のログインの手段（「LINE をつないでいます」。ADR 0070）。見出しに出し、知らないつなぎに気づけるように
            'linkedLogins' => app(LoginEntries::class)->linkedLabels($account->id),
            // ポイント（ADR 0068）: 残高・期限・付く予定・履歴（店が使っている時か、残高・履歴がある時だけ出す）
            'points' => $this->points($account),
        ]));
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->sessions->end(MemberCookie::token($request));
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('storefront.home')->withCookie(MemberCookie::forget($request));
    }

    /**
     * コードを送ったメール（このブラウザのセッション）。コードの期限を過ぎたら無いことにする
     */
    private function pending(Request $request): ?string
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || ! is_string($pending['email'] ?? null) || ! is_int($pending['at'] ?? null)
            || CarbonImmutable::now()->getTimestamp() - $pending['at'] > CustomerLogin::CODE_MINUTES * 60) {
            return null;
        }

        return $pending['email'];
    }

    /**
     * マイページのポイント（ADR 0068）。使わない店で、残高も履歴も無ければ null（何も出さない）
     *
     * @return array{balance: int, expiresAt: string|null, pending: list<array{number: string, points: int, placedAt: string}>, history: list<array{at: string, label: string, points: string, number: string|null}>}|null
     */
    private function points(CustomerAccount $account): ?array
    {
        $service = app(PointService::class);
        $history = $service->history($account->id, 30);

        if ($history->isEmpty() && ! app(PointSettings::class)->enabled()) {
            return null;
        }

        $labels = ['earn' => 'ご注文で付与', 'spend' => 'ご注文で利用', 'release' => 'ご注文の取り消しで返却', 'revoke' => 'ご返金で取り消し', 'expire' => '有効期限切れで失効', 'adjust' => 'ショップによる調整'];

        return [
            'balance' => $service->balance($account->id),
            'expiresAt' => $service->expiresAt($account->id)?->timezone('Asia/Tokyo')->format('Y/m/d'),
            // 来店で受け渡す注文（予約の品）は「ご来店の後に付きます」
            'pending' => array_map(static fn (array $o): array => ['number' => strtoupper($o['transactionId']), 'points' => $o['points'], 'placedAt' => $o['placedAt']->timezone('Asia/Tokyo')->format('Y/m/d'),
                'inPerson' => Fulfillment::query()->where('transaction_id', $o['transactionId'])->where('method', FulfillmentMethod::InPerson)->exists()], $service->pendingOrders($account->id)),
            // 店の人の調整の理由（自由な文）は出さない（店の中のメモ）。付ける前の返金の印（0 の取り消し）は出さない
            'history' => $history->reject(static fn (PointEntry $e): bool => $e->points === 0)->map(static fn (PointEntry $e): array => [
                'at' => $e->occurred_at->timezone('Asia/Tokyo')->format('Y/m/d'),
                'label' => match (true) {
                    $e->kind === 'expire' && $e->cause === 'left' => '退会で失効',
                    // App「友だち紹介」が付けた分（ADR 0075）
                    $e->kind === 'adjust' && $e->cause === 'referral' => '友だち紹介で付与',
                    default => $labels[$e->kind],
                },
                'points' => ($e->points > 0 ? '+' : '').number_format($e->points),
                'number' => $e->transaction_id === null ? null : strtoupper($e->transaction_id),
            ])->values()->all(),
        ];
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
