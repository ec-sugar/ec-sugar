<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Admin\Services\AuditLog;
use App\Core\Crypto\TenantCipher;
use App\Core\Payment\DirectMethod;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\RateLimit\Throttle;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Storefront\Access\ClientSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Normalizer;
use Throwable;

/**
 * まだ払われていない注文の回数の上限（ADR 0085）。注文の確定の数え方を 1 か所にまとめる。
 *
 * - 接続元の 1 時間の上限（hitSource）: 注文の確定の試みを、接続元（ClientSource。IPv6 は /64 と、2 段目の /48）ごとにキャッシュで数える。
 *   先に足してから比べる（同時に送っても上限を超えない。確定できなかった試みも数える。で直した形）。
 *   数えるのは核の Throttle（ADR 0081。先に足してから比べる・足し算そのものも原子的）。キーは Throttle::shopKey（店の ID が必ず入る）
 * - メールの 1 時間の上限（emailProblem）: 確定できた注文だけを DB で数える（試みで数えると、他人のメールの枠を注文を作らずに使い切って締め出せる）。
 *   注文の確定の錠（連鎖のロック）の中で呼ぶので、同時の確定でも上限を超えない
 * - 入金待ちの数の上限（pendingProblem）: 同じメール・同じ接続元が同時に持てる入金待ち（銀行振込・発送の前の代引き・コンビニ払いの払込票）の数。
 *   支払いの選び方（PaymentChoices）が注文の入力・確かめの画面・確定（錠の中）の 3 か所で同じ決め方で使う
 * - 断った試みは店ごと・決まりごとに 1 時間の数を数え、1・10・100… 回目に中央の監査ログへ回数だけを残す（接続元・メール・注文は入れない）。
 *   錠の中からは呼ばない（確定の経路がロールバックの後に呼ぶ）
 *
 * 平文のメールと接続元はキーにしない（どちらも店の鍵の HMAC。キャッシュにも表にも平文を置かない）。
 * メールは数えるときだけの正規化（countingEmail。全角・IDN・+ 以降・Gmail のドット）をしてから印にする
 */
final class UnpaidOrderLimits
{
    /** 0 円の注文の、同じ接続元・同じメールからの 1 時間あたりの上限（変えない） */
    public const int FREE_PER_HOUR = 3;

    /** 払う額のある注文の確定の、同じ接続元からの 1 時間あたりの上限（家族・会社・携帯の回線で分け合う分と、店の人が試しに続けて買う分を見込む） */
    public const int ORDERS_PER_SOURCE_HOUR = 20;

    /** 払う額のある注文の確定の、同じメールからの 1 時間あたりの上限（確定できた注文で数える） */
    public const int ORDERS_PER_EMAIL_HOUR = 5;

    /** 同じメールが同時に持てる入金待ちの数 */
    public const int PENDING_PER_EMAIL = 3;

    /** 同じ接続元が同時に持てる入金待ちの数（回線を分け合う分を見込んでメールより多く） */
    public const int PENDING_PER_SOURCE = 5;

    /** IPv6 の 2 段目（/48）の上限は、1 段目（/64）の何倍か（1 時間 60 回・入金待ち 15・0 円の注文 9） */
    public const int WIDE_FACTOR = 3;

    public const int WINDOW_SECONDS = 3600;

    public const string FREE_TOO_MANY = '同じメールアドレスまたは同じ回線から、無料の品のご注文が続いています。しばらく時間をおいてからお試しください。';

    public const string TOO_MANY = '同じメールアドレスまたは同じ回線から、ご注文が続いています。しばらく時間をおいてからお試しください。';

    /** 回数を数える置き場（キャッシュ）に届かない時。数えられないまま通さない */
    public const string UNAVAILABLE = 'ただいまご注文を受け付けられません。しばらく時間をおいてからお試しください。';

    /** 入金待ちの数の上限。メールと接続元のどちらで越えたかは言い分けない（他人のメールを入れた人に、そのメールの注文の数・番号を見せない） */
    public const string PENDING = '同じメールアドレスまたは同じ回線から、お支払いがお済みでないご注文が続いています。先のご注文のお支払いが済むまで、銀行振込・代金引換はお選びいただけません（お支払い方法と期限は、ご注文時のメールでお知らせしています）。';

    /** 断った試みの決まりの名前（監査ログの rule） */
    public const string RULE_FREE = 'free_hourly';

    public const string RULE_ORDERS = 'orders_hourly';

    public const string RULE_PENDING = 'pending';

    public const string RULE_UNAVAILABLE = 'counter_unavailable';

