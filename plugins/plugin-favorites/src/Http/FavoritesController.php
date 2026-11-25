<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites\Http;

use App\Core\Listing\Models\Listing;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Plugin\Services\MarketingConsents;
use App\Storefront\Catalog\Catalog;
use App\Storefront\View\StorefrontView;
use Carbon\CarbonImmutable;
use EcSugar\PluginFavorites\AddOutcome;
use EcSugar\PluginFavorites\FavoriteButton;
use EcSugar\PluginFavorites\FavoriteNotices;
use EcSugar\PluginFavorites\Favorites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * ショップのお気に入り（App の画面。ADR 0071）。
 *
 * - ハート（POST /p/favorites/{商品}）: pressed=1 で足す・0 で外す（押した後の形を送るので、2 つの画面で押しても、同時に 2 回押しても同じ結果）。
 *   JavaScript の時（Accept: application/json）は {pressed, label, message} を返し、無い時は元の画面（back。自分のショップの中の道筋だけ）へ戻す
 * - 会員でない人: 足そうとした商品をセッションに覚えてログインへ（戻り先は /p/favorites/back/{商品}。核のログインが App の道筋だけへ戻す）。
 *   ログインから戻ると、覚えていた商品と同じときだけ足して商品の画面へ（足すのは CSRF の合い言葉つきの POST で頼まれた分だけ。URL を開いただけでは足さない）
 * - マイページの一覧（/p/favorites/list）と知らせの選択（POST /p/favorites/settings）は会員だけ
 * - 配信の停止（/p/favorites/unsubscribe/{合い言葉}）: 開いただけでは変えず、ボタンかメールのソフトのワンクリック（RFC 8058）で知らせを止める
 */
final class FavoritesController
{
    private const string PENDING = 'favorites_pending';

