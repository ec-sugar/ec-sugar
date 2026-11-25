<?php

declare(strict_types=1);

namespace EcSugar\PluginGiftCards;

use App\Core\Crypto\BlindIndex;
use App\Core\Crypto\TenantCipher;
use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Listing\Enums\SellerStatus;
use App\Core\Listing\Models\Seller;
use App\Core\Money\Money;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Access\ClientSource;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Checkout\OrderException;
use Carbon\CarbonImmutable;
use EcSugar\PluginGiftCards\Models\GiftCardOrder;
use Illuminate\Support\Facades\DB;

/**
 * ギフトカードを売る注文を作る（ADR 0076）。カートを通さない 1 品の注文（ADR 0050 と同じ考え方）:
 *
 * - 同じ注文の合い言葉（二重送信）なら最初の注文を返す（連鎖のロックの中で）
 * - 額面は店の今の設定の選択肢から（お客さんの送った額をそのまま信じない）。明細は 1 行（ギフトカード ¥5,000）で、消費税は掛けない
 *   （前払式支払手段の発行は不課税。消費税はギフトカードで品を買った時に、その品に掛かる）
 * - 送料なし・受渡はデジタル（払いが確かになったらコードをメールで届ける）。支払いはカードだけ（核が、デジタルの受渡だけの注文に後から払う手段を出さない）
 * - 会員でもゲストでも買える。お客さんは買い手のメールの台帳につなぐ（会員なら会員の台帳）。名前・メールは暗号化した列へ、イベントに個人情報を入れない
 * - 贈る相手のメールとメッセージは App の表に暗号化して（コードを届ける時に使う）
 * - 同じメール（ブラインドインデックス）・同じ接続元から 24 時間に買える額の合計に上限（既定 100,000 円。錠の中で数える。取り消した・払われずに消えた注文は数えない）。
 *   盗んだカードでの買い込みと、贈る相手へのメールを踏み台にする量を抑える
 * - ショップの注文の印（CheckoutOrder）を付ける（払われないまま期限が過ぎた注文の片付け・ご注文の確認のメールが今の注文と同じに扱う）
 */
final class GiftCardPurchases
{
    public const string ACTOR = 'storefront';

    public const int MESSAGE_MAX = 200;

    public function __construct(
        private readonly EventStore $events,
        private readonly TransactionService $transactions,
        private readonly FulfillmentService $fulfillments,
        private readonly CustomerAccounts $accounts,
        private readonly GiftCardSettings $settings,
    ) {}

    public function place(int $amount, string $name, string $email, ?string $recipientEmail, ?string $message, ?CustomerAccount $member, string $orderKey, ?string $ip = null): Transaction
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $orderKey) !== 1) {
            throw new OrderException('画面の有効期限が切れました。もう一度お試しください。', changed: true);
        }

        if (! in_array($amount, $this->settings->denominations(), true)) {
            throw new OrderException('選べる額面が変わりました。もう一度お選びください。', changed: true);
        }

        $keyHash = hash('sha256', $orderKey);
        $validDays = $this->settings->validDays();
        $buyerIndex = BlindIndex::of('email', $email);
        $sourceIndex = app(TenantCipher::class)->index('gift_cards_source:'.ClientSource::of($ip));
        $limit = $this->settings->dailyLimit();

        return DB::transaction(function () use ($amount, $name, $email, $recipientEmail, $message, $member, $keyHash, $validDays, $buyerIndex, $sourceIndex, $limit): Transaction {
            $this->events->lock();
            $existing = CheckoutOrder::query()->where('key_hash', $keyHash)->first();

            if ($existing !== null) {
                return Transaction::query()->findOrFail($existing->transaction_id);
            }

            // 1 日の上限（錠の中で数えるので、同時に送っても超えない）
            foreach (['buyer_index' => $buyerIndex, 'source_index' => $sourceIndex] as $column => $index) {
                if (self::boughtToday($column, $index) + $amount > $limit) {
                    throw new OrderException('ギフトカードを 1 日にお求めいただけるのは '.Money::format($limit).' までです。日を改めてお求めください。');
                }
            }

            $seller = Seller::query()->where('status', SellerStatus::Active)->orderBy('created_at')->orderBy('id')->first()
                ?? throw new OrderException('今はギフトカードをお求めいただけません。');
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => $name, 'email' => $email]);
            $account = $member ?? $this->accounts->forEmail($email, self::ACTOR);
            $line = new LineDraft(LineType::Item, 'ギフトカード '.Money::format($amount), 1, $amount, 0, 'gift_card');
            $transaction = $this->transactions->open('sale', $seller, $customer, [$line], actor: self::ACTOR, customerAccount: $account);
            $lineId = (string) $transaction->lines()->where('line_type', LineType::Item)->value('id');
            $this->fulfillments->create($transaction, FulfillmentMethod::Digital, [$lineId => 1], null, null, self::ACTOR);

            GiftCardOrder::create([
                'transaction_id' => $transaction->id, 'amount' => $amount, 'valid_days' => $validDays,
                'recipient_email' => $recipientEmail, 'message' => $message, 'buyer_index' => $buyerIndex, 'source_index' => $sourceIndex,
            ]);
            CheckoutOrder::create(['key_hash' => $keyHash, 'transaction_id' => $transaction->id, 'pays_with' => 'checkout']);
            // 記録には額と日数だけ（贈る相手・メッセージは入れない）
            $this->events->append('gift_cards.ordered', 'transaction', $transaction->id, ['amount' => $amount, 'valid_days' => $validDays, 'gift' => $recipientEmail !== null], self::ACTOR);

            return $transaction;
        });
    }

    /**
     * 24 時間に同じメール・同じ接続元で注文した額の合計（取り消した・払われずに消えた注文は数えない）
     */
    private static function boughtToday(string $column, string $index): int
    {
        return (int) GiftCardOrder::query()->where($column, $index)->where('created_at', '>', CarbonImmutable::now()->subDay())
            ->whereNotIn('transaction_id', Transaction::query()->select('id')->whereIn('status', [TransactionStatus::Cancelled, TransactionStatus::Refunded]))
            ->sum('amount');
    }
}
