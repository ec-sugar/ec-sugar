<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use App\Core\Discount\Services\AutomaticDiscounts;
use App\Core\Discount\Services\CouponService;
use App\Core\Discount\Services\DiscountChoice;
use App\Core\Listing\Addons\AddonSelection;
use App\Core\Listing\ListingKinds;
use App\Core\Listing\Models\Listing;
use App\Core\Money\Allocation;
use App\Core\Money\Money;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Plugin\Services\PurchaseLimits;
use App\Core\Plugin\Services\SaleHolds;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Shop\Shipping\ShippingCalculator;
use App\Core\Shop\Shipping\ShippingDestination;
use App\Core\Tax\TaxClass;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Catalog\ListingCard;
use App\Storefront\Members\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 店頭のカート（ADR 0021）。カートを変えるのはここだけ。
 *
 * - 取引の前の下書きなので、記録の鎖のロックとイベントの外に置く。同時の操作は、そのカートの行のロックで守る
 * - 入れられるのは、公開中で固定価格の商品だけ。1 種類 10 個・在庫まで、1 つのカートに 20 種類まで
 * - お客さんとは合い言葉で紐付ける。DB には合い言葉の SHA-256 だけを置く。最後の操作から 30 日で期限切れ
 */
final class CartService
{
    public const int LIFETIME_DAYS = 30;

    public const int MAX_LINES = 20;

    public const int MAX_QUANTITY = 10;

    /** 0 円の品は 1 回の注文で 1 点まで */
    public const int FREE_MAX_QUANTITY = 1;

    /** App のご注文の決まり（1 回の上限・下限。ADR 0109）を読めなかった時（決まりが分からないまま売らない） */
    public const string LIMITS_UNREADABLE = 'ただいまご注文を受け付けられません。少し時間をおいて、もう一度お試しください。';

    /** 役務とほかの品が同じカートにある時の、役務の行の知らせ */
    public const string SERVICES_MIXED = 'サービスはほかの商品と別にご注文ください（ほかの商品を先にご注文いただくか、カートから削除してください）。';

    public function __construct(
        private readonly Catalog $catalog,
        private readonly ShippingCalculator $shipping,
        private readonly CouponService $coupons,
        private readonly AutomaticDiscounts $discounts,
        private readonly CustomerAccounts $accounts,
        private readonly PointService $points,
        private readonly PointSettings $pointSettings,
        private readonly ListingKinds $kinds,
        private readonly SaleHolds $holds,
        private readonly PurchaseLimits $limits,
    ) {}

    /**
     * 合い言葉からカートを探す（無い・期限切れ・形の違う合い言葉なら null）
     */
    public function find(?string $token): ?Cart
    {
        if ($token === null || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            return null;
        }

        return Cart::query()->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
    }

    /**
     * @return array{0: Cart, 1: string} [カート, 合い言葉（Cookie に入れる。ここ以外には残らない）]
     */
    public function create(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $cart = Cart::create(['token_hash' => hash('sha256', $token), 'expires_at' => CarbonImmutable::now()->addDays(self::LIFETIME_DAYS)]);

        return [$cart, $token];
    }

    /**
     * 種類をカートに入れる（ADR 0036。同じ種類で同じオプションの選び方の行があれば数を足す）。
     * オプションは呼び出し側が AddonSelection::resolve で今の定義と照らしたもの
     */
    public function add(Cart $cart, string $variantId, int $quantity, ?AddonSelection $addons = null): void
    {
        $this->change($cart, $variantId, static fn (int $current): int => $current + $quantity, $quantity, $addons ?? AddonSelection::none());
    }

    /**
     * 行の数を置き換える（0 なら外す）
     */
    public function setQuantity(Cart $cart, string $lineId, int $quantity): void
    {
        if ($quantity === 0) {
            $this->remove($cart, $lineId);

            return;
        }

        $line = CartLine::query()->where('cart_id', $cart->id)->whereKey($lineId)->first()
            ?? throw new CartException('カートにその商品はありません。');

        $this->change($cart, $line->variant_id, static fn (): int => $quantity, $quantity, AddonSelection::fromStored($line->addons), $line->id);
    }

