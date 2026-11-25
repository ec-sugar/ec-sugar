<?php

declare(strict_types=1);

namespace EcSugar\PluginGiftCards;

use App\Core\Event\EventStore;
use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginGiftCards\Mail\GiftCardMail;
use EcSugar\PluginGiftCards\Models\GiftCardOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 払いが確かになったギフトカードの注文に、カードを発行してコードをメールで届ける（ADR 0076）。
 *
 * - 払われた知らせ（PaymentConfirmed。確定の書き込みの後に届く）から。同じ知らせが 2 回来ても、核が売った注文ごとに 1 枚だけ発行する（一意の索引も守る）。
 *   払われずに消えた注文・取り消した注文・取消の返金が途中か止めた注文には出さない（錠の中で確かめる）
 * - テストのモードで払われた注文（支払いの試みの test_mode）には、テストの印を付けて発行する（本番の鍵の店では使えない）
 * - 発行と同じトランザクションで、デジタルの受渡を渡し終えて注文を完了にする（核の SaleOrderService::deliverDigital。発送・完了と同じ守りを通る。
 *   発送の知らせのメールは送らない。コードのメールが渡すもの）
 * - コードは発行の答えでだけ分かる。メール（買った人と、贈る相手）に載せるのはここだけで、記録・ログ・画面・キューには出さない（キューを通さず、確定の後に直に送る）
 * - 届けた印は宛先ごと。買った人に送れなかったら印（mailed_at）を付けず、1 時間ごとの gift-cards:issue が、まだ使われていないカードのコードを出し直して送り直す
 *   （3 回まで。届かなかったコードは誰も知らないので、出し直しても困る人はいない）。贈る相手に送れなかった時は出し直さない（買った人がコードを持っている）。
 *   発行を落とした注文も同じ見回りが拾う
 */
final class GiftCardIssuer
{
    public const string ACTOR = 'system:gift-cards';

    /** メールが届かなかったカードを送り直すまでの間（その前は払われた知らせの処理に任せる） */
    public const int RETRY_AFTER_MINUTES = 15;

    public function __construct(
        private readonly EventStore $events,
        private readonly GiftCardService $giftCards,
        private readonly SaleOrderService $orders,
        private readonly PaymentRefunds $refunds,
    ) {}

    /**
     * 払われた注文がギフトカードの注文なら発行して届ける。発行したら true
     */
    public function paid(string $transactionId): bool
    {
        $order = GiftCardOrder::query()->find($transactionId);

        if ($order === null) {
            return false;
        }

        $issued = DB::transaction(function () use ($order): ?array {
            $this->events->lock();
            $transaction = Transaction::query()->whereKey($order->transaction_id)->lockForUpdate()->firstOrFail();

            // 払われた注文だけ（払う前・取り消した注文には出さない）。取消の返金が途中・止めた注文にも出さない（取消が済めば、お金を返したので出さない）
            if ($transaction->status === TransactionStatus::Cancelled || $transaction->status === TransactionStatus::Refunded
                || in_array($transaction->phase, [Phase::Quote, Phase::Agreement, Phase::Cancel], true)
                || $this->refunds->activeCancel($transaction->id) !== null) {
                return null;
            }

            // テストのモードは支払いの試みの test_mode 列で見分ける（決済の形ごとの番号の書き方を見ない。ADR 0096）
            $test = PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('status', 'paid')->where('test_mode', true)->exists();
            $issued = $this->giftCards->issue($order->amount, $order->valid_days, $transaction->id, self::ACTOR, $test);

            if ($transaction->phase === Phase::Payment) {
                $this->orders->deliverDigital($transaction, self::ACTOR);
            }

            return $issued;
        });

        if ($issued === null || $issued['code'] === null) {
            return false;
        }

        $this->mail($issued['card'], $issued['code'], $order);

        return true;
    }

