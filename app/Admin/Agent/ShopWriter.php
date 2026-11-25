<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Admin\Http\Requests\CouponRequest;
use App\Admin\Http\Requests\ListingRules;
use App\Admin\Services\SalesPeriod;
use App\Core\Discount\Enums\CouponKind;
use App\Core\Discount\Models\Coupon;
use App\Core\Discount\Services\CouponService;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Enums\AdjustmentReason;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InsufficientStockException;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\StaleListingException;
use App\Core\Order\ReturnRequest;
use App\Core\Order\Services\OrderReturns;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Enums\ReturnReason;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Models\Transaction;
use App\Core\UserFacingException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * AI エージェントの書く道具の中身（ADR 0101）。MCP の道具（皮）と承認の一覧（AgentProposals）が同じものを使う。
 *
 * - plan(): 引数を確かめ、今の店の値を読んで中身（WritePlan: 形をそろえた引数・照らす値・人が読む要約）を作る。書き込まない
 * - apply(): 中身を反映する。管理画面と同じ核のサービス（ListingService・InventoryOperations・SaleOrderService・OrderReturns・CouponService）を通す
 *   （連鎖のロック・同じトランザクションのイベント・同時の編集の断り・在庫と台帳の決まりは、そのサービスが守る）
 * - 断るときは ToolInputException（エージェントと管理画面に見せてよい文だけ）。核の「見せてよい文」の例外はその文で、ほかの例外の文は出さない
 *
 * 店の文脈の中だけで作る（どの店かは鍵が決める）。段階・鍵の種類・承認の要否の決まりは呼ぶ側（AgentWriteTool・AgentProposals）が TOOLS を読んで守る
 */
final class ShopWriter
{
    /**
     * 書く道具: 要る段階（管理画面の同じ操作の経路の段階。テストが照らす）・店の設定にかかわらず承認が要るか・決済代行に頼むか・
     * 外の人に届く値を書くか（public。ショップに出る商品の名前と説明、お客さんの発送のメールと注文の画面に出る送り状の番号。
     * お客さんの情報を渡す店では承認が要る。仕込まれた指示で個人情報を外の人に見える所へ書かせる道を塞ぐ）
     *
     * @var array<string, array{level: string, approval: bool, external: bool, public: bool}>
     */
    public const array TOOLS = [
        'create_product' => ['level' => 'operate', 'approval' => false, 'external' => false, 'public' => true],
        'update_product' => ['level' => 'operate', 'approval' => false, 'external' => false, 'public' => true],
        'adjust_stock' => ['level' => 'operate', 'approval' => false, 'external' => false, 'public' => false],
        'mark_shipped' => ['level' => 'operate', 'approval' => false, 'external' => false, 'public' => true],
        'cancel_order' => ['level' => 'money', 'approval' => true, 'external' => true, 'public' => false],
        'refund_order' => ['level' => 'money', 'approval' => true, 'external' => true, 'public' => false],
        'create_coupon' => ['level' => 'operate', 'approval' => true, 'external' => false, 'public' => false],
    ];

    public const int MAX_STOCK = 1_000_000;

    /** 要約に出す新しい説明の頭の長さ */
    public const int DESCRIPTION_HEAD = 300;

    /** 決まりで断られた時の文（核の InvalidArgumentException の文は外へ返さない。読む道具と同じ） */
    public const string REFUSED = 'この変更は店の決まりで断られました。値を見直してください。';

