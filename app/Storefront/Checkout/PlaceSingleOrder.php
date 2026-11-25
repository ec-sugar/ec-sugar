<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\ComponentStock;
use App\Core\Inventory\Services\InsufficientStockException;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\ListingKinds;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Order\AgeChecks;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\Party;
use App\Core\Plugin\AgeCheck\AgeAnswer;
use App\Core\Plugin\AgeCheck\AgeCheckRefused;
use App\Core\Plugin\MemberBenefits;
use App\Core\Plugin\Services\PurchaseLimits;
use App\Core\Plugin\Services\SaleHolds;
use App\Core\Plugin\Services\StockComponents;
use App\Core\Points\Services\PointService;
use App\Core\Sample\Demo\DemoOrders;
use App\Core\Shop\Shipping\ShippingCalculator;
use App\Core\Shop\ShippingPolicy;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Cart\CartService;
use Illuminate\Support\Facades\DB;

/**
 * カートを通さない 1 つの商品の注文（ADR 0050。定期便の 1 回目など、App の申し込みの画面から）。決まりは今の注文の確定（PlaceOrder）と同じ:
 *
 * - 同じ注文の合い言葉（二重送信）なら最初の注文を返す（連鎖のロックの中で）
 * - 価格はサーバーの商品から。売る固定価格の商品だけ。在庫は拠点ごとに押さえる。送料は送料の表（商品の方法とお届け先の都道府県。ADR 0064）
 * - 値引き（定期の値引きなど）は割合（万分率）で、調整の行（discount）として足す。合計が 1 円未満の注文は作らない
 * - 税率は商品の区分から（ADR 0063）。約束の価格の差と値引きの行は商品と同じ率、送料は標準。定期便の回は、その回の時の区分で
 * - お客さんは渡された会員の台帳につなぐ（買い手のメールは会員のメール）。名前・住所は暗号化した列へ、イベントに個人情報を入れない
 * - ショップの注文の印（CheckoutOrder）を付ける（ご注文の確認・マイページ・注文の知らせのメールが今の注文と同じに扱う）
 * - ポイント（ADR 0068 の 9）: 会員なら発送で付く予定の数（商品の代金〔値引きの後〕× 今の店の率）を注文に写す。ここではポイントを使えない（使うのはカートを通る注文だけ）
 * - 来店で受け渡す品（予約の品など。placeInPerson）は、お届け先・送料・在庫なしで受渡を役務（in_person）にする。買い手は名前・電話（暗号化した列）と会員のメールだけ
 * - 送らない品（チケットなど。placeDigital）は、お届け先・送料なしで受渡を digital にする。買い手は名前と会員のメールだけ（電話・住所を持たない）
 * - 年齢の確かめ（ADR 0077）: 年齢の要る品は、錠の中で答え（App の申し込みの画面で受けた生年月日で数えた年齢と同意。$age）か、
 *   答えが無ければ会員が前に確かめた年齢で照らし、確かめた事実を同じトランザクションのイベントに残す。どちらも足りなければ断る（定期便の回も同じ）
 * - 会員だけ・ランクだけの商品（ADR 0079）は、ショップのお客さんの申し込み（actor が storefront）なら、その会員に見せない商品を錠の中で断る（URL を直に打っても買えない）。
 *   定期便の 2 回目からなど仕組みが作る注文は、申し込みの時の約束なので断らない
 * - App が売り方を押さえた商品（抽選販売など。ADR 0080）は、押さえた App（$heldBy）からでなければ断る（錠の中で確かめる）
 * - デモの店（ADR 0086）では、ショップのお客さんの申し込み（actor が storefront）を来訪者ごとに 1 日 5 件までにする（DemoOrders。
 *   同じ合い言葉の二重送信は数えない。仕組みが作る定期便の回・抽選の当選は数えない）
 */
final class PlaceSingleOrder
{
    public const string UNAVAILABLE = 'この商品は現在お申し込みいただけません（売り切れ・在庫不足・販売終了のいずれかです）。';

    public function __construct(
        private readonly EventStore $events,
        private readonly TransactionService $transactions,
        private readonly FulfillmentService $fulfillments,
        private readonly ShippingCalculator $shipping,
        private readonly PointService $points,
        private readonly AgeChecks $ageChecks,
    ) {}