    public function remove(Cart $cart, string $lineId): void
    {
        DB::transaction(function () use ($cart, $lineId): void {
            $this->lock($cart);
            CartLine::query()->where('cart_id', $cart->id)->whereKey($lineId)->delete();
            $this->touch($cart);
        });
    }

    /**
     * @param  ShippingDestination|null  $to  お届け先（注文の確かめ・確定は都道府県か贈り物のいちばん高い額。null はカートの目安）
     * @param  string|null  $buyerEmail  注文の買い手のメール（確かめの画面と確定。値引きの 1 人の回数をその台帳で見る。null なら会員の台帳）
     * @param  int  $points  使いたいポイント（会員の注文の確かめと確定。全部なら PHP_INT_MAX。残高と値引きの後の合計〔送料込み〕までに抑える。ADR 0068）
     * @param  string|null  $giftCardId  注文の入力で入れたギフトカード（ADR 0076。値引きとポイントの後の合計まで充てる。CartGiftCards）
     */
    public function view(?Cart $cart, ?ShippingDestination $to = null, ?string $buyerEmail = null, int $points = 0, ?string $giftCardId = null): CartView
    {
        if ($cart === null) {
            return CartView::empty();
        }

        [$views, $subtotal, $count, $minimum] = $this->lineViews($cart);

        // 送料は、今買える商品があるときだけ
        if ($count === 0) {
            return new CartView($views, 0, 0, couponCode: $cart->coupon_code, minimumSubtotal: $minimum);
        }

        // 値引き（ADR 0054・0065）: サーバーで決める。1 つの注文に 1 つ（自動の割引と入れたコードのクーポンから、送料を含めた合計がいちばん下がるもの）。
        // 送料の「◯円以上で無料」は値引きの後の小計で（ADR 0064: 配送の方法の便ごとに）。候補ごとに値引きの後の小計で送料を見積もり直す。
        // お届け先を知らないカートでは、カートの送料の表示と同じ目安（いちばん安い地域の額）で比べ、確かめの画面からはお届け先の額で比べ直す。
        // 送料の表と商品の配送の方法は 1 度だけ読み、候補ごとには額だけを計算し直す（注文の確定は店全体の台帳のロックの中で走るので、割引の数でクエリを増やさない）
        $destination = $to ?? ShippingDestination::estimate();
        $lines = self::couponLines($views);
        // 送らない品（ダウンロードなど）の行は送料の便に入れない（送料の「◯円以上で無料」の額にも数えない）
        $digital = array_column(array_filter($views, static fn (array $v): bool => $v['digital'] ?? false), 'lineId');
        $loaded = $this->shipping->load(array_column($lines, 'listingId'));
        $totalOf = fn (array $lines, int $discount, array $eligible, bool $waived): int => array_sum(array_column($lines, 'amount')) - $discount
            + ($waived ? 0 : $this->shipping->quote(self::shippingItems($lines, $discount, $eligible, $digital), $destination, $loaded)->fee());
        $choice = $this->chooseDiscount($cart, $lines, $buyerEmail, $totalOf);
        $outcome = $choice->applied;
        $discount = $outcome->discount ?? 0;
        $eligible = $outcome->eligible ?? [];
        $waived = $outcome?->shippingWaived === true;
        $quote = $this->shipping->quote(self::shippingItems($lines, $discount, $eligible, $digital), $destination, $loaded);
        $charged = $quote->fee();
        $shown = $waived ? $quote->waived() : $quote;
        $fee = $waived ? 0 : $charged;
        $points = $this->pointsFor($views, $discount, $eligible, $fee, $points);

        return new CartView($views, $subtotal, $count, $waived ? 0 : $charged, $waived ? null : ($quote->remainingForFree()['amount'] ?? null),
            discount: $discount, couponCode: $cart->coupon_code, couponNotice: $choice->couponNotice, shippingWaived: $waived, waivedShipping: $waived ? $charged : 0, shippingQuote: $shown,
            taxAmounts: self::taxAmounts($views, $discount, $eligible, $fee, $points->byRate),
            discountId: $outcome?->coupon->id, discountName: $outcome?->coupon->lineTitle(), discountAutomatic: $outcome?->coupon->isAutomatic() === true,
            discountKeys: $eligible, discountHint: $choice->hint, points: $points,
            // ギフトカード（ADR 0076）: 値引き → ポイントの後の合計（送料込み）に充てる。支払いの一部なので税率ごとの額は変えない
            giftCard: app(CartGiftCards::class)->apply($giftCardId, $subtotal - $discount - $points->used + $fee),
            minimumSubtotal: $minimum);
    }

