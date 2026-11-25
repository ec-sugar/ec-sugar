<?php

declare(strict_types=1);

namespace EcSugar\PluginGift\Http;

use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Listing\Models\ListingImage;
use App\Core\Order\Services\SaleOrderService;
use App\Core\RateLimit\Throttle;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\TransactionLine;
use App\Storefront\Access\ClientSource;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Catalog\ListingPhoto;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\View\StorefrontView;
use EcSugar\PluginGift\GiftOrders;
use EcSugar\PluginGift\GiftRefused;
use EcSugar\PluginGift\GiftStatus;
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

/**
 * 受け取る人の画面（ADR 0055 の 4）。リンクの token で開く。ショップの見た目で、差出人の表示名・メッセージ・商品の名前と写真と数だけを出す
 * （値段・買う人のメールと住所は出さない）。お届け先を入れて確かめ → 確定で受渡のお届け先になる。確定の後は「受け取り済み・発送済み」だけ。
 *
 * - 検索に載せない・URL を外へ渡さない（Referrer-Policy: no-referrer）・どこにも残さない
 * - 回数の上限: 接続元ごと（見る・送る）と token ごと（送る）。token は sha256 で引く（比べる時間から token は分からない）
 * - 入れたお届け先は確かめの間だけセッション（サーバーに暗号化して置く）に置き、確定で受渡の暗号化した列へ
 */
final class GiftReceiveController
{
    private const string SESSION = 'gift_receive';

    /** 接続元ごとの、見る回数（10 分あたり）と送る回数（1 時間あたり）、token ごとの送る回数（1 時間あたり） */
    private const int VIEWS_PER_10_MINUTES = 60;

    private const int POSTS_PER_HOUR = 30;

    private const int POSTS_PER_TOKEN_PER_HOUR = 10;

    public function __construct(
        private readonly StorefrontView $view,
        private readonly GiftOrders $gifts,
        private readonly Catalog $catalog,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $this->limit("gift-view|{$this->shopKey()}|".ClientSource::of($request->ip()), self::VIEWS_PER_10_MINUTES, 600);
        $gift = $this->gifts->findByToken($token);

        if ($gift === null) {
            return $this->page(['state' => 'invalid'], 404);
        }

        $state = $this->state($gift);
        $errors = $request->session()->get('errors');

        return $this->page([
            ...$this->facts($gift),
            'state' => $state,
            'token' => $token,
            'form' => [
                'values' => (array) ($request->session()->getOldInput('address') ?? $this->entered($request, $gift) ?? []),
                'errors' => $errors instanceof ViewErrorBag ? collect($errors->getMessages())->mapWithKeys(static fn (array $m, string $k): array => [str_replace('address.', '', $k) => $m[0] ?? ''])->all() : [],
            ],
            'error' => session('gift_error'),
        ]);
    }

    /**
     * 入れたお届け先を確かめ、確かめの画面へ（まだ確定しない）
     */
    public function submit(Request $request, string $token): RedirectResponse
    {
        $gift = $this->guardPost($request, $token);

        if ($gift === null || $this->state($gift) !== 'form') {
            return redirect('/p/gift/'.$token);
        }

        try {
            $address = CheckoutInput::address($request->only('address'));
        } catch (ValidationException $e) {
            return redirect('/p/gift/'.$token)->withErrors($e->errors())->withInput($request->only('address'));
        }

        $request->session()->put(self::SESSION.'.'.$gift->id, $address);

        return redirect('/p/gift/'.$token.'/confirm');
    }

    public function confirm(Request $request, string $token): Response|RedirectResponse
    {
        $this->limit("gift-view|{$this->shopKey()}|".ClientSource::of($request->ip()), self::VIEWS_PER_10_MINUTES, 600);
        $gift = $this->gifts->findByToken($token);
        $address = $gift === null ? null : $this->entered($request, $gift);

        if ($gift === null || $address === null || $this->state($gift) !== 'form') {
            return redirect('/p/gift/'.$token);
        }

        return $this->page([...$this->facts($gift), 'state' => 'confirm', 'token' => $token, 'address' => [
            'name' => $address['name'], 'phone' => $address['phone'], 'line' => CheckoutSummary::addressLine($address),
        ], 'error' => null, 'form' => ['values' => [], 'errors' => []]]);
    }