    /** 数えるときにローカル部のドットを落とすドメイン（ドットの違いが同じ受け箱に届く） */
    private const array DOTLESS_DOMAINS = ['gmail.com', 'googlemail.com'];

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * 接続元からの注文の確定の試みを 1 回数える（先に足してから比べる）。上限を超えたら断る理由、超えなければ null。
     * 数える置き場に届かなければ、通さずに決まった断りの文（500 にしない）
     *
     * @param  bool  $free  合計 0 円の注文（0 円の注文の上限で数える）
     */
    public function hitSource(?string $ip, bool $free): ?string
    {
        $bucket = $free ? 'free' : 'orders';
        $limit = $free ? self::FREE_PER_HOUR : self::ORDERS_PER_SOURCE_HOUR;
        $keys = [[$this->key($bucket.'|source|'.$this->sourceIndex($ip)), $limit]];
        $wide = $this->wideSourceIndex($ip);

        if ($wide !== null) {
            $keys[] = [$this->key($bucket.'|wide|'.$wide), $limit * self::WIDE_FACTOR];
        }

        $over = false;

        try {
            // どれも数える（片方で断っても、もう片方の数も進める。今までの 0 円の注文と同じ）
            foreach ($keys as [$key, $max]) {
                $over = ! Throttle::allow($key, $max, self::WINDOW_SECONDS) || $over;
            }
        } catch (Throwable $e) {
            Log::warning('注文の回数を数える保存先に届かないので、確定を断った', ['error' => $e::class]);

            return self::UNAVAILABLE;
        }

        if (! $over) {
            return null;
        }

        $this->refused($free ? self::RULE_FREE : self::RULE_ORDERS);

        return $free ? self::FREE_TOO_MANY : self::TOO_MANY;
    }

    /**
     * 同じメールで過去 1 時間に確定できた注文が上限に達していれば、断る理由（確定の錠の中で呼ぶ）。0 円の注文と払う額のある注文は別に数える
     */
    public function emailProblem(string $email, bool $free): ?string
    {
        $count = CheckoutOrder::query()
            ->join('transactions', 'transactions.id', '=', 'checkout_orders.transaction_id')
            ->where('checkout_orders.email_mark', $this->emailMark($email))
            ->where('checkout_orders.created_at', '>', CarbonImmutable::now()->subSeconds(self::WINDOW_SECONDS))
            ->where('transactions.total_amount', $free ? '=' : '<>', 0)
            ->count();

        if ($count < ($free ? self::FREE_PER_HOUR : self::ORDERS_PER_EMAIL_HOUR)) {
            return null;
        }

        return $free ? self::FREE_TOO_MANY : self::TOO_MANY;
    }

    /**
     * 同じメール・同じ接続元の入金待ちが上限に達していれば、断る理由（PENDING）。達していなければ null。
     * 注文の確定の錠（連鎖のロック）の中で呼べば、同時の確定でも順に数える
     *
     * @param  ?string  $email  買い手のメール（まだ分からなければ null。接続元だけで数える）
     */
    public function pendingProblem(?string $email, ?string $ip = null): ?string
    {
        if ($email !== null && trim($email) !== '' && $this->pending()->where('checkout_orders.email_mark', $this->emailMark($email))->count() >= self::PENDING_PER_EMAIL) {
            return self::PENDING;
        }

        $ip ??= request()->ip();

        if ($this->pending()->where('checkout_orders.source_index', $this->sourceIndex($ip))->count() >= self::PENDING_PER_SOURCE) {
            return self::PENDING;
        }

        $wide = $this->wideSourceIndex($ip);

        return $wide !== null && $this->pending()->where('checkout_orders.source_wide_index', $wide)->count() >= self::PENDING_PER_SOURCE * self::WIDE_FACTOR
            ? self::PENDING
            : null;
    }

    /**
     * 注文の印（checkout_orders に入れる。入金待ちとメールの回数を数えるためだけ。markDays の後に見回りが消す）
     *
     * @return array{source_index: string, source_wide_index: string|null, email_mark: string}
     */
    public function marks(string $email, ?string $ip = null): array
    {
        $ip ??= request()->ip();

        return ['source_index' => $this->sourceIndex($ip), 'source_wide_index' => $this->wideSourceIndex($ip), 'email_mark' => $this->emailMark($email)];
    }

    /**
     * 接続元の印（店の鍵の HMAC。IPv6 は /64 にまとめる）
     */
    public function sourceIndex(?string $ip = null): string
    {
        return $this->index('source|'.ClientSource::of($ip ?? request()->ip()));
    }

    /**
     * 2 段目の接続元の印（IPv6 の /48。IPv4 は null）
     */
    public function wideSourceIndex(?string $ip = null): ?string
    {
        $wide = ClientSource::wide($ip ?? request()->ip());

        return $wide === null ? null : $this->index('wide|'.$wide);
    }

    /**
     * メールの印（数えるときだけの正規化の後の、店の鍵の HMAC）。保存するブラインドインデックス（email_index）とは別
     */
    public function emailMark(string $email): string
    {
        return $this->index('email|'.self::countingEmail($email));
    }