    /**
     * ポイント（ADR 0068）: 使う数・税率ごとの割り振り・付くポイント。会員だけが使えて、付くポイントもログインした会員の分。
     * 使う数は「使いたい数・残高・値引きの後の合計（送料込み）」のいちばん小さい数。ただし使った後の合計が 1〜49 円になるなら、50 円が残る数に抑える。値引きの後の商品（率ごと）と送料に金額の割合で割り振る（送料の分は標準の率）。
     * 値引きの後の率ごとの額は、税率ごとの内訳（taxAmounts）と注文の確定（PlaceOrder::addDiscount）と同じ割り振りで出す（端数が食い違わない）。
     * 付くポイントは、商品の代金（値引きと使ったポイントの後、税込み、送料を除く）× 店の率（会員は特典の上乗せを足す。ADR 0079）の切り捨て。ゲストには「会員なら」の数だけ
     *
     * @param  list<array{purchasable: bool, lineId: string, lineTotal: int, taxRateBp: int, rateParts?: array<int, int>}>  $views
     * @param  list<string>  $eligible
     */
    private function pointsFor(array $views, int $discount, array $eligible, int $fee, int $requested): CartPoints
    {
        if (! $this->pointSettings->enabled()) {
            return CartPoints::none();
        }

        $member = self::memberWithPoints();
        // 付ける率は店の率に、会員の特典の上乗せ（ADR 0079）を足したもの。ゲストの「会員なら」は店の率だけ
        $rate = $member === null ? $this->pointSettings->rateBp() : $this->pointSettings->rateBpFor($member);
        $weights = [];
        $discounted = [];
        foreach ($views as $view) {
            if ($view['purchasable']) {
                foreach (self::rateParts($view) as $taxRate => $part) {
                    $weights[$taxRate] = ($weights[$taxRate] ?? 0) + $part;

                    if (in_array($view['lineId'], $eligible, true)) {
                        $discounted[$taxRate] = ($discounted[$taxRate] ?? 0) + $part;
                    }
                }
            }
        }
        ksort($weights);
        ksort($discounted);
        foreach ($discount > 0 ? Allocation::byWeight($discount, $discounted) : [] as $taxRate => $share) {
            $weights[$taxRate] -= $share;
        }
        $goods = array_sum($weights);

        if ($member === null) {
            return new CartPoints(member: false, earn: PointService::earnFor($goods, $rate));
        }

        $balance = $this->points->balance($member);
        $total = $goods + $fee;
        $use = max(0, min($requested, $balance, $total));
        // 使った後の合計は 0 円か 50 円以上（Stripe の円の最低額。ADR 0068 の作って決めたこと）。1〜49 円が残るなら 50 円が残る数に抑える
        // （「全部使う」で残高が合計まであれば上の min で 0 円になる。「数を入れる」で 1〜49 円になる数は、入力の画面と確かめの画面が理由つきで断る）
        $lowered = $total - $use >= 1 && $total - $use < CartPoints::MINIMUM_CHARGE;
        if ($lowered) {
            $use = max(0, $total - CartPoints::MINIMUM_CHARGE);
        }
        $shares = Allocation::byWeight($use, $weights + ['shipping' => $fee]);
        $onShipping = (int) $shares['shipping'];
        unset($shares['shipping']);
        $byRate = [];
        foreach ($shares as $taxRate => $share) {
            $byRate[(int) $taxRate] = $share;
        }
        if ($onShipping > 0) {
            $byRate[TaxClass::SHIPPING->rateBp()] = ($byRate[TaxClass::SHIPPING->rateBp()] ?? 0) + $onShipping;
        }
        ksort($byRate);
        $base = $goods - ($use - $onShipping);

        return new CartPoints(member: true, earn: PointService::earnFor($base, $rate), used: $use, balance: $balance,
            byRate: array_filter($byRate, static fn (int $v): bool => $v > 0), onShipping: $onShipping, earnBase: $base, lowered: $lowered);
    }