    /**
     * 見回り（1 時間ごと）: 払われたのに発行していない注文を発行し、コードのメールが届いていない・まだ使われていないカードのコードを出し直して送り直す
     *
     * @return array{issued: int, resent: int}
     */
    public function catchUp(CarbonImmutable $now): array
    {
        $issued = 0;
        $resent = 0;

        $missing = GiftCardOrder::query()
            ->whereNotIn('transaction_id', GiftCard::query()->whereNotNull('purchase_transaction_id')->select('purchase_transaction_id'))
            ->whereIn('transaction_id', Transaction::query()->select('id')->whereIn('status', [TransactionStatus::Open, TransactionStatus::Completed])
                ->whereNotIn('phase', [Phase::Quote, Phase::Agreement, Phase::Cancel]))
            ->limit(200)->pluck('transaction_id');

        foreach ($missing as $transactionId) {
            $issued += $this->paid((string) $transactionId) ? 1 : 0;
        }

        $undelivered = GiftCard::query()->whereNull('mailed_at')->whereNotNull('purchase_transaction_id')->where('reissues', '<', GiftCardService::MAX_REISSUES)
            ->where('updated_at', '<', $now->subMinutes(self::RETRY_AFTER_MINUTES))->limit(200)->get();

        foreach ($undelivered as $card) {
            $order = GiftCardOrder::query()->find((string) $card->purchase_transaction_id);
            $code = $order === null ? null : $this->giftCards->reissueUndelivered($card, self::ACTOR);

            if ($order !== null && $code !== null) {
                $resent += $this->mail(GiftCard::query()->findOrFail($card->id), $code, $order) ? 1 : 0;
            }
        }

        return ['issued' => $issued, 'resent' => $resent];
    }

    /**
     * コードを買った人と贈る相手にメールで届ける（キューを通さず直に。コードを保存しないため）。宛先ごとに、送れたら届けた印を付ける。
     * 買った人に送れなければ false（見回りが出し直す）。贈る相手に送れなくても出し直さない（買った人がコードを持っている）
     */
    private function mail(GiftCard $card, string $code, GiftCardOrder $order): bool
    {
        $tenant = tenant();
        $transaction = Transaction::query()->find($order->transaction_id);
        $buyer = $transaction === null ? null : Party::query()->find($transaction->customer_id);
        $buyerEmail = $buyer?->email;

        if (! $tenant instanceof Tenant || ! is_string($buyerEmail) || filter_var($buyerEmail, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('ギフトカードのコードを送る宛先が無い', ['card' => $card->id]);

            return false;
        }

        $facts = [
            'code' => GiftCardService::format($code),
            'amount' => $card->amount,
            'expiresAt' => $card->expires_at->timezone('Asia/Tokyo')->format('Y/m/d'),
            'shopUrl' => (string) StorefrontUrls::of($tenant, '/'),
            'buyerName' => (string) $buyer->name,
            'message' => $order->message,
            'gift' => $order->recipient_email !== null,
        ];
        $shopName = (string) $tenant->getAttribute('name');

        try {
            Mail::to($buyerEmail)->send(new GiftCardMail('buyer', $shopName, $facts));
        } catch (Throwable $e) {
            // 例外の文にコードが入らないように、種類だけを残す
            Log::warning('ギフトカードのコードのメールを買った人に送れなかった（見回りが出し直して送る）', ['card' => $card->id, 'error' => $e::class]);

            return false;
        }

        $this->giftCards->markMailed($card->id, 'buyer', self::ACTOR);

        if ($order->recipient_email !== null && filter_var($order->recipient_email, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                Mail::to($order->recipient_email)->send(new GiftCardMail('recipient', $shopName, $facts));
                $this->giftCards->markMailed($card->id, 'recipient', self::ACTOR);
            } catch (Throwable $e) {
                Log::warning('ギフトカードのコードのメールを贈る相手に送れなかった（買った人には届いている）', ['card' => $card->id, 'error' => $e::class]);
            }
        }

        return true;
    }
}
