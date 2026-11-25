<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Order\Jobs\RefundShippingDifference;
use App\Core\Order\Services\OrderShippingCharges;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Plugin\Gift\GiftNote;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Support\Facades\DB;

/**
 * 贈り物の注文を進める（ADR 0055）。どの操作も連鎖のロック → 贈り物の行のロックの順で取り、状態を変えたらイベントを同じトランザクションで残す。
 * イベントに入れるのは ID だけ（差出人・メッセージ・token・お届け先は入れない。ADR 0010）
 *
 * - 受け取りのリンクの token は 32 バイトの乱数。引くのは sha256 で（DB の索引で引くので、比べる時間から token は分からない）。
 *   買う人に出し直すため、token そのものは店の鍵で暗号化して持つ（DB だけが漏れても、鍵が無ければ受け取れない）
 * - 受け取り（お届け先を入れる）は、受け取り待ちで、期限の前で、注文が開いていて、取消の返金が途中でない時だけ。1 回だけ
 * - 受け取りで、いちばん高い額で取った送料と受け取りの都道府県の額との差額を決め、確定の後に返金する（ADR 0064 の 6。核の OrderShippingCharges と RefundShippingDifference）
 */
final class GiftOrders
{
    public const string ACTOR = 'storefront';

    /** 送料の差額の返金（受け取りの後の仕事） */
    public const string REFUND_ACTOR = 'system:gifts';

    public const int DEFAULT_DAYS = 14;

    public function __construct(
        private readonly EventStore $events,
        private readonly FulfillmentService $fulfillments,
        private readonly PaymentRefunds $refunds,
        private readonly PluginSettings $settings,
        private readonly OrderShippingCharges $shippingCharges,
    ) {}

    /**
     * 注文の確定の中で（連鎖のロックは呼ぶ側が持つ）。差出人とメッセージを暗号化して置く
     */
    public function placed(string $transactionId, GiftNote $note, string $actor): GiftOrder
    {
        $gift = GiftOrder::create(['transaction_id' => $transactionId, 'sender' => $note->sender, 'message' => $note->message, 'status' => 'preparing']);
        $this->events->append('gift.placed', 'gift_order', $gift->id, ['transaction_id' => $transactionId], $actor);

        return $gift;
    }

    /**
     * 払いが確かになった: 受け取りのリンクを作り、期限を決める（払いの確認待ちの時だけ。何度呼ばれても 1 回）
     */
    public function paid(string $transactionId, string $actor = 'system:gifts'): ?GiftOrder
    {
        return DB::transaction(function () use ($transactionId, $actor): ?GiftOrder {
            $this->events->lock();
            $gift = GiftOrder::query()->where('transaction_id', $transactionId)->lockForUpdate()->first();
            $transaction = Transaction::query()->find($transactionId);

            if ($gift === null || $gift->status !== 'preparing' || $transaction === null || $transaction->status !== TransactionStatus::Open
                || in_array($transaction->phase, [Phase::Quote, Phase::Agreement], true)) {
                return null;
            }

            $this->issueToken($gift);
            $gift->forceFill(['status' => 'waiting', 'expires_at' => CarbonImmutable::now()->addDays($this->days())])->save();
            $this->events->append('gift.link_created', 'gift_order', $gift->id, ['transaction_id' => $transactionId], $actor);

            return $gift;
        });
    }

    /**
     * リンクを作り直す（前のリンクは使えなくなる）。受け取り待ちの時だけ
     */
    public function renew(string $giftId, string $actor): GiftOrder
    {
        return DB::transaction(function () use ($giftId, $actor): GiftOrder {
            $this->events->lock();
            $gift = GiftOrder::query()->whereKey($giftId)->lockForUpdate()->firstOrFail();

            if ($gift->status !== 'waiting') {
                throw new GiftRefused('受け取り待ちの贈り物だけ、リンクを作り直せます。');
            }

            $this->issueToken($gift);
            $gift->save();
            $this->events->append('gift.link_renewed', 'gift_order', $gift->id, ['transaction_id' => $gift->transaction_id], $actor);

            return $gift;
        });
    }