    public function __construct(
        private readonly ListingService $listings,
        private readonly InventoryOperations $inventory,
        private readonly SaleOrderService $orders,
        private readonly OrderReturns $returns,
        private readonly CouponService $coupons,
    ) {
        if (! tenancy()->initialized) {
            throw new LogicException('AI エージェントの道具は店の文脈の中だけで使う');
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function plan(string $tool, array $arguments): WritePlan
    {
        return self::refusing(fn (): WritePlan => match ($tool) {
            'create_product' => $this->planCreateProduct($arguments),
            'update_product' => $this->planUpdateProduct($arguments),
            'adjust_stock' => $this->planAdjustStock($arguments),
            'mark_shipped' => $this->planMarkShipped($arguments),
            'cancel_order' => $this->planCancel($arguments),
            'refund_order' => $this->planRefund($arguments),
            'create_coupon' => $this->planCoupon($arguments),
            default => throw new ToolInputException('Unknown tool.'),
        });
    }

    /**
     * 反映して、結果（作った ID・新しい状態など。個人情報を入れない）を返す
     *
     * @return array<string, mixed>
     */
    public function apply(WritePlan $plan, string $actor): array
    {
        return self::refusing(fn (): array => match ($plan->tool) {
            'create_product' => $this->applyCreateProduct($plan, $actor),
            'update_product' => $this->applyUpdateProduct($plan, $actor),
            'adjust_stock' => $this->applyAdjustStock($plan, $actor),
            'mark_shipped' => $this->applyMarkShipped($plan, $actor),
            'cancel_order' => $this->applyCancel($plan, $actor),
            'refund_order' => $this->applyRefund($plan, $actor),
            'create_coupon' => $this->applyCoupon($plan, $actor),
            default => throw new ToolInputException('Unknown tool.'),
        });
    }

    // ---- create_product ----

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function planCreateProduct(array $arguments): WritePlan
    {
        $data = self::validate($arguments, [
            'title' => ['required', 'string', 'max:200', 'not_regex:/\A\s*\z/', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'price' => ['required', 'integer', 'min:0', 'max:'.ListingRules::MAX_AMOUNT],
            'description' => ['nullable', 'string', 'max:5000'],
            'tax_class' => ['nullable', 'string', Rule::enum(TaxClass::class)],
        ]);
        $seller = Seller::query()->orderBy('created_at')->orderBy('id')->first() ?? throw new ToolInputException('This shop has no seller to list products under.');
        $tax = TaxClass::from((string) ($data['tax_class'] ?? TaxClass::Standard->value));
        $title = trim((string) $data['title']);

        return $this->plan_('create_product', [
            'title' => $title,
            'price' => (int) $data['price'],
            'description' => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            'tax_class' => $tax->value,
        ], ['seller' => $seller->id], "商品「{$title}」を下書きで登録する（".self::yen((int) $data['price'])."・税 {$tax->label()}）");
    }

    /**
     * @return array<string, mixed>
     */
    private function applyCreateProduct(WritePlan $plan, string $actor): array
    {
        $args = $plan->arguments;
        $seller = Seller::query()->find((string) $plan->basis['seller']) ?? throw new ToolInputException('The seller was removed.');
        $listing = $this->listings->create($seller, 'product', ListingRules::toChanges([
            'title' => $args['title'],
            'unit_amount' => $args['price'],
            'description' => $args['description'],
            'tax_class' => $args['tax_class'],
            'status' => ListingStatus::Draft->value,
        ]), $actor);

        return ['product_id' => $listing->id, 'status' => $listing->status->value];
    }

    // ---- update_product ----

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function planUpdateProduct(array $arguments): WritePlan
    {
        $data = self::validate($arguments, [
            'id' => ['required', 'string', 'ulid'],
            'version' => ['nullable', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:200', 'not_regex:/\A\s*\z/', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'price' => ['sometimes', 'integer', 'min:0', 'max:'.ListingRules::MAX_AMOUNT],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'tax_class' => ['sometimes', 'string', Rule::enum(TaxClass::class)],
            'status' => ['sometimes', 'string', Rule::in([ListingStatus::Active->value, ListingStatus::Draft->value])],
        ]);
        $listing = Listing::query()->find(strtolower((string) $data['id'])) ?? throw new ToolInputException('Product not found.');

        if (isset($data['version']) && (int) $data['version'] !== $listing->version) {
            throw new ToolInputException("The product was changed after you read it (now version {$listing->version}). Read it again with get_product.");
        }

        $changes = [];
        $parts = [];

        if (array_key_exists('title', $data) && trim((string) $data['title']) !== $listing->title) {
            $changes['title'] = trim((string) $data['title']);
            $parts[] = "名前を「{$listing->title}」→「{$changes['title']}」";
        }

        // 今の値段の半分より下げる変更は、すぐ反映の店でも承認（注入で値段を壊されても、大きな値下げは人が見る）
        $steep = false;

        if (array_key_exists('price', $data) && (int) $data['price'] !== $listing->unit_amount) {
            $changes['price'] = (int) $data['price'];
            $steep = $listing->unit_amount !== null && $listing->unit_amount > 0 && $listing->unit_amount > $changes['price'] * 2;
            $parts[] = '値段を '.($listing->unit_amount === null ? '（なし）' : self::yen($listing->unit_amount)).' → '.self::yen($changes['price']);
        }

        if (array_key_exists('description', $data) && ($data['description'] ?? '') !== ($listing->description ?? '')) {
            $changes['description'] = $data['description'] === null || $data['description'] === '' ? null : (string) $data['description'];
            $parts[] = '説明を変更（'.mb_strlen((string) $changes['description']).' 文字。新しい説明の冒頭: 「'.self::head((string) $changes['description'], self::DESCRIPTION_HEAD).'」）';
        }

        if (array_key_exists('tax_class', $data) && $data['tax_class'] !== $listing->tax_class->value) {
            $changes['tax_class'] = (string) $data['tax_class'];
            $parts[] = "税を {$listing->tax_class->label()} → ".TaxClass::from($changes['tax_class'])->label();
        }

        if (array_key_exists('status', $data) && $data['status'] !== $listing->status->value) {
            $changes['status'] = (string) $data['status'];
            $parts[] = '状態を '.self::listingStatus($listing->status->value).' → '.self::listingStatus($changes['status']);
        }

        if ($changes === []) {
            throw new ToolInputException('Nothing would change (pass at least one of title, price, description, tax_class, status with a new value).');
        }

        return $this->plan_('update_product', ['id' => $listing->id] + $changes, ['version' => $listing->version],
            "「{$listing->title}」の".implode('、', $parts), $steep);
    }

    /**
     * @return array<string, mixed>
     */
    private function applyUpdateProduct(WritePlan $plan, string $actor): array
    {
        $args = $plan->arguments;
        $listing = Listing::query()->find((string) $args['id']) ?? throw new ToolInputException('Product not found.');
        $changes = array_filter([
            'title' => $args['title'] ?? null,
            'unit_amount' => $args['price'] ?? null,
            'tax_class' => $args['tax_class'] ?? null,
            'status' => $args['status'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        if (array_key_exists('description', $args)) {
            $changes['description'] = $args['description'];
        }

        try {
            $updated = $this->listings->update($listing, (int) $plan->basis['version'], ListingRules::toChanges($changes), $actor);
        } catch (StaleListingException) {
            throw new ToolInputException('この商品は案を作った後に変わりました。今の内容を読み直してから、もう一度依頼してください。');
        }

        return ['product_id' => $updated->id, 'version' => $updated->version];
    }

    // ---- adjust_stock ----

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function planAdjustStock(array $arguments): WritePlan
    {
        $data = self::validate($arguments, [
            'product_id' => ['required', 'string', 'ulid'],
            'variant_id' => ['nullable', 'string', 'ulid'],
            'location_id' => ['nullable', 'string', 'ulid'],
            'mode' => ['required', 'string', 'in:receive,count'],
            'quantity' => ['required', 'integer', 'min:0', 'max:'.self::MAX_STOCK],
            'reason' => ['nullable', 'string', Rule::enum(AdjustmentReason::class)],
        ]);
        $listing = Listing::query()->find(strtolower((string) $data['product_id'])) ?? throw new ToolInputException('Product not found.');
        $variant = isset($data['variant_id'])
            ? ListingVariant::query()->whereKey(strtolower((string) $data['variant_id']))->where('listing_id', $listing->id)->first()
            : $listing->soleVariant();

        if (! $variant instanceof ListingVariant) {
            throw new ToolInputException(isset($data['variant_id']) ? 'This variant is not part of the product.' : 'This product has several variants: pass variant_id (see get_product).');
        }

        $locations = Location::query()->where('seller_id', $listing->seller_id)->orderBy('name')->orderBy('id')->get();
        $location = isset($data['location_id'])
            ? $locations->firstWhere('id', strtolower((string) $data['location_id']))
            : ($locations->count() === 1 ? $locations->first() : null);

        if (! $location instanceof Location) {
            throw new ToolInputException(isset($data['location_id'])
                ? 'This stock location does not belong to the product\'s seller.'
                : 'Pass location_id. Stock locations: '.implode(', ', $locations->pluck('id')->all()).'.');
        }

        $name = $listing->title.($variant->value_1 === null ? '' : '（'.implode(' / ', array_filter([$variant->value_1, $variant->value_2, $variant->value_3])).'）');
        $quantity = (int) $data['quantity'];

        if ($data['mode'] === 'receive') {
            if ($quantity < 1) {
                throw new ToolInputException('To receive stock, quantity must be 1 or more.');
            }

            return $this->plan_('adjust_stock', ['product_id' => $listing->id, 'variant_id' => $variant->id, 'location_id' => $location->id, 'mode' => 'receive', 'quantity' => $quantity],
                ['variant' => $variant->id, 'location' => $location->id], "「{$name}」の在庫を {$quantity} 個入庫する（{$location->name}）");
        }

        $current = (int) InventoryStock::query()->where('variant_id', $variant->id)->where('location_id', $location->id)->value('quantity');

        if ($current === $quantity) {
            throw new ToolInputException("The stock is already {$quantity}.");
        }

        $reason = AdjustmentReason::from((string) ($data['reason'] ?? AdjustmentReason::Stocktake->value));

        return $this->plan_('adjust_stock', ['product_id' => $listing->id, 'variant_id' => $variant->id, 'location_id' => $location->id, 'mode' => 'count', 'quantity' => $quantity, 'reason' => $reason->value],
            ['variant' => $variant->id, 'location' => $location->id, 'quantity' => $current],
            "「{$name}」の在庫を {$current} → {$quantity} 個に調整する（{$reason->label()}。{$location->name}）");
    }

    /**
     * @return array<string, mixed>
     */
    private function applyAdjustStock(WritePlan $plan, string $actor): array
    {
        $args = $plan->arguments;
        $variant = ListingVariant::query()->findOrFail((string) $args['variant_id']);
        $location = Location::query()->findOrFail((string) $args['location_id']);

        try {
            $stock = $args['mode'] === 'receive'
                ? $this->inventory->receive($variant, $location, (int) $args['quantity'], $actor)
                : $this->inventory->adjust($variant, $location, (int) $args['quantity'] - (int) $plan->basis['quantity'], AdjustmentReason::from((string) $args['reason']), $actor);
        } catch (InsufficientStockException) {
            throw new ToolInputException('在庫が足りません。注文で押さえている数より少なくはできません。');
        }

        return ['product_id' => (string) $args['product_id'], 'variant_id' => $variant->id, 'quantity' => $stock->quantity, 'available' => $stock->available()];
    }

    // ---- mark_shipped ----

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function planMarkShipped(array $arguments): WritePlan
    {
        $data = self::validate($arguments, [
            'order_id' => ['required', 'string', 'ulid'],
            'fulfillment_id' => ['nullable', 'string', 'ulid'],
            'carrier' => ['required', 'string', 'in:'.implode(',', array_keys(SaleOrderService::CARRIERS))],
            'tracking_number' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9-]{1,64}\z/'],
        ]);
        $order = self::order((string) $data['order_id']);
        $shippable = Fulfillment::query()->where('transaction_id', $order->id)->where('status', FulfillmentStatus::Pending)
            ->whereNotIn('method', [FulfillmentMethod::InPerson, FulfillmentMethod::Digital])->orderBy('created_at')->get();
        $fulfillment = isset($data['fulfillment_id']) ? $shippable->firstWhere('id', strtolower((string) $data['fulfillment_id'])) : ($shippable->count() === 1 ? $shippable->first() : null);

        if (! $fulfillment instanceof Fulfillment) {
            throw new ToolInputException(match (true) {
                $shippable->isEmpty() => 'This order has nothing waiting to be shipped.',
                isset($data['fulfillment_id']) => 'This shipment is not waiting to be shipped in this order.',
                default => 'This order has several shipments: pass fulfillment_id ('.implode(', ', $shippable->pluck('id')->all()).').',
            });
        }

        $status = $this->orders->status($order)->value;
        $carrier = (string) $data['carrier'];
        $tracking = isset($data['tracking_number']) && $data['tracking_number'] !== '' ? (string) $data['tracking_number'] : null;

        return $this->plan_('mark_shipped', ['order_id' => $order->id, 'fulfillment_id' => $fulfillment->id, 'carrier' => $carrier, 'tracking_number' => $tracking],
            ['order' => $order->id, 'fulfillment' => $fulfillment->id, 'status' => $status],
            '注文 '.self::number($order->id).'（'.self::yen((int) $order->total_amount).'）を発送済みにする（'.SaleOrderService::CARRIERS[$carrier].'・お問い合わせ番号'.($tracking === null ? 'なし' : 'あり').'）。お客さんに発送のメールが届きます');
    }

    /**
     * @return array<string, mixed>
     */
    private function applyMarkShipped(WritePlan $plan, string $actor): array
    {
        $args = $plan->arguments;
        $order = $this->orders->ship(self::order((string) $args['order_id']), (string) $args['fulfillment_id'], (string) $args['carrier'], is_string($args['tracking_number'] ?? null) ? $args['tracking_number'] : null, $actor);

        return ['order_id' => $order->id, 'status' => $this->orders->status($order->refresh())->value];
    }

    // ---- cancel_order ----

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function planCancel(array $arguments): WritePlan
    {
        $data = self::validate($arguments, ['order_id' => ['required', 'string', 'ulid']]);
        $order = self::order((string) $data['order_id']);

        if (! $this->orders->cancellable($order)) {
            throw new ToolInputException('発送の前の注文だけ取り消せます（発送した注文は refund_order で返金します）。');
        }

        $status = $this->orders->status($order);

        return $this->plan_('cancel_order', ['order_id' => $order->id], ['status' => $status->value, 'total' => (int) $order->total_amount, 'phase' => $order->phase->value],
            '注文 '.self::number($order->id).'（'.self::yen((int) $order->total_amount)."・{$status->label()}）を取り消す。支払い済みの注文は Stripe で全額を返金し、在庫を元に戻します");
    }

    /**
     * @return array<string, mixed>
     */
    private function applyCancel(WritePlan $plan, string $actor): array
    {
        $done = $this->orders->cancel(self::order((string) $plan->arguments['order_id']), $actor);

        return ['order_id' => $done['transaction']->id, 'status' => $this->orders->status($done['transaction']->refresh())->value, 'refunded' => $done['refunded'], 'refund_pending' => $done['refund_pending']];
    }

    // ---- refund_order ----

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function planRefund(array $arguments): WritePlan
    {
        $reasons = array_map(static fn (ReturnReason $r): string => $r->value, ReturnReason::choosable());
        $data = self::validate($arguments, [
            'order_id' => ['required', 'string', 'ulid'],
            'amount' => ['nullable', 'integer', 'min:1', 'max:'.CouponRequest::MAX_AMOUNT],
            'reason' => ['nullable', 'string', Rule::in($reasons)],
        ]);
        $order = self::order((string) $data['order_id']);
        $options = $this->returns->options($order);

        if (! (bool) $options['returnable']) {
            throw new ToolInputException(is_string($options['blocked'] ?? null) ? $options['blocked'] : OrderReturns::NOT_RETURNABLE);
        }

        if (! (bool) $options['provider']) {
            throw new ToolInputException(OrderReturns::NO_PROVIDER);
        }

        $available = (int) $options['available'];
        $amount = isset($data['amount']) ? (int) $data['amount'] : $available;

        if ($amount < 1 || $amount > $available) {
            throw new ToolInputException('返金の額は 1 円から返せる残り（'.self::yen($available).'）までです。');
        }

        $reason = ReturnReason::from((string) ($data['reason'] ?? ReturnReason::Other->value));
        $preview = $this->returns->preview($order, self::refundRequest($amount, $reason));

        return $this->plan_('refund_order', ['order_id' => $order->id, 'amount' => $amount, 'reason' => $reason->value],
            ['available' => $available, 'total' => $preview->total],
            '注文 '.self::number($order->id).'（'.self::yen((int) $order->total_amount).'）に '.self::yen($amount)." を Stripe で返金する（理由: {$reason->label()}。返金できる残り ".self::yen($available).'）');
    }

    /**
     * @return array<string, mixed>
     */
    private function applyRefund(WritePlan $plan, string $actor): array
    {
        $args = $plan->arguments;
        $order = self::order((string) $args['order_id']);
        $refund = $this->returns->record($order, self::refundRequest((int) $args['amount'], ReturnReason::from((string) $args['reason'])), (int) $plan->basis['total'], $actor);

        return ['order_id' => $order->id, 'refund_id' => $refund?->id, 'refund_status' => $refund?->status, 'amount' => (int) $args['amount']];
    }

    // ---- create_coupon ----

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function planCoupon(array $arguments): WritePlan
    {
        $kind = $arguments['kind'] ?? null;
        $data = self::validate($arguments, [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:100', 'not_regex:/\A\s*\z/', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'kind' => ['required', 'string', Rule::enum(CouponKind::class)],
            'value' => [$kind === CouponKind::FreeShipping->value ? 'prohibited' : 'required', 'integer', 'min:1', 'max:'.($kind === CouponKind::Percent->value ? 100 : CouponRequest::MAX_AMOUNT)],
            'max_discount' => [$kind === CouponKind::Percent->value ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:'.CouponRequest::MAX_AMOUNT],
            'product_ids' => ['nullable', 'array', 'list', 'min:1', 'max:'.CouponRequest::MAX_TARGETS],
            'product_ids.*' => ['string', 'ulid', 'distinct'],
            'min_subtotal' => ['nullable', 'integer', 'min:1', 'max:'.CouponRequest::MAX_AMOUNT],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:'.CouponRequest::MAX_USAGE],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:'.CouponRequest::MAX_PER_CUSTOMER],
            'members_only' => ['nullable', 'boolean'],
        ]);
        $code = CouponService::normalize((string) $data['code']) ?? throw new ToolInputException('Coupon codes are 3–32 letters, digits and hyphens (no hyphen at either end or twice in a row).');

        if (Coupon::query()->where('code', $code)->exists()) {
            throw new ToolInputException('A coupon with this code already exists.');
        }

        $products = array_values(array_map(static fn (mixed $id): string => strtolower((string) $id), (array) ($data['product_ids'] ?? [])));

        if ($products !== [] && Listing::query()->whereIn('id', $products)->count() !== count($products)) {
            throw new ToolInputException('Some of product_ids were not found.');
        }

        $kind = CouponKind::from((string) $data['kind']);
        $value = $kind === CouponKind::FreeShipping ? 0 : (int) $data['value'];
        $args = [
            'code' => $code,
            'name' => trim((string) $data['name']),
            'kind' => $kind->value,
            'value' => $value,
            'max_discount' => isset($data['max_discount']) ? (int) $data['max_discount'] : null,
            // 全品は空（null）。承認の時に同じ引数で確かめ直す（空の配列は「1 つ以上」の確かめに当たる）
            'product_ids' => $products === [] ? null : $products,
            'min_subtotal' => isset($data['min_subtotal']) ? (int) $data['min_subtotal'] : null,
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'usage_limit' => isset($data['usage_limit']) ? (int) $data['usage_limit'] : null,
            'per_customer_limit' => isset($data['per_customer_limit']) ? (int) $data['per_customer_limit'] : 1,
            'members_only' => (bool) ($data['members_only'] ?? false),
        ];
        $off = match ($kind) {
            CouponKind::Percent => "{$value}% 引き".($args['max_discount'] === null ? '' : '（上限 '.self::yen($args['max_discount']).'）'),
            CouponKind::Fixed => self::yen($value).' 引き',
            CouponKind::FreeShipping => '送料無料',
        };
        $terms = array_filter([
            $products === [] ? '全品' : '商品 '.count($products).' 点',
            $args['min_subtotal'] === null ? null : self::yen($args['min_subtotal']).' 以上',
            $args['starts_on'] === null && $args['ends_on'] === null ? '期間なし' : ($args['starts_on'] ?? '').'〜'.($args['ends_on'] ?? ''),
            $args['usage_limit'] === null ? null : "全体で {$args['usage_limit']} 回まで",
            "1 人 {$args['per_customer_limit']} 回",
            $args['members_only'] ? '会員限定' : null,
        ]);

        return $this->plan_('create_coupon', $args, ['code' => $code], "クーポン「{$code}」を作る: {$off}・".implode('・', $terms)."（メモ: {$args['name']}）");
    }

    /**
     * @return array<string, mixed>
     */
    private function applyCoupon(WritePlan $plan, string $actor): array
    {
        $args = $plan->arguments;
        $zone = SalesPeriod::zone();
        $coupon = $this->coupons->create([
            'method' => Coupon::CODE,
            'code' => $args['code'],
            'name' => $args['name'],
            'kind' => $args['kind'],
            'value' => $args['value'],
            'max_discount' => $args['max_discount'],
            'target' => is_array($args['product_ids']) ? 'listings' : 'all',
            'target_ids' => is_array($args['product_ids']) ? $args['product_ids'] : [],
            'min_subtotal' => $args['min_subtotal'],
            'min_quantity' => null,
            // 日付は日本の日の始まり（終わりは次の日の始まりまで）
            'starts_at' => is_string($args['starts_on']) ? CarbonImmutable::createFromFormat('Y-m-d', $args['starts_on'], $zone)?->startOfDay()->utc() : null,
            'ends_at' => is_string($args['ends_on']) ? CarbonImmutable::createFromFormat('Y-m-d', $args['ends_on'], $zone)?->startOfDay()->addDay()->utc() : null,
            'usage_limit' => $args['usage_limit'],
            'per_customer_limit' => $args['per_customer_limit'],
            'members_only' => $args['members_only'],
        ], $actor);

        return ['coupon_id' => $coupon->id, 'code' => $coupon->code];
    }

    // ---- 共通 ----

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $basis
     */
    private function plan_(string $tool, array $arguments, array $basis, string $summary, bool $approval = false): WritePlan
    {
        $rule = self::TOOLS[$tool];

        return new WritePlan($tool, $rule['level'], $rule['approval'] || $approval, $rule['external'], $arguments, $basis, mb_substr($summary, 0, 2000));
    }

    /**
     * 自由文の頭（改行・制御の文字は空白にして、長ければ切る）。要約に入れる
     */
    private static function head(string $text, int $length): string
    {
        $flat = trim((string) preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $text));

        return mb_strlen($flat) > $length ? mb_substr($flat, 0, $length).'…' : $flat;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function validate(array $arguments, array $rules): array
    {
        $validator = Validator::make($arguments, $rules);

        if ($validator->fails()) {
            throw new ToolInputException(implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }

    private static function order(string $id): Transaction
    {
        return Transaction::query()->where('kind', 'sale')->find(strtolower($id)) ?? throw new ToolInputException('Order not found.');
    }

    private static function refundRequest(int $amount, ReturnReason $reason): ReturnRequest
    {
        return new ReturnRequest([], false, $amount, $reason, 'provider');
    }

    /**
     * 核の断り（見せてよい文の例外・入力の誤り・品番の重なり）を、見せてよい文の ToolInputException にする。
     * 決済代行に届かない・答えが分からない（PaymentUnavailableException の 409 でないもの）は AgentWriteUncertain（返金の記録が残って見回りが続きを進めることがある）。
     * InvalidArgumentException の文は外へ返さず、決まった文にする。ほかの例外は文を出さずにそのまま投げる
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private static function refusing(Closure $work): mixed
    {
        try {
            return $work();
        } catch (ToolInputException $e) {
            throw $e;
        } catch (PaymentUnavailableException $e) {
            throw $e->status() === 409 ? new ToolInputException($e->getMessage()) : new AgentWriteUncertain($e->getMessage());
        } catch (UserFacingException $e) {
            throw new ToolInputException($e->getMessage());
        } catch (ValidationException $e) {
            throw new ToolInputException(implode(' ', $e->validator->errors()->all()));
        } catch (UniqueConstraintViolationException) {
            throw new ToolInputException('同じ品番の商品がもうあります。');
        } catch (InvalidArgumentException) {
            // 核の決まりの文は内部の言い方を含みうるので、決まった文にする（読む道具と同じ。見直しの低 8）
            throw new ToolInputException(self::REFUSED);
        }
    }

    private static function yen(int $amount): string
    {
        return '¥'.number_format($amount);
    }

    private static function number(string $id): string
    {
        return strtoupper(substr($id, 0, 10));
    }

    private static function listingStatus(string $status): string
    {
        return match ($status) {
            'active' => '公開中',
            'draft' => '下書き',
            default => '販売終了',
        };
    }
}