    /**
     * 確定: 受渡のお届け先にする（1 回だけ）
     */
    public function claim(Request $request, string $token): RedirectResponse
    {
        $gift = $this->guardPost($request, $token);
        $address = $gift === null ? null : $this->entered($request, $gift);

        if ($gift === null || $address === null) {
            return redirect('/p/gift/'.$token);
        }

        try {
            $this->gifts->claim($token, $address);
        } catch (GiftRefused $e) {
            return redirect('/p/gift/'.$token)->with('gift_error', $e->getMessage());
        }

        $request->session()->forget(self::SESSION.'.'.$gift->id);

        return redirect('/p/gift/'.$token);
    }

    /**
     * 画面の状態: form（入れる）・claimed（受け取り済み）・shipped（発送済み）・expired（期限切れ）・cancelled（取り消し）
     */
    private function state(GiftOrder $gift): string
    {
        $status = GiftStatus::of($gift);

        return match (true) {
            $status === 'waiting' && $gift->expires_at !== null && $gift->expires_at->isFuture() => 'form',
            $status === 'waiting', $status === 'expired' => 'expired',
            $status === 'claimed' => 'claimed',
            $status === 'shipped' => 'shipped',
            default => 'cancelled',
        };
    }

    /**
     * 受け取る人に見せる事実だけ（差出人・メッセージ・商品の名前と写真と数・発送）。値段と買う人の名前と連絡先は入れない
     *
     * @return array<string, mixed>
     */
    private function facts(GiftOrder $gift): array
    {
        $items = [];

        foreach (TransactionLine::query()->where('transaction_id', $gift->transaction_id)->where('line_type', LineType::Item)->orderBy('position')->get() as $line) {
            $key = $line->listing_id.'|'.$line->description;
            $items[$key] ??= ['title' => (string) $line->description, 'quantity' => 0, 'photo' => $this->photo($line->listing_id)];
            $items[$key]['quantity'] += (int) $line->quantity;
        }

        $shipments = Fulfillment::query()->where('transaction_id', $gift->transaction_id)->whereIn('status', [FulfillmentStatus::Shipped, FulfillmentStatus::Completed])
            ->orderBy('shipped_at')->get()->map(static fn (Fulfillment $f): array => [
                'carrier' => $f->carrier === null ? null : (SaleOrderService::CARRIERS[$f->carrier] ?? null),
                'trackingNumber' => $f->tracking_number,
                'shippedAt' => $f->shipped_at?->timezone('Asia/Tokyo')->format('Y/m/d') ?? '',
            ])->values()->all();

        return [
            'sender' => $gift->sender,
            'message' => $gift->message,
            'items' => array_values($items),
            'shipments' => $shipments,
            'expiresOn' => $gift->expires_at === null ? null : GiftStatus::date($gift->expires_at),
        ];
    }

    /**
     * 商品の 1 枚目の写真（公開している商品だけ。ショップの写真の道筋は公開している商品の写真だけを返す）
     */
    private function photo(?string $listingId): ?ListingPhoto
    {
        if ($listingId === null || ! $this->catalog->isPublished($listingId)) {
            return null;
        }

        $image = ListingImage::query()->where('listing_id', $listingId)->orderBy('position')->first();

        return $image === null ? null : new ListingPhoto($image->id, '', $image->width, $image->height);
    }

    /**
     * @return array<string, string|null>|null
     */
    private function entered(Request $request, GiftOrder $gift): ?array
    {
        $address = $request->session()->get(self::SESSION.'.'.$gift->id);

        return is_array($address) ? $address : null;
    }

    private function guardPost(Request $request, string $token): ?GiftOrder
    {
        $this->limit("gift-post|{$this->shopKey()}|".ClientSource::of($request->ip()), self::POSTS_PER_HOUR, 3600);
        $this->limit("gift-token|{$this->shopKey()}|".GiftOrders::hash($token), self::POSTS_PER_TOKEN_PER_HOUR, 3600);

        return $this->gifts->findByToken($token);
    }

    /**
     * 先に足してから比べる（同時に送っても上限を超えない）。超えたら 429
     */
    private function limit(string $key, int $max, int $seconds): void
    {
        abort_unless(Throttle::allow($key, $max, $seconds), 429);
    }

    private function shopKey(): string
    {
        return (string) tenant()?->getTenantKey();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(array $data, int $status = 200): Response
    {
        // リンクの token を次の画面や読み込み先へ渡さない・残さない・検索に載せないは、経路の宣言（secret-link）で核がまとめて掛ける
        return $this->view->renderApp('gift::receive', $data)->setStatusCode($status);
    }
}
