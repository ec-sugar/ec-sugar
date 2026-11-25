<?php

declare(strict_types=1);

namespace App\Core\GiftCard\Services;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\EventStore;
use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Models\GiftCardEntry;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Enums\LedgerAccount;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Models\LedgerPosting;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * ギフトカード（商品券。ADR 0076）の核。残高の帳簿（gift_card_entries）に書くのはここだけ。1 円 = 1 円。
 *
 * - 発行（issue）: 売った注文の払いが確かになった時（App が呼ぶ。1 つの注文に 1 枚）。コードは 16 文字（見分けにくい文字を除く）で、
 *   ここで作って呼ぶ側に 1 回だけ返す（メールで届けるため）。DB には店の鍵の HMAC と末尾 4 文字だけ。記録・イベント・ログに出さない。
 *   テストの支払いで売ったカードには印（test）を付け、本番の鍵の店では使わせない
 * - 使う（spend）: 注文を作るトランザクションの中、台帳のロックの後で、カードの行を錠で取り、止めていない・期限の前・残高が足りるかを確かめてから引く
 * - 受け取りの記録（recordPayment）: 注文の払いが確かになった時（決済代行の残りが払われた・全額をギフトカードで払った）に、台帳に
 *   「ギフトカードでの受け取り」（支払いの仕訳。direct・参照 gift_card:<カード>）を書く。店の負債（未使用の残高）が減る
 * - 戻す（release）: 理由ごとに 1 回。cancel は払われずに消えた注文・取消（自動）、shipping は贈り物の送料の差額で現金とポイントで返せなかった残り（自動）、
 *   refund は外でした返金の後に店の人が選んだ額（自動では戻さない）。戻す合計は使った額まで（DB も守る）。台帳に受け取りを書いていれば、残っている受け取りまで返金の仕訳も。
 *   期限は延ばさず、期限を過ぎていれば戻した分はすぐ期限切れ
 * - 止める・再開（盗難の申し出）・手で足し引き（お金の段階。理由は暗号化）・期限で消す（毎日 gift-cards:expire）
 *
 * どの書き込みも EventStore::lock() を先に取り、同じトランザクションでイベントを残す（payload は額・残高・注文の ID だけ。コードも末尾も入れない）。
 * 残高が負にならないことは DB のトリガーも守る
 */
final class GiftCardService
{
    /** コードに使う文字（見分けにくい 0・O・1・I・L を除いた 31 文字） */
    public const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const int CODE_LENGTH = 16;

    /** 1 枚の額面と手の足し引きの上限（円） */
    public const int MAX_AMOUNT = 1_000_000;

    /** 期限の上限（発行から 3 年） */
    public const int MAX_VALID_DAYS = 1095;

    public const int REASON_MAX = 200;

    /** 台帳の支払い・返金の仕訳の参照（ギフトカードでの受け取り） */
    public const string REFERENCE = 'gift_card:';

    public const string NOT_FOUND = 'ギフトカードのコードが見つかりません。もう一度ご確認ください。';

    public const string STOPPED = 'このギフトカードは、現在お使いいただけません（ショップにお問い合わせください）。';

    public const string EXPIRED = 'このギフトカードは有効期限が過ぎています。';

    public const string EMPTY = 'このギフトカードの残高はありません。';

    public const string TEST_ONLY = 'このギフトカードはテスト用に発行されたものなので、お使いいただけません。';

    /** 届かなかったコードを出し直す回数の上限 */
    public const int MAX_REISSUES = 3;

    public const array RELEASE_CAUSES = ['cancel', 'shipping', 'refund'];

    private const array EVENT = ['issue' => 'issued', 'spend' => 'spent', 'release' => 'released', 'expire' => 'expired', 'adjust' => 'adjusted'];

