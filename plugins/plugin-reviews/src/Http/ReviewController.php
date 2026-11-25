<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews\Http;

use App\Core\Party\Models\CustomerAccount;
use App\Core\RateLimit\Throttle;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Access\ClientSource;
use App\Storefront\Members\Member;
use App\Storefront\View\StorefrontView;
use EcSugar\PluginReviews\Models\Review;
use EcSugar\PluginReviews\ReviewableOrders;
use EcSugar\PluginReviews\ReviewInput;
use EcSugar\PluginReviews\ReviewLinks;
use EcSugar\PluginReviews\ReviewRefused;
use EcSugar\PluginReviews\Reviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ショップのレビューの画面（買った人だけ）。会員はマイページの「レビュー」（/p/reviews/account）から自分の注文を、
 * ゲストはご注文の確認の画面の「レビューを書く」（署名と期限つきの道筋）からその注文を開く。
 * 見てよいのは、自分の注文（会員。店の人がまとめた記録の注文も）か、署名の合う道筋の注文だけ。どちらでもなければ、注文があるかどうかを教えずにご注文の確認へ。
 * 書くのは 1 つの注文の 1 つの商品に 1 つ、接続元ごとに 1 時間 WRITES_PER_HOUR 回まで
 */
final class ReviewController
{
    public const int WRITES_PER_HOUR = 10;

