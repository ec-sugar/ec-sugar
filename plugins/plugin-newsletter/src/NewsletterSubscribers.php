<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

use App\Core\Crypto\BlindIndex;
use App\Core\Party\Models\CustomerAccount;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\ClientSource;
use Carbon\CarbonImmutable;
use EcSugar\PluginNewsletter\Jobs\SendConfirmMail;
use EcSugar\PluginNewsletter\Models\NewsletterDelivery;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 購読の受付・確かめ・配信の停止・退会・片付け（お客さんの操作）。購読の表を書くのはここだけ。
 *
 * 購読は「お客さんが頼んだ知らせの予約」で、取引・在庫・お金の状態ではない（conventions.md の 3 の例外。App「再入荷のお知らせ」の登録と同じ扱い）。
 * 連鎖のロックもイベントも使わず、その行のロックと一意の制約で守る。公開の受付から誰でも作れる行なので、追記だけの記録（イベント）に積まない。
 * 同意の記録（特定電子メール法 第 3 条 2 項）は行そのもの: 同意した日時（consented_at）と経路（source）、確かめた日時、止めた日時と経路。
 *
 * - ゲストは確かめのメールのリンクを押してはじめて届ける（本物のメールの持ち主だけ。ダブルオプトイン）。会員はログインで確かめたメールなので、すぐ届ける
 * - 回数の上限: メール × 接続元で 1 時間に 5 回、接続元だけで 1 時間に 20 回、メールごとに（接続元をまたいで）1 日に 10 回。
 *   ゲストは超えても答えは同じ（送らないだけ）。数えるキーには店の ID を入れ、メールは索引で数える（平文を鍵に入れない）
 * - 1 つのメールに 1 行（DB の部分の一意の索引）。店全体の購読（確かめ待ちと届ける人）は 50,000 まで
 * - 止めた人のメールは、記録を残す期間（止めてから RETAIN_DAYS 日）の後に消す（数だけが残る）
 */
final class NewsletterSubscribers
{
    public const int CONFIRM_HOURS = 24;

    public const int PAIR_HOURLY = 5;

    public const int SOURCE_HOURLY = 20;

    public const int EMAIL_DAILY = 10;

    public const int SHOP_MAX = 50_000;

    /**
     * 止めた人の同意の記録（メール）を残す日数。特定電子メール法の施行規則は「送らないこととなった日から 1 か月」を求める
     * （迷惑メール相談センターの資料。README に根拠）。それより長くは持たない
     */
    public const int RETAIN_DAYS = 31;

    /** 同意の経路 */
    public const array SOURCES = ['form', 'checkout', 'account'];

    /**
     * 受け取るを選んだ。会員（$member）はそのメールで、すぐ届ける。ゲストは確かめのメールを送る（答えはいつも Sent）。
     * $ip が null（注文の入力から）のときは、メールごとの回数だけを数える（注文の受付そのものに回数の上限がある）
     */
    public function subscribe(?string $email, ?CustomerAccount $member, string $source, ?string $ip): SubscribeOutcome
    {
        $email = trim($member !== null ? $member->email : (string) $email);
        $silent = $member === null ? SubscribeOutcome::Sent : null;

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $silent ?? SubscribeOutcome::Throttled;
        }

        if (! $this->allowed($email, $ip)) {
            return $silent ?? SubscribeOutcome::Throttled;
        }

        $index = BlindIndex::of('email', $email);

        if (NewsletterSubscriber::query()->whereIn('status', [SubscriberStatus::Pending->value, SubscriberStatus::Active->value])->count() >= self::SHOP_MAX
            && ! NewsletterSubscriber::query()->where('email_index', $index)->exists()) {
            return SubscribeOutcome::Full;
        }

