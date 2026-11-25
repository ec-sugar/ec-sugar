<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder\Http;

use App\Core\Party\Models\Party;
use App\Core\Plugin\Services\MarketingConsents;
use App\Core\RateLimit\Throttle;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Access\ClientSource;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\View\StorefrontView;
use Carbon\CarbonImmutable;
use EcSugar\PluginCartReminder\CartReminders;
use EcSugar\PluginCartReminder\CartRestore;
use EcSugar\PluginCartReminder\Models\CartReminder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * ショップのカゴ落ちのお知らせのリンクの画面（ADR 0067。JavaScript を使わない）。
 *
 * - 「カートに戻す」（/p/cart-reminder/{合い言葉}）: 開くとカートを作り直してカートの画面へ（メールの約束どおり 1 回で戻る。カートは取引の前の下書きで、
 *   入るのは自分が受け取ったメールの注文の商品だけ）。使えない・期限切れのリンクは一言の画面
 * - 配信の停止（/p/cart-reminder/unsubscribe/{合い言葉}）: 画面は開いただけでは変えず、ボタン（POST）で止める。
 *   メールのソフトのワンクリック（RFC 8058。CSRF の合い言葉を持たない）も同じ POST で受ける。止めるのはお知らせのメールの受け取りの同意そのもの
 *   （同意を持つ App に核の口で頼む。メルマガも止まる）
 * - 回数の上限: 「カートに戻す」は接続元ごとに 1 時間 30 回（合い言葉の総当たりと、カートを作り続けることを止める）。停止は数えない（止める操作を断らない）
 * - URL に合い言葉があるので、次の画面へ URL を渡さず、検索に載せず、残さない
 */
final class CartReminderController
{
    public const int OPENS_PER_HOUR = 30;

    public function __construct(
        private readonly StorefrontView $view,
        private readonly CartService $carts,
        private readonly CartRestore $restore,
        private readonly MarketingConsents $consents,
    ) {}

    public function open(Request $request, string $token): Response|RedirectResponse
    {
        abort_unless(Throttle::allow(Throttle::shopKey('cart-reminder-open', ClientSource::of($request->ip())), self::OPENS_PER_HOUR, 3600), 429);

        $result = $this->restore->restore($token, $this->carts->find(CartCookie::token($request)));

        if ($result['state'] === 'invalid' || $result['state'] === 'expired') {
            return $this->page('カートに戻す', $result['state'] === 'expired'
                ? 'このリンクの有効期限（'.CartReminders::LINK_DAYS.' 日）が切れています。お手数ですが、ショップで商品をお選びください。'
                : 'このリンクは使えません。お手数ですが、ショップで商品をお選びください。', null, 404);
        }

        $notes = [];

        if ($result['removed'] !== []) {
            $names = array_map(static fn (string $t): string => mb_strimwidth($t, 0, 40, '…'), array_slice($result['removed'], 0, 3));
            $notes[] = '売り切れ・販売を終えた商品（'.implode('・', $names).(count($result['removed']) > 3 ? ' ほか' : '').'）は、カートに戻せなかったため外しました。';
        }

        if ($result['addons']) {
            $notes[] = '名入れなどのオプションは、商品の画面でもう一度お選びください。';
        }

        $response = $result['state'] === 'restored'
            ? redirect()->route('storefront.cart')->with('cart_message', trim('前回の商品をカートに戻しました。価格と在庫は現在のものです。'.implode('', $notes)))
            : redirect()->route('storefront.cart')->with('cart_error', '前回の商品は、売り切れか販売終了のため、カートに戻せませんでした。');

        return $result['token'] === null ? $response : $response->withCookie(CartCookie::make($request, $result['token']));
    }

    public function showUnsubscribe(Request $request, string $token): Response
    {
        $row = $this->byUnsubscribe($token);

        return match (true) {
            $row === null => $this->page('お知らせのメールの配信停止', 'このリンクは使えません。', null, 404),
            $row->unsubscribed_at !== null => $this->page('お知らせのメールの配信停止', 'お知らせのメールの配信は、すでに停止しています。今後は届きません。', null),
            default => $this->page('お知らせのメールの配信停止', '下のボタンを押すと、このショップからのお知らせのメール（メールマガジンを含みます）の配信を停止します。', '/p/cart-reminder/unsubscribe/'.$token),
        };
    }

    /**
     * 止める。画面のボタンと、メールのソフトのワンクリック（本文が List-Unsubscribe=One-Click）の両方
     */
    public function unsubscribe(Request $request, string $token): Response
    {
        $via = $request->input('List-Unsubscribe') === 'One-Click' ? 'one_click' : 'link';
        $row = $this->byUnsubscribe($token);

        if ($row === null) {
            return $this->page('お知らせのメールの配信停止', 'このリンクは使えません。', null, 404);
        }

        $order = Transaction::query()->find($row->transaction_id);
        $email = $order?->customer_id === null ? null : Party::query()->find($order->customer_id)?->email;

        if (is_string($email) && $email !== '') {
            $this->consents->withdraw($email, $via);
        }

        $now = CarbonImmutable::now();
        DB::table('cart_reminders')->where('id', $row->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => $now, 'updated_at' => $now]);

        return $this->page('お知らせのメールの配信停止', 'お知らせのメールの配信を停止しました。今後は届きません。', null);
    }

    private function byUnsubscribe(string $token): ?CartReminder
    {
        return CartReminder::query()->where('unsubscribe_hash', CartReminders::hash($token))->first();
    }

    private function page(string $title, string $message, ?string $action, int $status = 200): Response
    {
        // 参照元を渡さない・残さない・検索に載せないは、経路の宣言（secret-link）で核がまとめて掛ける
        return $this->view->renderApp('cart-reminder::token', ['title' => $title, 'message' => $message, 'path' => $action])->setStatusCode($status);
    }
}