    private const string MESSAGE = 'reviews_message';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly Reviews $reviews,
    ) {}

    /**
     * マイページのレビュー（会員だけ）。書ける注文の商品と、書いたかどうか
     */
    public function account(Request $request): Response|RedirectResponse
    {
        $account = self::member($request);

        if ($account === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/reviews/account']);
        }

        return $this->view->renderApp('reviews::list', [
            'orders' => self::present(ReviewableOrders::ofMember($account)->all(), false),
            'member' => true,
            'message' => self::message($request),
        ]);
    }

    /**
     * 1 つの注文のレビュー（ご注文の確認から。会員は自分の注文なら署名なしでも）
     */
    public function order(Request $request, string $orderId): Response|RedirectResponse
    {
        [$order, $signed] = self::access($request, $orderId);

        if ($order === null) {
            return self::refuse($request);
        }

        return $this->view->renderApp('reviews::list', [
            'orders' => ReviewableOrders::writable($order) ? self::present([$order], $signed) : [],
            'member' => false,
            'message' => self::message($request),
        ]);
    }

    public function form(Request $request, string $orderId, string $listingId): Response|RedirectResponse
    {
        [$order, $signed] = self::access($request, $orderId);

        if ($order === null) {
            return self::refuse($request);
        }

        $listingId = strtolower($listingId);
        $title = ReviewableOrders::listings($order)[$listingId] ?? null;

        // 書けない（発送の前・注文の商品でない・もう書いた）ときは、注文のレビューの画面へ戻して理由を見せる
        if (! ReviewableOrders::writable($order) || $title === null) {
            return redirect(ReviewLinks::order($order->id, $signed))->with(self::MESSAGE, 'この商品には、まだレビューを書けません（発送後に書けます）。');
        }

        if (Review::query()->where('transaction_id', $order->id)->where('listing_id', $listingId)->exists()) {
            return redirect(ReviewLinks::order($order->id, $signed))->with(self::MESSAGE, 'この商品のレビューは、すでに投稿いただいています。');
        }

        return $this->view->renderApp('reviews::form', [
            'listingTitle' => $title,
            'action' => ReviewLinks::form($order->id, $listingId, $signed),
            'back' => ReviewLinks::order($order->id, $signed),
            'publishesAtOnce' => $this->reviews->publishesAtOnce(),
            'limits' => ['title' => ReviewInput::TITLE_MAX, 'body' => ReviewInput::BODY_MAX, 'name' => ReviewInput::NAME_MAX],
            'defaultName' => ReviewInput::DEFAULT_NAME,
        ]);
    }

    public function submit(Request $request, string $orderId, string $listingId): RedirectResponse
    {
        [$order, $signed] = self::access($request, $orderId);

        if ($order === null) {
            return self::refuse($request);
        }

        $listingId = strtolower($listingId);
        $back = ReviewLinks::order($order->id, $signed);
        // 書く回数の上限（接続元ごと。店ごとに数える）。断った回も数える
        $key = 'reviews-write|'.(string) tenant()?->getTenantKey().'|'.ClientSource::of($request->ip());

        if (! Throttle::allow($key, self::WRITES_PER_HOUR, 3600)) {
            return redirect($back)->with(self::MESSAGE, '短い間に何度も投稿されています。しばらく時間をおいてから、もう一度お試しください。');
        }

        $input = ReviewInput::from($request->input('rating'), $request->input('title'), $request->input('body'), $request->input('display_name'));

        if (is_array($input)) {
            // 欄に戻すのは文字の値だけ（配列などを送られても、欄を描き直す時に落ちない）
            $old = array_filter($request->only('rating', 'title', 'body', 'display_name'), is_string(...));

            return redirect(ReviewLinks::form($order->id, $listingId, $signed))->withErrors($input)->withInput($old);
        }

        try {
            $review = $this->reviews->write($order->id, $listingId, $input);
        } catch (ReviewRefused $e) {
            return redirect($back)->with(self::MESSAGE, $e->getMessage());
        }

        return redirect($back)->with(self::MESSAGE, $review->status === 'published'
            ? 'レビューを投稿いただき、ありがとうございます。商品ページに掲載しました。'
            : 'レビューを投稿いただき、ありがとうございます。ショップが内容を確認してから、商品ページに掲載されます。');
    }

    /**
     * 見てよい注文と、署名の道筋で来たか。会員の自分の注文か、署名の合う道筋の注文だけ（ほかは [null, false]）
     *
     * @return array{0: ?Transaction, 1: bool}
     */
    private static function access(Request $request, string $orderId): array
    {
        // 署名つきの道筋（期限つきの閲覧の資格）を参照元に残さないのは、経路の宣言（secret-link）で核が掛ける。断る・戻す応答も同じ

        $order = ReviewableOrders::find($orderId);

        if ($order === null) {
            return [null, false];
        }

        $account = self::member($request);

        if ($account !== null && ReviewableOrders::ownedBy($order, $account)) {
            return [$order, false];
        }

        return ReviewLinks::signed($request) ? [$order, true] : [null, false];
    }

    /**
     * 注文と商品ごとの、書いたかどうか（画面に渡す形。名前・メール・届け先は入れない）
     *
     * @param  list<Transaction>  $orders
     * @return list<array{number: string, placedAt: string, items: list<array{title: string, stateLabel: ?string, url: ?string}>}>
     */
    private static function present(array $orders, bool $signed): array
    {
        $ids = array_map(static fn (Transaction $t): string => $t->id, $orders);
        $written = $ids === [] ? collect() : Review::query()->whereIn('transaction_id', $ids)->get(['transaction_id', 'listing_id', 'status'])
            ->keyBy(static fn (Review $r): string => $r->transaction_id.'|'.$r->listing_id);
        $out = [];

        foreach ($orders as $order) {
            $items = [];

            foreach (ReviewableOrders::listings($order) as $listingId => $title) {
                $review = $written->get($order->id.'|'.$listingId);
                $items[] = [
                    'title' => $title,
                    // 書いたレビューを店が非公開にした・消したことは、書いた人に見せない（どれも「書きました」）
                    'stateLabel' => match ($review?->status) {
                        null => null,
                        'published' => '投稿済み（掲載中）',
                        'pending' => '投稿済み（ショップで確認中）',
                        default => '投稿済み',
                    },
                    'url' => $review === null ? ReviewLinks::form($order->id, $listingId, $signed) : null,
                ];
            }

            if ($items !== []) {
                $out[] = ['number' => strtoupper($order->id), 'placedAt' => $order->created_at?->timezone('Asia/Tokyo')->format('Y/m/d') ?? '', 'items' => $items];
            }
        }

        return $out;
    }

    private static function member(Request $request): ?CustomerAccount
    {
        $member = $request->user('customer');

        return $member instanceof Member && $member->belongsHere() ? $member->account : null;
    }

    /**
     * 見てよくない（無い・ほかの人の注文・署名が違う・期限切れ）。どれも同じ答えで、ご注文の確認へ
     */
    private static function refuse(Request $request): RedirectResponse
    {
        return redirect()->route('storefront.orders.lookup')->with('cart_error', $request->query('signature') !== null
            ? 'リンクの有効期限が切れました。ご注文の確認から、もう一度「レビューを書く」を開いてください。'
            : 'ご注文の確認から「レビューを書く」を開いてください。会員の方はマイページからも書けます。');
    }

    private static function message(Request $request): ?string
    {
        $message = $request->session()->get(self::MESSAGE);

        return is_string($message) ? $message : null;
    }
}