    /**
     * ログインした会員で、ポイントを貯めて使える台帳の ID（ゲスト・会員でなくなった台帳は null）
     */
    private static function memberWithPoints(): ?string
    {
        $member = Auth::guard('customer')->user();

        return $member instanceof Member && $member->belongsHere() && PointService::isMember($member->account) ? $member->account->id : null;
    }

    /**
     * カートの数（今買える商品の点数）だけ。ショップのどの画面にも出すので、値引きと送料は計算しない
     */
    public function count(?Cart $cart): int
    {
        return $cart === null ? 0 : $this->lineViews($cart)[2];
    }

    /**
     * カートの行を今の商品・在庫・オプションで照らし直した形と、今買える行の小計・点数
     *
     * @return array{0: list<array{card: ListingCard|null, title: string, quantity: int, maxQuantity: int, lineTotal: int, lineTotalLabel: string, purchasable: bool, notice: string|null, listingId: string, lineId: string, taxRateBp: int, digital?: bool, inPerson?: bool}>, 1: int, 2: int, 3: int}
     */
    private function lineViews(Cart $cart): array
    {
        $lines = $cart->lines()->get();
        $listings = $this->catalog->purchasableVariants($lines->pluck('variant_id')->all());
        // App が売り方を押さえた商品（抽選販売など。ADR 0080）は注文に進めない
        $holds = $this->holds->of($lines->pluck('listing_id')->all());
        // App のご注文の決まり（ADR 0109）: 商品ごとの 1 回の上限と、商品の合計の下限。読めなければ全部の行を止める
        $rules = $this->limits->forCart(array_values(array_map(strval(...), $lines->pluck('listing_id')->all())));
        $unreadable = $rules === null;
        $limits = $rules['perOrder'] ?? [];
        $minimum = $rules['minimum'] ?? 0;
        // 同じ商品を種類・オプション違いで何行も入れたときは、上限を行の順に分ける
        $taken = [];
        $views = [];
        $subtotal = 0;
        $count = 0;
        // 同じ種類をオプション違いで何行も入れたときは、在庫を行の順に分ける
        $used = [];
        // 役務（設置の代行など）は、ほかの種類の品と同じ注文に入れない（受渡の済ませ方が違う）。混ざっていたら役務の行を止める
        $known = array_intersect_key($listings, array_flip($lines->pluck('variant_id')->all()));
        $servicesMixed = in_array(true, array_column($known, 'inPerson'), true) && in_array(false, array_column($known, 'inPerson'), true);

        foreach ($lines as $line) {
            $listing = $listings[$line->variant_id] ?? null;

            if ($listing === null) {
                $views[] = ['card' => null, 'title' => '販売を終了した商品', 'quantity' => $line->quantity, 'maxQuantity' => 0, 'lineTotal' => 0,
                    'lineTotalLabel' => Money::format(0), 'purchasable' => false, 'notice' => 'この商品は販売を終了しました。カートから削除してください。', 'listingId' => $line->listing_id, 'lineId' => $line->id,
                    'taxRateBp' => TaxClass::Standard->rateBp()];

                continue;
            }

            ['card' => $card, 'available' => $variantAvailable] = $listing;
            $available = max(0, $variantAvailable - ($used[$line->variant_id] ?? 0));
            $notice = null;
            [$addons, $addonNotice] = $this->recheckAddons($line, $listing['listingId']);

            // カートに入れさせない種類（入れた後に扱いが変わった・古いカート）は注文に進めない
            $refusal = $holds[$listing['listingId']]['message'] ?? $this->kinds->cartRefusal($listing['kind']);

            $limit = $limits[$listing['listingId']] ?? null;
            $left = $limit === null ? null : max(0, $limit - ($taken[$listing['listingId']] ?? 0));

            if ($unreadable) {
                $notice = self::LIMITS_UNREADABLE;
                $addons = null;
            } elseif ($refusal !== null) {
                $notice = $refusal;
                $addons = null;
            } elseif ($servicesMixed && $listing['inPerson']) {
                $notice = self::SERVICES_MIXED;
                $addons = null;
            } elseif ($addonNotice !== null) {
                $notice = $addonNotice;
            } elseif ($available === 0) {
                $notice = '売り切れになりました。';
            } elseif ($line->quantity > $available) {
                $notice = "在庫が少なくなりました（残り {$available} 点）。数量を減らしてください。";
            } elseif ($card->price === 0 && ($used[$line->variant_id] ?? 0) + $line->quantity > self::FREE_MAX_QUANTITY) {
                $notice = '無料の商品は 1 回のご注文で '.self::FREE_MAX_QUANTITY.' 点までです。数量を減らしてください。';
            } elseif ($left !== null && $line->quantity > $left) {
                $notice = "この商品は 1 回のご注文で {$limit} 点までです。数量を減らしてください。";
            } elseif ($card->price !== $line->added_unit_amount) {
                $notice = 'カートに入れた後に価格が変わりました（'.Money::format($line->added_unit_amount).' → '.$card->priceLabel().'）。';
            }

            $max = $card->price === 0 ? max(0, self::FREE_MAX_QUANTITY - ($used[$line->variant_id] ?? 0)) : self::MAX_QUANTITY;
            $max = $left === null ? $max : min($max, $left);
            $purchasable = $addons !== null && $available > 0 && $line->quantity <= $available && $line->quantity <= $max;
            $lineTotal = ($card->price + ($addons?->amount() ?? 0)) * $line->quantity;
            // 行の額を税率ごとに（オプションに税区分があれば、その分は別の率。注文の明細〔PlaceOrder〕と同じ分け方）
            $rateParts = [$card->taxRateBp => $card->price * $line->quantity];
            foreach ($addons?->amountsByRate($card->taxRateBp) ?? [] as $rate => $amount) {
                $rateParts[$rate] = ($rateParts[$rate] ?? 0) + $amount * $line->quantity;
            }
            $used[$line->variant_id] = ($used[$line->variant_id] ?? 0) + $line->quantity;
            // 上限の枠を使うのは買える行だけ（売り切れの行のせいで、買える行に「数を減らしてください」を出さない）
            if ($purchasable) {
                $taken[$listing['listingId']] = ($taken[$listing['listingId']] ?? 0) + $line->quantity;
            }
            $title = $addons === null || $addons->isEmpty() ? $card->title : $card->title.'／'.$addons->summary();
            $views[] = ['card' => $card, 'title' => $title, 'quantity' => $line->quantity, 'maxQuantity' => min($max, $available),
                'lineTotal' => $lineTotal, 'lineTotalLabel' => Money::format($lineTotal, $card->currency), 'purchasable' => $purchasable, 'notice' => $notice, 'listingId' => $line->listing_id, 'lineId' => $line->id,
                'taxRateBp' => $card->taxRateBp, 'rateParts' => $rateParts, 'digital' => $listing['digital'], 'inPerson' => $listing['inPerson']];

            if ($purchasable) {
                $subtotal += $lineTotal;
                $count += $line->quantity;
            }
        }

        return [$views, $subtotal, $count, $minimum];
    }