    public function __construct(
        private readonly EventStore $events,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * 入れられた文字を、コードの形（16 文字の大文字）にそろえる。空白・ハイフンは除き、小文字は大文字に。形が違えば null
     */
    public static function normalize(string $input): ?string
    {
        if (strlen($input) > 64) {
            return null;
        }

        $code = strtoupper((string) preg_replace('/[\s\-‐－ー]+/u', '', $input));

        return preg_match('/\A[2-9A-HJKMNP-Z]{16}\z/', $code) === 1 ? $code : null;
    }

    /**
     * 見せる形（「ABCD-EFGH-JKMN-PQRS」）
     */
    public static function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    /**
     * コードの店の鍵の HMAC（DB の索引。鍵を持たない者は DB の中身からコードを総当たりで確かめられない）
     */
    public static function hash(string $code): string
    {
        return app(TenantCipher::class)->index('gift_card_code:'.$code);
    }

    /**
     * 入れられたコードのカード（形が違う・無ければ null）。ほかの店のカードは店の DB に無いので見つからない
     */
    public function find(string $input): ?GiftCard
    {
        $code = self::normalize($input);

        return $code === null ? null : GiftCard::query()->where('code_hash', self::hash($code))->first();
    }

    public function balance(string $cardId): int
    {
        return (int) ($this->latest($cardId)->balance_after ?? 0);
    }

    /**
     * カードの様子（active 使える・stopped 止めた・test_only テストのカードで本番の鍵の店・expired 期限切れ・empty 残高 0）
     *
     * @param  bool  $liveShop  店の決済代行が本番の鍵か（呼ぶ側が決める。テストのカードを使わせない）
     */
    public function state(GiftCard $card, ?CarbonImmutable $now = null, bool $liveShop = false): string
    {
        $now ??= CarbonImmutable::now();

        return match (true) {
            $card->stopped_at !== null => 'stopped',
            $card->test && $liveShop => 'test_only',
            $card->expired($now) => 'expired',
            $this->balance($card->id) <= 0 => 'empty',
            default => 'active',
        };
    }

    /**
     * 使えないなら、その理由（お客さんに見せる文。使えるなら null）
     */
    public function problem(GiftCard $card, ?CarbonImmutable $now = null, bool $liveShop = false): ?string
    {
        return match ($this->state($card, $now, $liveShop)) {
            'stopped' => self::STOPPED,
            'test_only' => self::TEST_ONLY,
            'expired' => self::EXPIRED,
            'empty' => self::EMPTY,
            default => null,
        };
    }

    /**
     * 発行する（呼ぶ側の DB トランザクションの中でもよい）。売った注文ごとに 1 枚（もう発行していれば、そのカードとコード null を返す。
     * コードは届けた時にしか分からないので、二度目には返さない）
     *
     * @return array{card: GiftCard, code: string|null}
     */
    public function issue(int $amount, int $validDays, ?string $purchaseTransactionId, ?string $actor, bool $test = false): array
    {
        if ($amount < 1 || $amount > self::MAX_AMOUNT || $validDays < 1 || $validDays > self::MAX_VALID_DAYS) {
            throw new LogicException('額面と期限の日数が範囲の外');
        }

        return DB::transaction(function () use ($amount, $validDays, $purchaseTransactionId, $actor, $test): array {
            $this->events->lock();

            if ($purchaseTransactionId !== null) {
                $existing = GiftCard::query()->where('purchase_transaction_id', $purchaseTransactionId)->first();

                if ($existing !== null) {
                    return ['card' => $existing, 'code' => null];
                }
            }

            $now = CarbonImmutable::now();
            [$card, $code] = $this->createWithFreshCode(fn (string $hash, string $last4): GiftCard => GiftCard::create([
                'code_hash' => $hash, 'last4' => $last4, 'amount' => $amount, 'purchase_transaction_id' => $purchaseTransactionId,
                'expires_at' => $now->addDays($validDays)->startOfSecond(), 'test' => $test,
            ]));

            $this->append($card, 'issue', $amount, null, $actor, extra: ['expires_at' => $card->expires_at->toIso8601String(), 'purchase' => $purchaseTransactionId, 'test' => $test ? true : null]);

            return ['card' => $card, 'code' => $code];
        });
    }

    /**
     * コードを出し直す（届ける前のカードだけ: 買った人へのメールが送れなかった。まだ一度も使われていない。3 回まで）。新しいコードを 1 回だけ返す。古いコードは使えなくなる
     */
    public function reissueUndelivered(GiftCard $card, ?string $actor): ?string
    {
        return DB::transaction(function () use ($card, $actor): ?string {
            $this->events->lock();
            $locked = GiftCard::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

            if ($locked->mailed_at !== null || $locked->reissues >= self::MAX_REISSUES || GiftCardEntry::query()->where('gift_card_id', $locked->id)->where('kind', 'spend')->exists()) {
                return null;
            }

            [, $code] = $this->createWithFreshCode(static function (string $hash, string $last4) use ($locked): GiftCard {
                $locked->forceFill(['code_hash' => $hash, 'last4' => $last4, 'reissues' => $locked->reissues + 1])->save();

                return $locked;
            });
            $this->events->append('gift_card.code_reissued', 'gift_card', $locked->id, [], $actor);

            return $code;
        });
    }

    /**
     * コードを届けた（メールを送れた）印。宛先ごと（buyer 買った人・recipient 贈る相手）。出し直すかは買った人の印で決める
     *
     * @param  'buyer'|'recipient'  $to
     */
    public function markMailed(string $cardId, string $to, ?string $actor): void
    {
        $column = $to === 'recipient' ? 'recipient_mailed_at' : 'mailed_at';

        DB::transaction(function () use ($cardId, $column, $to, $actor): void {
            $this->events->lock();
            $updated = GiftCard::query()->whereKey($cardId)->whereNull($column)->update([$column => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

            if ($updated > 0) {
                $this->events->append('gift_card.mailed', 'gift_card', $cardId, ['to' => $to], $actor);
            }
        });
    }

    /**
     * 注文に使う（注文を作るトランザクションの中、台帳のロックの後で呼ぶ）。カードの行を錠で取り、止めていない・期限の前・残高が足りるかを
     * その錠の中で確かめてから引き、注文に使った額を写す。足りなければ GiftCardException（確かめの画面の後にほかの注文で使われた・止められた）
     */
    public function spend(string $transactionId, string $cardId, int $amount, ?string $actor, bool $liveShop = false): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ギフトカードは注文を作るトランザクションの中で使う');
        }

        if ($amount < 1) {
            throw new LogicException('使う額は 1 円から');
        }

        $this->events->lock();
        $card = GiftCard::query()->whereKey($cardId)->lockForUpdate()->first() ?? throw new GiftCardException(self::NOT_FOUND);
        $problem = $this->problem($card, null, $liveShop);

        if ($problem !== null) {
            throw new GiftCardException($problem);
        }

        $balance = $this->balance($card->id);

        if ($amount > $balance) {
            throw new GiftCardException('ギフトカードの残高（'.self::yen($balance).'）が、お使いになる額より少なくなりました。');
        }

        OrderGiftCard::create(['transaction_id' => $transactionId, 'gift_card_id' => $card->id, 'amount' => $amount]);
        $this->append($card, 'spend', -$amount, $transactionId, $actor);
    }

    /**
     * 注文の払いが確かになった時に、ギフトカードで払った分を台帳に「ギフトカードでの受け取り」として書く（呼ぶ側が台帳のロックを持つ。支払いの段へ進める前に）。
     * 使っていない注文・もう書いた注文は何もしない
     */
    public function recordPayment(Transaction $transaction, ?string $actor): void
    {
        $order = OrderGiftCard::query()->find($transaction->id);

        if ($order === null || $this->journalExists($transaction->id, JournalType::Payment, $order->gift_card_id)) {
            return;
        }

        $this->ledger->recordPayment($transaction, $order->amount, PaymentRoute::Direct, self::REFERENCE.$order->gift_card_id, $actor);
        $this->events->append('gift_card.payment_recorded', 'transaction', $transaction->id, ['amount' => $order->amount], $actor);
    }

    /**
     * 注文に使った分をカードへ戻す（呼ぶ側の DB トランザクションの中でもよい）。理由ごとに 1 回（cancel: 払われずに消えた注文・取消、shipping: 贈り物の送料の差額、
     * refund: 外でした返金の後に店の人が選んだ額）。額を渡さなければ、まだ戻していない残り全部。戻す合計は使った額まで（DB も断る）。
     * 台帳に受け取りを書いていれば、残っている受け取りまで返金の仕訳も書く。期限は延ばさない（期限を過ぎたカードに戻した分は、すぐ期限切れ）。
     * 使っていない・もう全部戻した・その理由でもう戻した注文は何もしない
     *
     * @return int 戻した額
     */
    public function release(string $transactionId, ?string $actor, string $cause = 'cancel', ?int $amount = null): int
    {
        if (! in_array($cause, self::RELEASE_CAUSES, true)) {
            throw new LogicException('戻す理由が違う');
        }

        return DB::transaction(function () use ($transactionId, $actor, $cause, $amount): int {
            $this->events->lock();
            $order = OrderGiftCard::query()->find($transactionId);

            if ($order === null || GiftCardEntry::query()->where('transaction_id', $transactionId)->where('kind', 'release')->where('cause', $cause)->exists()) {
                return 0;
            }

            $card = GiftCard::query()->whereKey($order->gift_card_id)->lockForUpdate()->firstOrFail();
            $back = min($amount ?? PHP_INT_MAX, $this->releasable($transactionId));

            if ($back < 1) {
                return 0;
            }

            $toLedger = min($back, $this->heldInLedger($transactionId));

            if ($toLedger > 0) {
                $this->ledger->recordRefund(Transaction::query()->findOrFail($transactionId), $toLedger, PaymentRoute::Direct, self::REFERENCE.$card->id, $actor);
            }

            $this->append($card, 'release', $back, $transactionId, $actor, cause: $cause);

            if ($card->expired(CarbonImmutable::now())) {
                $this->append($card, 'expire', -$this->balance($card->id), null, $actor);
            }

            return $back;
        });
    }

    /**
     * 注文に使った額のうち、まだカードへ戻していない額
     */
    public function releasable(string $transactionId): int
    {
        return -(int) GiftCardEntry::query()->where('transaction_id', $transactionId)->whereIn('kind', ['spend', 'release'])->sum('amount');
    }

    /**
     * 台帳に残っている「ギフトカードでの受け取り」（受け取り − 返した分。返金の照らし合わせで、決済代行で受け取った額を出すため）
     */
    public function heldInLedger(string $transactionId): int
    {
        return (int) LedgerPosting::query()
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
            ->where('ledger_postings.transaction_id', $transactionId)
            ->whereIn('ledger_journals.type', [JournalType::Payment->value, JournalType::Refund->value])
            ->where('ledger_journals.reference', 'like', 'gift\_card:%')
            ->where('ledger_postings.account', LedgerAccount::Customer->value)
            ->sum('ledger_postings.amount');
    }

    /**
     * 止める（盗難の申し出など）。止めたカードは使えない（残高はそのまま。再開できる）
     */
    /**
     * @param  'staff'|'refund'  $reason  店の人が止めた・売った注文が返金された（ホームのやることに出る）
     */
    public function stop(GiftCard $card, ?string $actor, string $reason = 'staff'): GiftCard
    {
        return $this->setStopped($card, true, $actor, $reason);
    }

    public function resume(GiftCard $card, ?string $actor): GiftCard
    {
        return $this->setStopped($card, false, $actor);
    }

    /**
     * 店の人の手で足す・引く（お金の段階。経路が直前の再確認を確かめる）。理由（200 文字まで）は暗号化して帳簿に残し、イベントには額だけ。
     * 残高より多くは引けない。期限の過ぎたカードには足せない
     */
    public function adjust(GiftCard $card, int $amount, string $reason, ?string $actor): GiftCardEntry
    {
        $reason = trim(str_replace(["\r\n", "\r"], "\n", $reason));

        if ($amount === 0 || abs($amount) > self::MAX_AMOUNT) {
            throw new GiftCardException('調整する額は 1〜'.number_format(self::MAX_AMOUNT).' 円で入力してください。');
        }

        if ($reason === '' || ! mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > self::REASON_MAX || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/u', $reason) === 1) {
            throw new GiftCardException('理由は '.self::REASON_MAX.' 文字以内で入力してください。');
        }

        return DB::transaction(function () use ($card, $amount, $reason, $actor): GiftCardEntry {
            $this->events->lock();
            $locked = GiftCard::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
            $balance = $this->balance($locked->id);

            if ($amount > 0 && $locked->expired(CarbonImmutable::now())) {
                throw new GiftCardException('有効期限切れのカードには加算できません。');
            }

            if ($balance + $amount < 0) {
                throw new GiftCardException('残高（'.self::yen($balance).'）より多くは減らせません。');
            }

            if ($balance + $amount > self::MAX_AMOUNT) {
                throw new GiftCardException('残高は '.self::yen(self::MAX_AMOUNT).' までです。');
            }

            return $this->append($locked, 'adjust', $amount, null, $actor, note: $reason);
        });
    }

    /**
     * 期限を過ぎて残高のあるカードの残高を消す（毎日。gift-cards:expire）。カードごとに錠の中で確かめ直すので、同時に 2 つ走っても 1 回だけ
     *
     * @return int 消したカードの数
     */
    public function expireDue(CarbonImmutable $now, ?string $actor): int
    {
        $count = 0;
        $due = DB::table(DB::raw('(SELECT DISTINCT ON (gift_card_id) gift_card_id, balance_after FROM gift_card_entries ORDER BY gift_card_id, card_seq DESC) AS latest'))
            ->join('gift_cards', 'gift_cards.id', '=', 'latest.gift_card_id')
            ->where('latest.balance_after', '>', 0)->where('gift_cards.expires_at', '<=', $now)
            ->orderBy('latest.gift_card_id')->limit(10000)->pluck('latest.gift_card_id');

        foreach ($due as $cardId) {
            $count += DB::transaction(function () use ($cardId, $now, $actor): int {
                $this->events->lock();
                $card = GiftCard::query()->whereKey((string) $cardId)->lockForUpdate()->firstOrFail();
                $balance = $this->balance($card->id);

                if ($balance <= 0 || ! $card->expired($now)) {
                    return 0;
                }

                $this->append($card, 'expire', -$balance, null, $actor);

                return 1;
            });
        }

        return $count;
    }

    /**
     * まだ使われていない残高の合計（店の負債。期限の前のカード。止めたカードも数える）と、残高のあるカードの数
     *
     * @return array{amount: int, cards: int}
     */
    public function outstanding(?CarbonImmutable $now = null): array
    {
        $row = DB::table(DB::raw('(SELECT DISTINCT ON (gift_card_id) gift_card_id, balance_after FROM gift_card_entries ORDER BY gift_card_id, card_seq DESC) AS latest'))
            ->join('gift_cards', 'gift_cards.id', '=', 'latest.gift_card_id')
            ->where('latest.balance_after', '>', 0)->where('gift_cards.expires_at', '>', $now ?? CarbonImmutable::now())
            ->selectRaw('COALESCE(SUM(latest.balance_after), 0) AS amount, COUNT(*) AS cards')->first();

        return ['amount' => (int) ($row->amount ?? 0), 'cards' => (int) ($row->cards ?? 0)];
    }

    /**
     * 売った注文が返金されて自動で止めた、残高のある期限の前のカードの数（ホームのやること。店の人が残高を 0 にするか再開すると消える）
     */
    public function stoppedByRefund(?CarbonImmutable $now = null): int
    {
        return DB::table(DB::raw('(SELECT DISTINCT ON (gift_card_id) gift_card_id, balance_after FROM gift_card_entries ORDER BY gift_card_id, card_seq DESC) AS latest'))
            ->join('gift_cards', 'gift_cards.id', '=', 'latest.gift_card_id')
            ->where('gift_cards.stopped_reason', 'refund')->where('latest.balance_after', '>', 0)->where('gift_cards.expires_at', '>', $now ?? CarbonImmutable::now())
            ->count();
    }

    /**
     * 注文に使ったギフトカード（使っていなければ null）。末尾 4 文字・使った額・使った後の残高（その時の帳簿の行）・カードへ戻した額
     *
     * @return array{last4: string, amount: int, remaining: int, released: int}|null
     */
    public function forOrder(string $transactionId): ?array
    {
        $order = OrderGiftCard::query()->find($transactionId);

        if ($order === null) {
            return null;
        }

        $card = GiftCard::query()->findOrFail($order->gift_card_id);
        $entries = GiftCardEntry::query()->where('transaction_id', $transactionId)->get(['kind', 'amount', 'balance_after']);

        return [
            'last4' => $card->last4,
            'amount' => $order->amount,
            'remaining' => (int) ($entries->firstWhere('kind', 'spend')->balance_after ?? 0),
            'released' => (int) $entries->where('kind', 'release')->sum('amount'),
        ];
    }

    /**
     * 帳簿の行（新しい順）
     *
     * @return Collection<int, GiftCardEntry>
     */
    public function history(string $cardId, int $limit = 50): Collection
    {
        return GiftCardEntry::query()->where('gift_card_id', $cardId)->orderByDesc('card_seq')->limit($limit)->get();
    }

    private function setStopped(GiftCard $card, bool $stop, ?string $actor, string $reason = 'staff'): GiftCard
    {
        return DB::transaction(function () use ($card, $stop, $actor, $reason): GiftCard {
            $this->events->lock();
            $locked = GiftCard::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

            if (($locked->stopped_at !== null) === $stop) {
                return $locked;
            }

            $locked->forceFill(['stopped_at' => $stop ? CarbonImmutable::now() : null, 'stopped_reason' => $stop ? $reason : null])->save();
            $this->events->append($stop ? 'gift_card.stopped' : 'gift_card.resumed', 'gift_card', $locked->id, ['balance' => $this->balance($locked->id)] + ($stop ? ['reason' => $reason] : []), $actor);

            return $locked;
        });
    }

    private function journalExists(string $transactionId, JournalType $type, string $cardId): bool
    {
        return LedgerJournal::query()->where('transaction_id', $transactionId)->where('type', $type)->where('reference', self::REFERENCE.$cardId)->whereNull('reverses_journal_id')->exists();
    }

    /**
     * 新しいコードを作って保存する（ハッシュが重なったら作り直す。31 文字 × 16 で約 79 ビットなので、ほぼ起きない）
     *
     * @param  \Closure(string, string): GiftCard  $save  ハッシュと末尾 4 文字で保存する
     * @return array{0: GiftCard, 1: string}
     */
    private function createWithFreshCode(\Closure $save): array
    {
        for ($try = 1; ; $try++) {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            try {
                // 重なりは同じトランザクションの中で断られるので、その 1 文だけを巻き戻せるようにする
                return DB::transaction(static fn (): array => [$save(self::hash($code), substr($code, -4)), $code]);
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505' || $try >= 3) {
                    throw $e;
                }
            }
        }
    }