    private const string ULID = '/\A[0-9a-hjkmnp-tv-z]{26}\z/';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly Favorites $favorites,
        private readonly Catalog $catalog,
    ) {}

    public function toggle(Request $request, string $listingId): JsonResponse|RedirectResponse
    {
        $listingId = strtolower($listingId);
        $want = $request->input('pressed');
        $json = $request->expectsJson();
        $back = self::backPath($request->input('back'), $listingId);

        if (! in_array($want, ['0', '1'], true)) {
            return $json ? response()->json(['message' => 'もう一度押してください。'], 422) : redirect($back);
        }

        $member = FavoriteButton::member();

        if ($member === null) {
            // 足すときだけ覚える（外すのはログインの後に要らない）。覚えるのはショップに出ている商品の ID だけ
            if ($want === '1' && $this->catalog->isPublished($listingId)) {
                $request->session()->put(self::PENDING, $listingId);
            }

            $login = route('storefront.account.login', ['return' => '/p/favorites/back/'.$listingId], false);

            return $json ? response()->json(['login' => $login, 'message' => 'お気に入りは、ログインしてから使えます。'], 401) : redirect($login);
        }

        [$message, $error] = $want === '1'
            ? self::added($this->favorites->add($member, $listingId))
            : [$this->favorites->remove($member, $listingId) ? 'お気に入りから削除しました。' : null, null];
        $pressed = $this->favorites->has($member, $listingId);

        if ($json) {
            return response()->json([
                'pressed' => $pressed,
                'label' => $pressed ? FavoriteButton::REMOVE : FavoriteButton::ADD,
                'message' => $error ?? $message,
            ], $error === null ? 200 : 422);
        }

        return $error === null ? redirect($back)->with('cart_message', $message) : redirect($back)->with('cart_error', $error);
    }

    /**
     * ログインから戻った（GET）。足そうとしていた商品（セッション）と同じときだけ足して、商品の画面へ
     */
    public function back(Request $request, string $listingId): RedirectResponse
    {
        $listingId = strtolower($listingId);
        $member = FavoriteButton::member();

        if ($member === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/favorites/back/'.$listingId]);
        }

        $pending = $request->session()->pull(self::PENDING);

        if (! $this->catalog->isPublished($listingId)) {
            return redirect('/p/favorites/list');
        }

        $to = redirect()->route('storefront.product', ['listingId' => $listingId]);

        if ($pending !== $listingId) {
            return $to;
        }

        [$message, $error] = self::added($this->favorites->add($member, $listingId));

        return $error === null ? $to->with('cart_message', $message) : $to->with('cart_error', $error);
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $member = FavoriteButton::member();

        if ($member === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/favorites/list']);
        }

        $items = $this->favorites->list($member);
        $ids = array_column($items, 'listing_id');
        $cards = [];

        foreach ($this->catalog->picked($ids) as $card) {
            $cards[$card->id] = $card;
        }

        // 公開を終えた商品は名前だけ（外すボタンつき）
        $gone = array_values(array_diff($ids, array_keys($cards)));
        $titles = $gone === [] ? [] : Listing::query()->whereKey($gone)->pluck('title', 'id')->all();
        $account = CustomerAccount::query()->findOrFail($member);
        $consents = app(MarketingConsents::class);

        return $this->view->renderApp('favorites::list', [
            'entries' => array_map(static fn (array $item): array => [
                'id' => $item['listing_id'],
                'card' => $cards[$item['listing_id']] ?? null,
                'title' => (string) ($titles[$item['listing_id']] ?? ''),
            ], $items),
            'max' => Favorites::MAX,
            'settings' => $this->favorites->settings($member),
            // 知らせはお知らせのメールの受け取りを選んだ人だけに届く（ADR 0067 の核の口）。選んでいなければ案内を出す
            'consented' => $consents->consents($account->email),
            'consentAvailable' => $consents->available(),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $member = FavoriteButton::member();

        if ($member === null) {
            return redirect()->route('storefront.account.login', ['return' => '/p/favorites/list']);
        }

        $this->favorites->choose($member, $request->boolean('price_drop'), $request->boolean('restock'));

        return redirect('/p/favorites/list')->with('cart_message', 'お知らせの設定を保存しました。');
    }

    public function showUnsubscribe(Request $request, string $token): Response
    {
        $notice = self::notice($token);

        return match (true) {
            $notice === null => $this->page('このリンクは使えません。', null, 404),
            $notice->unsubscribed_at !== null => $this->page('お気に入りのお知らせは、すでに停止しています。今後は届きません。', null),
            default => $this->page('下のボタンを押すと、お気に入りの商品の値下げ・再入荷のお知らせを停止します（お気に入りはそのまま残ります）。', '/p/favorites/unsubscribe/'.$token),
        };
    }

    /**
     * 止める。画面のボタンと、メールのソフトのワンクリック（本文が List-Unsubscribe=One-Click）の両方。止める操作は断らない
     */
    public function unsubscribe(Request $request, string $token): Response
    {
        $notice = self::notice($token);

        if ($notice === null) {
            return $this->page('このリンクは使えません。', null, 404);
        }

        DB::transaction(function () use ($notice): void {
            $this->favorites->choose((string) $notice->customer_account_id, false, false);
            $now = CarbonImmutable::now();
            DB::table('favorites_notices')->where('id', $notice->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => $now, 'updated_at' => $now]);
        });

        return $this->page('お気に入りのお知らせを停止しました。今後は届きません（マイページの「お気に入り」から、また設定できます）。', null);
    }

    /**
     * @return array{0: ?string, 1: ?string} 知らせの文と、断った理由
     */
    private static function added(AddOutcome $outcome): array
    {
        return match ($outcome) {
            AddOutcome::Added, AddOutcome::Already => ['お気に入りに追加しました。', null],
            AddOutcome::Full => [null, 'お気に入りは '.Favorites::MAX.' 件までです。いらないものを削除してから追加してください。'],
            AddOutcome::Unavailable => [null, 'この商品はいまお気に入りに追加できません。'],
        };
    }

    /**
     * 戻る先（自分のショップの中の道筋だけ。外のホスト・// で始まる道筋・制御文字は断り、商品の画面へ）
     */
    private static function backPath(mixed $back, string $listingId): string
    {
        if (is_string($back) && strlen($back) <= 500 && preg_match('#\A/(?![/\\\\])[A-Za-z0-9/_\-.~%?=&+]*\z#', $back) === 1) {
            return $back;
        }

        return preg_match(self::ULID, $listingId) === 1 ? route('storefront.product', ['listingId' => $listingId], false) : '/';
    }

    private static function notice(string $token): ?object
    {
        return DB::table('favorites_notices')->where('unsubscribe_hash', FavoriteNotices::hash($token))->first();
    }

    private function page(string $message, ?string $action, int $status = 200): Response
    {
        // 参照元を渡さない・残さない・検索に載せないは、経路の宣言（secret-link）で核がまとめて掛ける
        return $this->view->renderApp('favorites::token', ['message' => $message, 'path' => $action])->setStatusCode($status);
    }
}