    /**
     * 行の額の税率ごとの内訳（オプションに別の税区分があると 2 つの率に分かれる）。内訳の無い行は行の率に全部
     *
     * @param  array{lineTotal: int, taxRateBp: int, rateParts?: array<int, int>}  $view
     * @return array<int, int>
     */
    private static function rateParts(array $view): array
    {
        return $view['rateParts'] ?? [$view['taxRateBp'] => $view['lineTotal']];
    }

    /**
     * 税率ごとの額（ADR 0063。確かめの画面の内訳）。注文の確定（PlaceOrder）と同じ分け方: 商品は商品の率（オプションは税区分があればその率）、
     * 値引きは対象の行の額の率ごとの合計で率へ割り振り、送料は標準
     *
     * @param  list<array{purchasable: bool, lineId: string, lineTotal: int, taxRateBp: int, rateParts?: array<int, int>}>  $views
     * @param  list<string>  $eligible  値引きの対象の行の鍵
     * @param  array<int, int>  $points  使ったポイントの税率ごとの割り振り（ADR 0068。値引きと同じく率ごとの額から引く）
     * @return array<int, int> 税率 => 額
     */
    public static function taxAmounts(array $views, int $discount, array $eligible, int $shipping, array $points = []): array
    {
        $amounts = [];
        $weights = [];

        foreach ($views as $view) {
            if (! $view['purchasable']) {
                continue;
            }

            foreach (self::rateParts($view) as $rate => $part) {
                $amounts[$rate] = ($amounts[$rate] ?? 0) + $part;

                if (in_array($view['lineId'], $eligible, true)) {
                    $weights[$rate] = ($weights[$rate] ?? 0) + $part;
                }
            }
        }

        ksort($weights);

        foreach ($discount > 0 ? Allocation::byWeight($discount, $weights) : [] as $rate => $share) {
            $amounts[$rate] -= $share;
        }

        if ($shipping > 0) {
            $rate = TaxClass::SHIPPING->rateBp();
            $amounts[$rate] = ($amounts[$rate] ?? 0) + $shipping;
        }

        foreach ($points as $rate => $share) {
            $amounts[$rate] = ($amounts[$rate] ?? 0) - $share;
        }

        ksort($amounts);

        return $amounts;
    }