    /**
     * 受け取る人がお届け先を入れる（受渡のお届け先に。暗号化は核の受渡が行う）。token で引いた贈り物に 1 回だけ
     *
     * @param  array<string, string|null>  $address  CheckoutInput::address の形（name・phone・postal・prefecture・city・line1・line2）
     */
    public function claim(string $token, array $address): GiftOrder
    {
        return DB::transaction(function () use ($token, $address): GiftOrder {
            $this->events->lock();
            $gift = GiftOrder::query()->where('token_hash', self::hash($token))->lockForUpdate()->first() ?? throw new GiftRefused('このリンクは使えません。');
            $transaction = Transaction::query()->whereKey($gift->transaction_id)->lockForUpdate()->firstOrFail();

            if ($gift->status !== 'waiting' || $gift->expires_at === null || $gift->expires_at->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw new GiftRefused('この贈り物は、もう受け取れません。');
            }

            // 取り消しの途中（返金を頼んでいる間）は受け取らない（受け取ったのに取り消される、を作らない）
            if ($transaction->status !== TransactionStatus::Open || $this->refunds->activeCancel($transaction->id) !== null) {
                throw new GiftRefused('この贈り物は、もう受け取れません。');
            }

            $waiting = Fulfillment::query()->where('transaction_id', $transaction->id)->where('method', FulfillmentMethod::Shipping)
                ->where('status', FulfillmentStatus::Pending)->orderBy('id')->get()->filter(static fn (Fulfillment $f): bool => $f->awaitingAddress());

            if ($waiting->isEmpty()) {
                throw new GiftRefused('この贈り物は、もう受け取れません。');
            }

            $to = ['name' => $address['name'] ?? null, 'phone' => $address['phone'] ?? null, 'zip' => $address['postal'] ?? null, 'prefecture' => $address['prefecture'] ?? null,
                'city' => $address['city'] ?? null, 'line1' => $address['line1'] ?? null, 'line2' => $address['line2'] ?? null];

            foreach ($waiting as $fulfillment) {
                $this->fulfillments->setAddress($fulfillment, $to, self::ACTOR);
            }

            // 送料はいちばん高い額で取ってある（ADR 0055 の 7）。受け取りの都道府県の額との差額を決め、確定の後に返金する（ADR 0064 の 6。0 なら何もしない）
            if ($this->shippingCharges->settle($transaction->id, (string) $to['prefecture'], self::ACTOR) > 0) {
                $transactionId = $transaction->id;
                DB::afterCommit(static fn () => RefundShippingDifference::dispatch($transactionId, self::REFUND_ACTOR));
            }

            $gift->forceFill(['status' => 'claimed', 'claimed_at' => CarbonImmutable::now()])->save();
            $this->events->append('gift.claimed', 'gift_order', $gift->id, ['transaction_id' => $transaction->id], self::ACTOR);
            // 買う人へ「受け取られました」（お届け先は書かない）。書き込みが終わってから
            DB::afterCommit(static fn () => SendGiftMail::dispatch($gift->id, 'claimed'));

            return $gift;
        });
    }

    /**
     * token で引く（見るだけ。無ければ null）
     */
    public function findByToken(string $token): ?GiftOrder
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) === 1 ? GiftOrder::query()->where('token_hash', self::hash($token))->first() : null;
    }

    /**
     * 受け取りの期限の日数（店の設定。1〜60、空なら 14）
     */
    public function days(): int
    {
        $value = $this->settings->publicValues('gift')['expiry_days'] ?? null;
        $days = is_string($value) ? (int) $value : self::DEFAULT_DAYS;

        return $days >= 1 && $days <= 60 ? $days : self::DEFAULT_DAYS;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 新しい token（32 バイトの乱数を URL に使える形で 43 文字）。sha256 と、店の鍵で暗号化したものを入れる
     */
    private function issueToken(GiftOrder $gift): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $gift->forceFill(['token_hash' => self::hash($token), 'token_sealed' => $token]);
    }
}