        try {
            return DB::transaction(function () use ($email, $index, $member, $source, $silent): SubscribeOutcome {
                $row = NewsletterSubscriber::query()->where('email_index', $index)->lockForUpdate()->first();
                $now = CarbonImmutable::now();

                // もう届けている: 何も変えない（ゲストには同じ答え。会員なら会員の印だけを付ける）
                if ($row?->status === SubscriberStatus::Active) {
                    if ($member !== null && $row->customer_account_id === null) {
                        $row->forceFill(['customer_account_id' => $member->id])->save();
                    }

                    return $silent ?? SubscribeOutcome::Already;
                }

                $row ??= new NewsletterSubscriber(['email' => $email]);

                if ($member !== null) {
                    // 会員: ログインで確かめたメールなので、すぐ届ける
                    $row->fill(['status' => SubscriberStatus::Active, 'source' => $source, 'consented_at' => $now, 'confirmed_at' => $now,
                        'token_hash' => null, 'mail_token' => null, 'expires_at' => null, 'unsubscribed_at' => null, 'unsubscribed_via' => null]);
                    $row->customer_account_id = $member->id;
                    $row->save();

                    return SubscribeOutcome::Subscribed;
                }

                // ゲスト: 確かめのメール（確かめ待ちがもうあれば、合い言葉を新しくして送り直す。前のリンクは使えなくなる）。
                // 前に止めた人の、止めた日時と経路は、確かめるまで残す（確かめられなければ、止めたままに戻す）
                $token = self::token();
                $row->fill(['status' => SubscriberStatus::Pending, 'source' => $source, 'consented_at' => $now, 'confirmed_at' => null,
                    'token_hash' => self::hash($token), 'mail_token' => $token, 'expires_at' => $now->addHours(self::CONFIRM_HOURS)]);
                $row->save();
                $id = $row->id;
                DB::afterCommit(static fn () => SendConfirmMail::dispatch($id));

                return SubscribeOutcome::Sent;
            });
        } catch (UniqueConstraintViolationException) {
            // 同じ時に送られた 2 つ目（DB の部分の一意の索引が拒んだ）
            return $silent ?? SubscribeOutcome::Already;
        }
    }

    /**
     * 確かめのリンクを押した（ゲストの購読を届けるようにする）
     */
    public function confirm(string $token): TokenOutcome
    {
        return DB::transaction(function () use ($token): TokenOutcome {
            $row = NewsletterSubscriber::query()->where('token_hash', self::hash($token))->lockForUpdate()->first();
            $state = self::confirmState($row);

            if ($row === null || $state !== TokenOutcome::Pending) {
                return $state;
            }

            $row->forceFill(['status' => SubscriberStatus::Active, 'confirmed_at' => CarbonImmutable::now(), 'expires_at' => null, 'mail_token' => null,
                'unsubscribed_at' => null, 'unsubscribed_via' => null])->save();

            return TokenOutcome::Confirmed;
        });
    }

    /**
     * 確かめのリンクの様子を、変えずに読む（押す前の画面）
     */
    public function peekConfirm(string $token): TokenOutcome
    {
        return self::confirmState(NewsletterSubscriber::query()->where('token_hash', self::hash($token))->first());
    }

    /**
     * 配信のメールの停止のリンク（画面のボタンか、メールのソフトのワンクリック）。その人の購読を止め、どの配信から止めたかを残す。
     * 合い言葉を知っても、その 1 人の購読しか止められない
     */
    public function unsubscribe(string $token, string $via): TokenOutcome
    {
        return DB::transaction(function () use ($token, $via): TokenOutcome {
            $delivery = NewsletterDelivery::query()->where('token_hash', self::hash($token))->lockForUpdate()->first();

            if ($delivery === null) {
                return TokenOutcome::Invalid;
            }

            $row = NewsletterSubscriber::query()->whereKey($delivery->subscriber_id)->lockForUpdate()->first();

            if ($row === null || $row->status === SubscriberStatus::Unsubscribed) {
                return TokenOutcome::Stopped;
            }

            $now = CarbonImmutable::now();
            self::stop($row, $via, $now);
            $delivery->forceFill(['unsubscribed_at' => $now])->save();

            return TokenOutcome::StoppedNow;
        });
    }

    /**
     * 停止のリンクの様子を、変えずに読む（押す前の画面）
     */
    public function peekUnsubscribe(string $token): TokenOutcome
    {
        $delivery = NewsletterDelivery::query()->where('token_hash', self::hash($token))->first();

        if ($delivery === null) {
            return TokenOutcome::Invalid;
        }

        $status = NewsletterSubscriber::query()->find($delivery->subscriber_id)?->status;

        return $status === null || $status === SubscriberStatus::Unsubscribed ? TokenOutcome::Stopped : TokenOutcome::Active;
    }

    /**
     * ほかの App のお知らせのメール（「カゴ落ちのお知らせ」など）の配信の停止のリンクから止めた（核の口 MarketingConsent。ADR 0067）。
     * そのメールの購読（確かめ待ちも）を止める。経路は link か one_click（どの App のメールから止めたかは残さない）。止めたら真
     */
    public function withdraw(string $email, string $via): bool
    {
        $via = $via === 'one_click' ? 'one_click' : 'link';

        return DB::transaction(function () use ($email, $via): bool {
            $row = NewsletterSubscriber::query()->where('email_index', BlindIndex::of('email', $email))
                ->whereIn('status', [SubscriberStatus::Pending->value, SubscriberStatus::Active->value])->lockForUpdate()->first();

            if ($row === null) {
                return false;
            }

            self::stop($row, $via, CarbonImmutable::now());

            return true;
        });
    }

    /**
     * 会員の今の様子（マイページ）。会員のメールの購読
     */
    public function memberStatus(CustomerAccount $member): ?SubscriberStatus
    {
        return NewsletterSubscriber::query()->where('email_index', BlindIndex::of('email', $member->email))->first()?->status;
    }

    /**
     * 会員がマイページで止めた。会員のメールの購読と、その会員が選んだ購読（前のメールのもの）を止める
     */
    public function unsubscribeMember(CustomerAccount $member, string $via = 'account'): int
    {
        return DB::transaction(function () use ($member, $via): int {
            $rows = NewsletterSubscriber::query()
                ->where(static fn ($q) => $q->where('email_index', BlindIndex::of('email', $member->email))->orWhere('customer_account_id', $member->id))
                ->whereIn('status', [SubscriberStatus::Pending->value, SubscriberStatus::Active->value])
                ->lockForUpdate()
                ->get();
            $now = CarbonImmutable::now();

            foreach ($rows as $row) {
                self::stop($row, $via, $now);
            }

            return $rows->count();
        });
    }

    /**
     * 退会した会員の購読を止める（退会と同じトランザクション）
     */
    public function leave(string $accountId): int
    {
        $rows = NewsletterSubscriber::query()->where('customer_account_id', $accountId)
            ->whereIn('status', [SubscriberStatus::Pending->value, SubscriberStatus::Active->value])->lockForUpdate()->get();
        $now = CarbonImmutable::now();

        foreach ($rows as $row) {
            self::stop($row, 'leave', $now);
        }

        return $rows->count();
    }

    /**
     * 片付け: 確かめられないまま期限を過ぎた購読を消し（前に止めた人なら、止めたままに戻す）、
     * 止めてから RETAIN_DAYS 日を過ぎた人のメールを消す
     *
     * @return array{expired: int, erased: int}
     */
    public function prune(CarbonImmutable $now): array
    {
        $expired = NewsletterSubscriber::query()->where('status', SubscriberStatus::Pending->value)->where('expires_at', '<', $now);
        $reverted = (clone $expired)->whereNotNull('unsubscribed_at')->update([
            'status' => SubscriberStatus::Unsubscribed->value, 'token_hash' => null, 'mail_token' => null, 'expires_at' => null, 'updated_at' => $now,
        ]);
        $deleted = (clone $expired)->whereNull('unsubscribed_at')->delete();
        $erased = NewsletterSubscriber::query()->where('status', SubscriberStatus::Unsubscribed->value)
            ->where('unsubscribed_at', '<', $now->subDays(self::RETAIN_DAYS))
            ->whereNotNull('email_index')
            ->update(['email' => null, 'email_index' => null, 'customer_account_id' => null, 'updated_at' => $now]);

        return ['expired' => $reverted + (int) $deleted, 'erased' => $erased];
    }

    /**
     * 止める（行のロックの中で）。確かめのリンクも使えなくする
     */
    private static function stop(NewsletterSubscriber $row, string $via, CarbonImmutable $now): void
    {
        $row->forceFill(['status' => SubscriberStatus::Unsubscribed, 'unsubscribed_at' => $now, 'unsubscribed_via' => $via,
            'token_hash' => null, 'mail_token' => null, 'expires_at' => null])->save();
    }

    private static function confirmState(?NewsletterSubscriber $row): TokenOutcome
    {
        return match (true) {
            $row === null => TokenOutcome::Invalid,
            $row->status === SubscriberStatus::Active => TokenOutcome::Active,
            $row->status === SubscriberStatus::Unsubscribed => TokenOutcome::Stopped,
            $row->expires_at === null || $row->expires_at->isPast() => TokenOutcome::Invalid,
            default => TokenOutcome::Pending,
        };
    }

    /**
     * 回数の上限（数えてから比べ、超えていなければ真）
     */
    private function allowed(string $email, ?string $ip): bool
    {
        $shop = (string) tenant()?->getTenantKey();
        $index = BlindIndex::of('email', $email);
        $keys = [];

        if ($ip !== null) {
            $source = ClientSource::of($ip);
            $keys[] = ["newsletter|{$shop}|pair|".hash('sha256', $source.'|'.$index), self::PAIR_HOURLY, 3600];
            $keys[] = ["newsletter|{$shop}|source|".$source, self::SOURCE_HOURLY, 3600];
        }

        $keys[] = ["newsletter|{$shop}|email|".$index, self::EMAIL_DAILY, 86_400];

        // 先に数えてから上限と比べる（核の Throttle。狭い上限から数え、越えた所で止める）
        return Throttle::firstOver($keys) === null;
    }

    /**
     * リンクの合い言葉（32 バイトの乱数。URL に載る形で 43 文字）
     */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