    /**
     * 送料を決める品の形（ADR 0064）: 今買える行ごとに、値引き（クーポン・自動の割引）を対象の行へ金額の割合で割り振った後の額（ADR 0054 の 7 と同じ割り振り）
     *
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines  今買える行（couponLines の形）
     * @param  list<string>  $eligible  値引きの対象の行
     * @param  list<string>  $digital  送らない品の行（値引きの割り振りには入れ、送料の便からは外す）
     * @return list<array{key: string, listingId: string, amount: int}>
     */
    private static function shippingItems(array $lines, int $discount, array $eligible, array $digital = []): array
    {
        $items = [];
        $weights = [];

        foreach ($lines as $index => $line) {
            $items[$index] = ['key' => $line['key'], 'listingId' => $line['listingId'], 'amount' => $line['amount']];

            if (in_array($line['key'], $eligible, true)) {
                $weights[$index] = $line['amount'];
            }
        }

        foreach (Allocation::byWeight(min($discount, array_sum($weights)), $weights) as $index => $share) {
            $items[$index]['amount'] -= $share;
        }

        return array_values(array_filter($items, static fn (array $item): bool => ! in_array($item['key'], $digital, true)));
    }

    /**
     * カートにクーポンのコードを入れる（照らした答えは開くたびに出し直す。使えない理由もカートに出す）
     */
    public function applyCoupon(Cart $cart, string $raw): void
    {
        $coupon = $this->coupons->find($raw);

        if ($coupon === null || $coupon->status !== 'active') {
            throw new CartException(CouponService::UNKNOWN);
        }

        DB::transaction(function () use ($cart, $coupon): void {
            $this->lock($cart);
            Cart::query()->whereKey($cart->id)->update(['coupon_code' => $coupon->code]);
            $this->touch($cart);
        });
        $cart->coupon_code = $coupon->code;
    }