    /**
     * 数えるときだけのメールの正規化: NFKC → ドメインを IDN の ASCII の形に（Symfony Mailer の IdnAddressEncoder と同じ指定）→ 小文字 →
     * ローカル部の「+」より後を落とす。gmail.com・googlemail.com はローカル部のドットも落とし、googlemail.com を gmail.com にそろえる。
     * ASCII の形にできないドメインはその形のまま（小文字にして）数える。保存するメールとブラインドインデックスは変えない
     */
    public static function countingEmail(string $email): string
    {
        $value = Normalizer::normalize(trim($email), Normalizer::FORM_KC);
        $value = $value === false ? trim($email) : trim($value);
        $at = strrpos($value, '@');

        if ($at === false) {
            return mb_strtolower($value);
        }

        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);
        $ascii = preg_match('/[^\x00-\x7F]/', $domain) === 1
            ? idn_to_ascii($domain, IDNA_DEFAULT | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
            : $domain;
        $domain = mb_strtolower($ascii === false ? $domain : $ascii);
        $local = mb_strtolower($local);
        $plus = strpos($local, '+');

        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        if (in_array($domain, self::DOTLESS_DOMAINS, true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    /**
     * 注文の印を持ち、入金待ちとして数える日数の秒数。払える期限のいちばん長いもの（銀行振込 14 日・コンビニ払い 30 日）＋ 1 日（期限は注文の日の N 日後の 23:59）＋ 猶予の時間
     */
    public static function markSeconds(): int
    {
        return (max(DirectMethodSettings::MAX_BANK_DAYS, PaymentMethodSettings::MAX_KONBINI_DAYS) + 1) * 86_400 + (int) config('payment.awaiting_grace_hours') * 3600;
    }

    /**
     * 断った試みを数え、店ごと・決まりごとの 1 時間で 1・10・100… 回目に監査ログへ回数だけを残す（記録で監査ログを埋めさせない）。
     * 数える置き場・記録に失敗しても、断ることは変えない。確定の錠の中からは呼ばない（中央の監査ログの錠を、店の連鎖のロックを握ったまま待たない）
     */
    public function refused(string $rule): void
    {
        try {
            $count = Throttle::hit($this->key('refused|'.$rule), self::WINDOW_SECONDS);
        } catch (Throwable) {
            $count = 1;
        }

        if ($count !== 1 && ! self::isPowerOfTen($count)) {
            return;
        }

        try {
            $this->audit->record('storefront.orders_limited', null, ['rule' => $rule, 'refused' => $count, 'window_minutes' => intdiv(self::WINDOW_SECONDS, 60)], (string) tenant()?->getTenantKey());
        } catch (Throwable $e) {
            Log::warning('注文の回数の上限で断った記録を監査ログに残せなかった', ['rule' => $rule, 'error' => $e::class]);
        }
    }

    /**
     * 数える入金待ち（ショップの注文で、印を持つ日数のうちに作ったもの。注文が開いていて、支払いの記録が入金待ちのまま）:
     * - 銀行振込: 払う期限と猶予を過ぎていない（期限切れの見回りの前でも外す。猶予は DirectPayments::lapseAt と同じ）
     * - 代金引換: 発送の前（受渡がどれも発送の前）だけ。発送した後は荷物が動いているので数えない
     * - コンビニ払いなど決済代行の入金待ち（払込票を出した注文。ADR 0058）: 払う期限と猶予を過ぎていない
     *
     * @return Builder<PaymentAttempt>
     */
    private function pending(): Builder
    {
        $now = CarbonImmutable::now();
        $lapsed = $now->subHours((int) config('payment.awaiting_grace_hours'));

        return PaymentAttempt::query()
            ->join('transactions', 'transactions.id', '=', 'payment_attempts.transaction_id')
            ->join('checkout_orders', 'checkout_orders.transaction_id', '=', 'transactions.id')
            ->where('payment_attempts.status', 'awaiting')
            ->where('transactions.status', TransactionStatus::Open)
            ->where('checkout_orders.created_at', '>=', $now->subSeconds(self::markSeconds()))
            ->where(static fn (Builder $q) => $q
                ->where(static fn (Builder $bank) => $bank->where('payment_attempts.kind', 'direct')->where('payment_attempts.method', DirectMethod::BankTransfer->value)
                    ->where('payment_attempts.pay_by', '>', $lapsed))
                ->orWhere(static fn (Builder $cod) => $cod->where('payment_attempts.kind', 'direct')->where('payment_attempts.method', DirectMethod::Cod->value)
                    ->whereNotExists(static fn ($f) => $f->selectRaw('1')->from('fulfillments')->whereColumn('fulfillments.transaction_id', 'transactions.id')
                        ->whereNotIn('fulfillments.status', ['pending', 'cancelled'])))
                ->orWhere(static fn (Builder $later) => $later->where('payment_attempts.kind', 'checkout')
                    ->where(static fn (Builder $due) => $due->whereNull('payment_attempts.pay_by')->orWhere('payment_attempts.pay_by', '>', $lapsed))));
    }

    private function index(string $value): string
    {
        return app(TenantCipher::class)->index('unpaid-order|'.$value);
    }

    /**
     * 数えるキー（unpaid-order|店の ID|…）。店の ID は核の Throttle::shopKey が必ず入れる（キャッシュを店で分けていない置き場でも、ほかの店の数と混ざらない）
     */
    private function key(string $rest): string
    {
        return Throttle::shopKey('unpaid-order', $rest);
    }

    private static function isPowerOfTen(int $n): bool
    {
        for ($p = 10; $p <= $n; $p *= 10) {
            if ($p === $n) {
                return true;
            }
        }

        return false;
    }
}