    private function latest(string $cardId): ?GiftCardEntry
    {
        return GiftCardEntry::query()->where('gift_card_id', $cardId)->orderByDesc('card_seq')->first();
    }

    private static function yen(int $amount): string
    {
        return '¥'.number_format($amount);
    }

    /**
     * 帳簿に 1 行足し、同じトランザクションでイベントを残す（呼ぶ側が連鎖のロックとカードの行の錠を持つ）。残高は DB のトリガーが入れる
     *
     * @param  array<string, mixed>  $extra
     */
    private function append(GiftCard $card, string $kind, int $amount, ?string $transactionId, ?string $actor, ?string $note = null, array $extra = [], ?string $cause = null): GiftCardEntry
    {
        if ($kind === 'expire' && $amount === 0) {
            throw new LogicException('消す残高が無い');
        }

        $entry = GiftCardEntry::create([
            'gift_card_id' => $card->id, 'kind' => $kind, 'amount' => $amount, 'transaction_id' => $transactionId, 'cause' => $cause,
            'note' => $note, 'actor' => $actor, 'occurred_at' => CarbonImmutable::now(),
        ]);
        $entry->refresh();

        $this->events->append('gift_card.'.self::EVENT[$kind], 'gift_card', $card->id, array_filter([
            'amount' => $amount,
            'balance' => $entry->balance_after,
            'transaction' => $transactionId,
            'cause' => $cause,
            ...$extra,
        ], static fn (mixed $v): bool => $v !== null), $actor);

        return $entry;
    }
}