    public function removeCoupon(Cart $cart): void
    {
        DB::transaction(function () use ($cart): void {
            $this->lock($cart);
            Cart::query()->whereKey($cart->id)->update(['coupon_code' => null]);
            $this->touch($cart);
        });
        $cart->coupon_code = null;
    }

    /**
     * 今買える行の形（値引きを照らす・注文で割り振る。quantity はまとめ買いの数の条件に使う）
     *
     * @param  list<array{purchasable: bool, lineId: string, listingId: string, lineTotal: int, quantity: int}>  $views
     * @return list<array{key: string, listingId: string, amount: int, quantity: int}>
     */
    public static function couponLines(array $views): array
    {
        $lines = [];
        foreach ($views as $view) {
            if ($view['purchasable']) {
                $lines[] = ['key' => $view['lineId'], 'listingId' => $view['listingId'], 'amount' => $view['lineTotal'], 'quantity' => $view['quantity']];
            }
        }

        return $lines;
    }

    /**
     * 効かせる値引きを決める（ADR 0065。自動の割引と入れたコードのクーポン）。
     * 会員だけの割引はログインした会員にだけ。1 人の回数は discountAccounts() の台帳で数える
     *
     * @param  list<array{key: string, listingId: string, amount: int, quantity: int}>  $lines
     * @param  \Closure(list<array{key: string, listingId: string, amount: int, quantity?: int}>, int, list<string>, bool): int  $totalOf  その値引きを効かせた時の最終の合計（送料を含む）
     */
    private function chooseDiscount(Cart $cart, array $lines, ?string $buyerEmail, \Closure $totalOf): DiscountChoice
    {
        $code = $cart->coupon_code === null ? null : $this->coupons->find($cart->coupon_code);

        $member = self::memberAccount();

        // 会員価格（ADR 0079）もログインした会員の台帳で同じ候補に入る
        return $this->discounts->choose($lines, $code, $this->discountAccounts($buyerEmail), $member !== null, $totalOf, $member);
    }

    /**
     * 値引きの 1 人の回数を数える台帳（ADR 0065。カートの表示・確かめの画面・確定・使った記録で同じ決め方）:
     * ログインした会員の台帳と、注文の買い手のメールの台帳（分かれば。確かめの画面と確定）の両方。会員が注文のメールを別の宛先に書き換えても、会員の台帳で数えるので越えられない。
     * ゲストはメールの台帳だけ（カートではメールを知らないので数えず、確かめの画面から）。記録には最初の台帳（会員ならその台帳）を残す
     *
     * @param  string|null  $emailAccount  確定で作った・引いた買い手の台帳（渡されればメールで引き直さない）
     * @return list<string>
     */
    public function discountAccounts(?string $buyerEmail, ?string $emailAccount = null): array
    {
        $email = $emailAccount ?? ($buyerEmail === null ? null : $this->accounts->findByEmail($buyerEmail)?->id);

        return array_values(array_unique(array_filter([self::memberAccount(), $email], static fn (?string $id): bool => $id !== null)));
    }

    private static function memberAccount(): ?string
    {
        $member = Auth::guard('customer')->user();

        return $member instanceof Member && $member->belongsHere() ? $member->account->id : null;
    }

    /**
     * 期限の切れたカートを消す（毎日。storefront:prune-carts）
     */
    public function prune(): int
    {
        return Cart::query()->where('expires_at', '<=', now())->delete();
    }