    /**
     * @param  int  $discountBp  値引きの割合（万分率。10% は 1000、5000 まで）
     * @param  ShippingPolicy|null  $agreedShipping  約束した送料の決まり（定期便の 2 回目から）
     * @param  int|null  $agreedUnitAmount  約束した単価（定期便の 2 回目から。今の価格と違えば差を調整の行 price_agreed で足し、値引きは約束の単価から）
     * @param  bool  $storedPayment  預けたカードで請求する注文（定期便の回・抽選の当選）。払われていない注文の片付けが取り消さない
     * @param  string|null  $heldBy  売り方を押さえた App の名前（抽選販売の当選の注文。ADR 0080）。押さえた商品は、その App からだけ注文にできる
     */
    public function place(Listing $listing, ListingVariant $variant, int $quantity, int $discountBp, string $discountLabel, CheckoutInput $input, CustomerAccount $account, string $orderKey, string $actor, ?int $agreedUnitAmount = null, bool $storedPayment = false, ?ShippingPolicy $agreedShipping = null, ?AgeAnswer $age = null, ?string $heldBy = null): Transaction
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $orderKey) !== 1) {
            throw new OrderException('画面の有効期限が切れました。ページを再読み込みして、もう一度お試しください。', changed: true);
        }

        if ($quantity < 1 || $quantity > 99 || $discountBp < 0 || $discountBp > 5000 || $variant->listing_id !== $listing->id || ($agreedUnitAmount !== null && $agreedUnitAmount < 1)) {
            throw new OrderException(self::UNAVAILABLE);
        }

        // 送らない種類（チケットなど）は送る注文にしない（定期便などの App の申し込みから来ても）
        if (app(ListingKinds::class)->cartRefusal($listing->kind) !== null) {
            throw new OrderException(self::UNAVAILABLE);
        }

        $keyHash = hash('sha256', $orderKey);

        try {
            return DB::transaction(function () use ($listing, $variant, $quantity, $discountBp, $discountLabel, $input, $account, $keyHash, $actor, $agreedUnitAmount, $storedPayment, $agreedShipping, $age, $heldBy): Transaction {
                $this->events->lock();
                $existing = CheckoutOrder::query()->where('key_hash', $keyHash)->first();

                if ($existing !== null) {
                    return Transaction::query()->findOrFail($existing->transaction_id);
                }

                self::admitDemo($account, $actor);
                self::refuseHeld($listing->id, $heldBy);
                self::refuseOverLimit($listing->id, $quantity, $actor);

                $listing = Listing::query()->with('seller')->lockForUpdate()->findOrFail($listing->id);
                $variant = ListingVariant::query()->whereKey($variant->id)->where('listing_id', $listing->id)->firstOrFail();

                if ($listing->status !== ListingStatus::Active || $variant->price($listing) === null || $this->hiddenFrom($listing, $account, $actor)) {
                    throw new OrderException(self::UNAVAILABLE);
                }

                $ageRequired = $this->checkAge($listing->id, $age, $account->id);

                $customer = Party::create([
                    'kind' => PartyKind::Customer,
                    'name' => $input->buyer['name'],
                    'email' => $account->email,
                    'phone' => $input->buyer['phone'],
                    'address' => self::address($input->buyer),
                ]);

                $taxRate = $listing->taxRateBp();
                [$drafts, $locations] = self::allocate($listing, $variant, $quantity, $taxRate);
                $subtotal = array_sum(array_map(static fn (LineDraft $d): int => $d->toArray()['amount'], $drafts));

                // 約束した単価と今の価格の差（値上げ・値下げを知らせた回より前は、約束の単価で）
                if ($agreedUnitAmount !== null && $agreedUnitAmount * $quantity !== $subtotal) {
                    $drafts[] = LineDraft::adjustment('price_agreed', '定期便のお約束の価格', $agreedUnitAmount * $quantity - $subtotal, $taxRate);
                    $subtotal = $agreedUnitAmount * $quantity;
                }

                $discount = intdiv($subtotal * $discountBp, 10000);

                if ($discount > 0) {
                    $drafts[] = LineDraft::adjustment('discount', mb_substr($discountLabel, 0, 200), -$discount, $taxRate);
                }

                // 定期便の 2 回目からは約束の送料の決まり（ADR 0049 の 4）。無ければ今の送料の表（商品の方法とお届け先の都道府県。ADR 0064）
                $current = $this->shipping->policyFor($listing->id, (string) $input->shipTo()['prefecture']);
                $shipping = ($agreedShipping ?? $current)->feeFor($subtotal - $discount);

                // 送料は配送の対価なので、商品の率によらず標準（ADR 0063）
                if ($shipping > 0) {
                    $drafts[] = LineDraft::adjustment('shipping', '送料', $shipping, TaxClass::SHIPPING->rateBp());
                }

                $transaction = $this->transactions->open('sale', $listing->seller, $customer, $drafts, actor: $actor, customerAccount: $account);

                if ((int) $transaction->total_amount < 1) {
                    throw new OrderException(self::UNAVAILABLE);
                }

                $this->ageChecks->record($transaction->id, $ageRequired, $age, $account->id, $actor);

                $byLocation = [];
                foreach ($transaction->lines()->where('line_type', LineType::Item)->orderBy('id')->get() as $line) {
                    $byLocation[(string) $line->location_id][(string) $line->id] = (int) $line->quantity;
                }
                foreach ($byLocation as $locationId => $items) {
                    $this->fulfillments->create($transaction, FulfillmentMethod::Shipping, $items, $locations[$locationId], self::shipTo($input), $actor, shippingMethod: $current->method);
                }

                CheckoutOrder::create(['key_hash' => $keyHash, 'transaction_id' => $transaction->id, 'pays_with' => $storedPayment ? 'stored' : 'checkout']);
                // 付く予定のポイント（会員だけ。使わない店・率 0 では何も書かない）
                $this->points->recordOrder($transaction->id, $account->id, 0, $subtotal - $discount, [], $actor);

                return $transaction;
            });
        } catch (InsufficientStockException) {
            throw new OrderException(self::UNAVAILABLE);
        }
    }

    /**
     * 来店で受け渡す品の 1 品の注文（予約の品など。ADR 0056）。決まりは place と同じ（二重送信・価格はサーバー・ショップの注文の印・付く予定のポイント）で、
     * お届け先・送料が無く、在庫も押さえない（売れる数は App が持つ。明細に拠点を付けない）。受渡は役務（in_person）を 1 つ。
     * 買い手は名前と電話（暗号化した列）と会員のメール。$paysWith は払い方（checkout は決済代行の画面、on_site は当日払い。当日払いの支払いの記録は呼ぶ側が
     * 同じトランザクションの中で DirectPayments::begin で始める）。呼ぶ側が先に連鎖のロックを取ったトランザクションの中から呼んでよい（同じロックを取り直すだけ）
     *
     * @param  string  $paysWith  checkout か on_site（ほかは断る）
     */
    public function placeInPerson(Listing $listing, ListingVariant $variant, int $quantity, string $buyerName, string $buyerPhone, CustomerAccount $account, string $orderKey, string $actor, string $paysWith = 'checkout', ?AgeAnswer $age = null, ?string $heldBy = null): Transaction
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $orderKey) !== 1) {
            throw new OrderException('画面の有効期限が切れました。ページを再読み込みして、もう一度お試しください。', changed: true);
        }

        $buyerName = trim($buyerName);
        $buyerPhone = trim($buyerPhone);

        if ($quantity < 1 || $quantity > 99 || $variant->listing_id !== $listing->id || $buyerName === '' || mb_strlen($buyerName) > 60
            || preg_match('/\A[0-9]{10,11}\z/', $buyerPhone) !== 1 || ! in_array($paysWith, ['checkout', 'on_site'], true)) {
            throw new OrderException(self::UNAVAILABLE);
        }

        $keyHash = hash('sha256', $orderKey);

        return DB::transaction(function () use ($listing, $variant, $quantity, $buyerName, $buyerPhone, $account, $keyHash, $actor, $paysWith, $age, $heldBy): Transaction {
            $this->events->lock();
            $existing = CheckoutOrder::query()->where('key_hash', $keyHash)->first();

            if ($existing !== null) {
                return Transaction::query()->findOrFail($existing->transaction_id);
            }

            self::admitDemo($account, $actor);
            self::refuseHeld($listing->id, $heldBy);
            self::refuseOverLimit($listing->id, $quantity, $actor);

            $listing = Listing::query()->with('seller')->lockForUpdate()->findOrFail($listing->id);
            $variant = ListingVariant::query()->whereKey($variant->id)->where('listing_id', $listing->id)->firstOrFail();

            if ($listing->status !== ListingStatus::Active || $variant->price($listing) === null || $this->hiddenFrom($listing, $account, $actor)) {
                throw new OrderException(self::UNAVAILABLE);
            }

            $ageRequired = $this->checkAge($listing->id, $age, $account->id);

            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => $buyerName, 'email' => $account->email, 'phone' => $buyerPhone]);
            // 拠点を付けない明細（在庫を押さえない。売れる数は App が持つ）
            $draft = LineDraft::fromVariant($listing, $variant, $quantity, $listing->taxRateBp());
            $transaction = $this->transactions->open('sale', $listing->seller, $customer, [$draft], actor: $actor, customerAccount: $account);

            if ((int) $transaction->total_amount < 1) {
                throw new OrderException(self::UNAVAILABLE);
            }

            $this->ageChecks->record($transaction->id, $ageRequired, $age, $account->id, $actor);

            $items = $transaction->lines()->where('line_type', LineType::Item)->orderBy('id')->pluck('quantity', 'id')->map(static fn (mixed $n): int => (int) $n)->all();
            $this->fulfillments->create($transaction, FulfillmentMethod::InPerson, $items, null, null, $actor);

            CheckoutOrder::create(['key_hash' => $keyHash, 'transaction_id' => $transaction->id, 'pays_with' => $paysWith]);
            $this->points->recordOrder($transaction->id, $account->id, 0, $draft->amount, [], $actor);

            return $transaction;
        });
    }

    /**
     * 送らない品の 1 品の注文（チケットなど）。決まりは place と同じ（二重送信・価格はサーバー・在庫は拠点ごと・ショップの注文の印・付く予定のポイント）で、
     * お届け先と送料が無く、受渡は digital（拠点ごと）。買い手は名前（暗号化した列）と会員のメールだけ。
     * 呼ぶ側が先に連鎖のロックを取ったトランザクションの中から呼んでよい（同じロックを取り直すだけ）。
     * $unitAmount を渡すと、在庫を押さえない注文にする（明細に拠点を付けず、受渡は拠点なしの digital。単価はその額。売れる数と値段は App が持つ。
     * チケットのリセールで、元の人が払った額で売る時）
     *
     * @param  bool  $storedPayment  預けたカードで請求する注文（抽選の当選・リセールの抽選の当選。ADR 0080）。払われていない注文の片付けが取り消さない
     * @param  string|null  $heldBy  売り方を押さえた App の名前（place と同じ）
     * @param  int|null  $unitAmount  在庫を押さえない注文の単価（1 円以上。null ならいつもの注文: 種類の値段で在庫を押さえる）
     */
    public function placeDigital(Listing $listing, ListingVariant $variant, int $quantity, string $buyerName, CustomerAccount $account, string $orderKey, string $actor, ?AgeAnswer $age = null, bool $storedPayment = false, ?string $heldBy = null, ?int $unitAmount = null): Transaction
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $orderKey) !== 1) {
            throw new OrderException('画面の有効期限が切れました。ページを再読み込みして、もう一度お試しください。', changed: true);
        }

        $buyerName = trim($buyerName);

        if ($quantity < 1 || $quantity > 99 || $variant->listing_id !== $listing->id || $buyerName === '' || mb_strlen($buyerName) > 60 || ($unitAmount !== null && $unitAmount < 1)) {
            throw new OrderException(self::UNAVAILABLE);
        }

        $keyHash = hash('sha256', $orderKey);

        try {
            return DB::transaction(function () use ($listing, $variant, $quantity, $buyerName, $account, $keyHash, $actor, $age, $storedPayment, $heldBy, $unitAmount): Transaction {
                $this->events->lock();
                $existing = CheckoutOrder::query()->where('key_hash', $keyHash)->first();

                if ($existing !== null) {
                    return Transaction::query()->findOrFail($existing->transaction_id);
                }

                self::admitDemo($account, $actor);
                self::refuseHeld($listing->id, $heldBy);
                self::refuseOverLimit($listing->id, $quantity, $actor);

                $listing = Listing::query()->with('seller')->lockForUpdate()->findOrFail($listing->id);
                $variant = ListingVariant::query()->whereKey($variant->id)->where('listing_id', $listing->id)->firstOrFail();

                if ($listing->status !== ListingStatus::Active || $variant->price($listing) === null || $this->hiddenFrom($listing, $account, $actor)) {
                    throw new OrderException(self::UNAVAILABLE);
                }

                $ageRequired = $this->checkAge($listing->id, $age, $account->id);

                $customer = Party::create(['kind' => PartyKind::Customer, 'name' => $buyerName, 'email' => $account->email]);
                // 在庫を押さえない注文は、拠点の無い明細を 1 つ（単価は渡された額。名前・品番は種類から）
                [$drafts, $locations] = $unitAmount === null
                    ? self::allocate($listing, $variant, $quantity, $listing->taxRateBp())
                    : [[self::unstocked(LineDraft::fromVariant($listing, $variant, $quantity, $listing->taxRateBp()), $unitAmount)], []];
                $subtotal = array_sum(array_map(static fn (LineDraft $d): int => $d->toArray()['amount'], $drafts));
                $transaction = $this->transactions->open('sale', $listing->seller, $customer, $drafts, actor: $actor, customerAccount: $account);

                if ((int) $transaction->total_amount < 1) {
                    throw new OrderException(self::UNAVAILABLE);
                }

                $this->ageChecks->record($transaction->id, $ageRequired, $age, $account->id, $actor);

                $byLocation = [];
                foreach ($transaction->lines()->where('line_type', LineType::Item)->orderBy('id')->get() as $line) {
                    $byLocation[(string) $line->location_id][(string) $line->id] = (int) $line->quantity;
                }
                foreach ($byLocation as $locationId => $items) {
                    $this->fulfillments->create($transaction, FulfillmentMethod::Digital, $items, $locations[$locationId] ?? null, null, $actor);
                }

                CheckoutOrder::create(['key_hash' => $keyHash, 'transaction_id' => $transaction->id, 'pays_with' => $storedPayment ? 'stored' : 'checkout']);
                $this->points->recordOrder($transaction->id, $account->id, 0, $subtotal, [], $actor);

                return $transaction;
            });
        } catch (InsufficientStockException) {
            throw new OrderException(self::UNAVAILABLE);
        }
    }

    /**
     * 年齢の確かめ（ADR 0077）を錠の中で照らす。答えが無ければ会員が前に確かめた年齢だけで決める。足りなければ OrderException
     *
     * @return int 品の年齢（要らなければ 0）
     */
    private function checkAge(string $listingId, ?AgeAnswer $age, string $accountId): int
    {
        try {
            $required = $this->ageChecks->required([$listingId]);
            $this->ageChecks->guard($required, $age, $accountId, false, answerRequired: false);

            return $required;
        } catch (AgeCheckRefused $e) {
            throw new OrderException($e->getMessage());
        }
    }

    /**
     * デモの店の来訪者ごとの注文の数（ADR 0086）。ショップのお客さんの申し込みだけ数える（連鎖のロックの中、二重送信の確かめの後で呼ぶ）
     */
    private static function admitDemo(CustomerAccount $account, string $actor): void
    {
        if ($actor === 'storefront' && ! DemoOrders::admit((string) $account->email)) {
            throw new OrderException(DemoOrders::REFUSED);
        }
    }

    /**
     * App が売り方を押さえた商品は、押さえた App からでなければ断る（ADR 0080。連鎖のロックの中で呼ぶ。押さえの始まり・終わりも同じロックの中で書かれる）
     */
    private static function refuseHeld(string $listingId, ?string $heldBy): void
    {
        $hold = app(SaleHolds::class)->for($listingId);

        if ($hold !== null && $hold['plugin'] !== $heldBy) {
            throw new OrderException($hold['message']);
        }
    }

    /**
     * 商品ごとの 1 回のご注文の上限（App の決まり。ADR 0109）。ショップのお客さんの申し込み（actor が storefront）だけ確かめる
     * （定期便の 2 回目からなど仕組みが作る注文は、申し込みの時の約束なので断らない）。決まりを読めなければ断る。
     * 商品の合計の下限は、カートを通る注文だけの決まり（ここでは見ない）
     */
    private static function refuseOverLimit(string $listingId, int $quantity, string $actor): void
    {
        if ($actor !== 'storefront') {
            return;
        }

        $limits = app(PurchaseLimits::class)->perOrder([$listingId]);

        if ($limits === null) {
            throw new OrderException(CartService::LIMITS_UNREADABLE);
        }

        if (isset($limits[$listingId]) && $quantity > $limits[$listingId]) {
            throw new OrderException("この商品は 1 回のご注文で {$limits[$listingId]} 点までです。数量を減らしてください。");
        }
    }

    /**
     * 拠点の無い（在庫を押さえない）明細にして、単価を入れ替えた写し（名前・種類・品番・税率はそのまま）
     */
    private static function unstocked(LineDraft $draft, int $unitAmount): LineDraft
    {
        return new LineDraft($draft->lineType, $draft->description, $draft->quantity, $unitAmount, $draft->taxRateBp, $draft->kind,
            $draft->listingId, null, null, $draft->variantId, $draft->sku);
    }

    /**
     * 数を在庫のある拠点から順に割り当てる
     *
     * @return array{0: list<LineDraft>, 1: array<string, Location>}
     */
    private static function allocate(Listing $listing, ListingVariant $variant, int $quantity, int $taxRate): array
    {
        $components = $quantity > 0 ? app(StockComponents::class)->of($listing->id) : [];

        if ($components !== []) {
            return self::allocateFromComponents($listing, $variant, $quantity, $taxRate, $components);
        }

        $drafts = [];
        $locations = [];
        $remaining = $quantity;

        foreach (InventoryStock::query()->where('variant_id', $variant->id)->whereRaw('quantity - reserved > 0')->orderBy('location_id')->get() as $stock) {
            $take = min($remaining, $stock->available());
            $location = Location::query()->findOrFail($stock->location_id);
            $drafts[] = LineDraft::fromVariant($listing, $variant, $take, $taxRate, $location);
            $locations[$location->id] = $location;
            $remaining -= $take;

            if ($remaining === 0) {
                break;
            }
        }

        if ($remaining > 0) {
            throw new InsufficientStockException('在庫が足りません');
        }

        return [$drafts, $locations];
    }

    /**
     * 自分の在庫を持たない商品（セット商品）の数を、構成する品から作れる拠点に割り当てる。
     *
     * 商品そのものの在庫の行は見ない（店の人が意味のない在庫を入れなくてよい）。
     * **拠点ごとに「構成する品から作れる数」を数える**ので、構成する品が揃っていない拠点は選ばれない。
     * 実際に在庫を押さえるのは行ごとの LineStock で、ここで決めた拠点の構成する品から引く
     *
     * @param  array<string, int>  $components  種類の ID => 1 つあたりの数
     * @return array{0: list<LineDraft>, 1: array<string, Location>}
     */
    private static function allocateFromComponents(Listing $listing, ListingVariant $variant, int $quantity, int $taxRate, array $components): array
    {
        $sets = app(ComponentStock::class)->buildable($components);

        $drafts = [];
        $locations = [];
        $remaining = $quantity;

        foreach ($sets as $locationId => $buildable) {
            $take = min($remaining, $buildable);
            $location = Location::query()->findOrFail($locationId);
            $drafts[] = LineDraft::fromVariant($listing, $variant, $take, $taxRate, $location);
            $locations[$location->id] = $location;
            $remaining -= $take;

            if ($remaining === 0) {
                break;
            }
        }

        if ($remaining > 0) {
            throw new InsufficientStockException('在庫が足りません');
        }

        return [$drafts, $locations];
    }

    /**
     * ショップのお客さんの申し込みで、その会員に見せない商品か（ADR 0079。会員だけ・ランクだけの商品）
     */
    private function hiddenFrom(Listing $listing, CustomerAccount $account, string $actor): bool
    {
        return $actor === PlaceOrder::ACTOR && app(MemberBenefits::class)->hides($listing->id, $account->member_since === null ? null : $account->id);
    }

    /**
     * @param  array<string, string|null>  $address
     * @return array<string, string|null>
     */
    private static function address(array $address): array
    {
        return ['zip' => $address['postal'], 'prefecture' => $address['prefecture'], 'city' => $address['city'], 'line1' => $address['line1'], 'line2' => $address['line2'] ?? null];
    }

    /**
     * @return array<string, string|null>
     */
    private static function shipTo(CheckoutInput $input): array
    {
        $to = $input->shipTo();

        return ['name' => $to['name'], 'phone' => $to['phone'], ...self::address($to)];
    }
}