    /**
     * @param  \Closure(int): int  $next  今の数から新しい数を出す
     */
    private function change(Cart $cart, string $variantId, \Closure $next, int $requested, AddonSelection $addons, ?string $lineId = null): void
    {
        if ($requested < 1 || $requested > self::MAX_QUANTITY) {
            throw new CartException('数量は 1〜'.self::MAX_QUANTITY.' の範囲で指定してください。');
        }

        DB::transaction(function () use ($cart, $variantId, $next, $addons, $lineId): void {
            $this->lock($cart);
            $listing = $this->catalog->purchasableVariants([$variantId])[$variantId] ?? null;

            if ($listing === null) {
                throw new CartException('この商品は現在カートに入れられません。');
            }

            // App の 1 品の注文の画面だけで買う種類（チケットなど）
            if (($refusal = $this->kinds->cartRefusal($listing['kind'])) !== null) {
                throw new CartException($refusal);
            }

            // App が売り方を押さえた商品（抽選販売など。ADR 0080）
            if (($hold = $this->holds->for($listing['listingId'])) !== null) {
                throw new CartException($hold['message']);
            }

            $line = $lineId !== null
                ? CartLine::query()->where('cart_id', $cart->id)->whereKey($lineId)->first()
                : CartLine::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->where('addons_key', $addons->key())->first();
            $quantity = $next($line->quantity ?? 0);
            // 同じ種類のほかの行（オプション違い）の数は、在庫から先に引く
            $others = (int) CartLine::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->when($line !== null, static fn ($q) => $q->whereKeyNot($line->id))->sum('quantity');
            // 無料の品の「1 点まで」は種類ごと（オプション違いの行も合わせて数える）
            $cap = $listing['card']->price === 0 ? max(0, self::FREE_MAX_QUANTITY - $others) : self::MAX_QUANTITY;
            $limit = min($cap, max(0, $listing['available'] - $others));
            // 入れた後に在庫が減った・0 円になった（1 点まで）行は、上限を超えていても減らすのは受け付ける。
            // 減らしてもまだ超えていれば、カートの知らせ（view()）が残り、注文には進めない。同じ数の送り直しも減らす側に入れる
            $decreasing = $line !== null && $quantity <= $line->quantity;

            if ($listing['available'] - $others <= 0 && ! $decreasing) {
                throw new CartException('売り切れです。');
            }

            if ($quantity > $limit && ! $decreasing) {
                throw new CartException($limit === 0 && $listing['card']->price === 0
                    ? '無料の商品は 1 回のご注文で '.self::FREE_MAX_QUANTITY.' 点までです。'
                    : "この商品は {$limit} 点までです。");
            }

            if ($line === null) {
                if (CartLine::query()->where('cart_id', $cart->id)->count() >= self::MAX_LINES) {
                    throw new CartException('カートに入れられるのは '.self::MAX_LINES.' 種類までです。');
                }

                CartLine::create(['cart_id' => $cart->id, 'listing_id' => $listing['listingId'], 'variant_id' => $variantId, 'quantity' => $quantity, 'added_unit_amount' => $listing['card']->price,
                    'addons' => $addons->isEmpty() ? null : $addons->items, 'addons_key' => $addons->key()]);
            } else {
                $line->quantity = $quantity;
                $line->save();
            }

            $this->touch($cart);
        });
    }

    /**
     * 行のオプションを今の定義で照らし直す（無くなった・選択肢が変わった・必須が増えた）。照らせなければ [null, 知らせ]
     *
     * @return array{0: AddonSelection|null, 1: string|null}
     */
    private function recheckAddons(CartLine $line, string $listingId): array
    {
        $stored = AddonSelection::fromStored($line->addons);
        $listing = Listing::query()->find($listingId);

        try {
            $current = $listing === null ? $stored : AddonSelection::resolve($listing, $stored->asInput());
        } catch (\InvalidArgumentException) {
            return [null, 'オプションの内容が変わりました。カートから削除して、選び直してください。'];
        }

        return $current->amount() !== $stored->amount()
            ? [$current, 'カートに入れた後にオプションの料金が変わりました。']
            : [$current, null];
    }

    /**
     * そのカートの行だけを押さえる（ほかのお客さんは待たせない）
     */
    private function lock(Cart $cart): void
    {
        Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
    }

    private function touch(Cart $cart): void
    {
        Cart::query()->whereKey($cart->id)->update(['expires_at' => CarbonImmutable::now()->addDays(self::LIFETIME_DAYS), 'updated_at' => now()]);
    }
}
