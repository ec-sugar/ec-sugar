<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock;

use App\Core\Crypto\BlindIndex;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Party\Models\CustomerAccount;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\ClientSource;
use Carbon\CarbonImmutable;
use EcSugar\PluginRestock\Jobs\NotifyRestock;
use EcSugar\PluginRestock\Jobs\SendRestockMail;
use EcSugar\PluginRestock\Models\RestockRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 再入荷のお知らせの登録・確かめ・取り消し（お客さんの操作）と、退会・期限切れの片付け。登録の表を書くのはここと RestockNotifier だけ。
 *
 * 登録は「お客さんが頼んだ知らせの予約」で、取引・在庫・お金の状態ではなく、監査の源泉にもならない（conventions.md の 3 の例外。送ったメールの記録や
 * 会員のログインのコードと同じ扱い）。連鎖のロックもイベントも使わず、その行のロックと一意の制約で守る。公開の受付から誰でも作れる行なので、
 * 追記だけの記録（イベント）に積まない（いたずらの登録で記録が消せないまま膨らむのを防ぐ）。
 *
 * - ゲストは確かめのメールのリンクを押してはじめて有効（本物のメールだけ）。会員はログインで確かめたメールなので、すぐ有効にして登録の知らせを送る
 * - 回数の上限: メール × 接続元で 1 時間に 5 回、接続元だけで 1 時間に 20 回、メールごとに（接続元をまたいで）1 日に 10 回。
 *   ゲストは超えても答えは同じ（送らないだけ）。数えるキーには店の ID を入れ、メールは索引で数える（平文を鍵に入れない）
 * - 1 人が 1 つの種類に持てる進行中の登録は 1 つ（同じ時の 2 つは DB の部分の一意の索引が拒む）。1 つのメールの進行中の登録は 30 まで、店全体で 20,000 まで
 * - リンクの合い言葉は 32 バイトの乱数。表にはハッシュだけを置き、メールに載せるまでの間だけ暗号化して置く
 */
final class RestockRequests
{
    public const int CONFIRM_HOURS = 24;

    public const int PAIR_HOURLY = 5;

    public const int SOURCE_HOURLY = 20;

    public const int EMAIL_DAILY = 10;

    public const int EMAIL_ACTIVE_MAX = 30;

    public const int SHOP_ACTIVE_MAX = 20_000;

    public function __construct(private readonly RestockTargets $targets) {}

    /**
     * 売り切れの種類に登録する。会員（$member）のときはメールの欄を使わず、会員のメールで登録する
     */
    public function register(string $listingId, string $variantId, ?string $email, ?CustomerAccount $member, ?string $ip): RegisterOutcome
    {
        if (! array_key_exists($variantId, $this->targets->soldOut($listingId))) {
            $ours = ListingVariant::query()->where('listing_id', $listingId)->whereKey($variantId)->exists();

            return $ours && $this->targets->available($variantId) ? RegisterOutcome::Available : RegisterOutcome::Unavailable;
        }

        $email = trim($member !== null ? $member->email : (string) $email);
        $silent = $member === null ? RegisterOutcome::Sent : null;

        if (! $this->allowed($email, $ip)) {
            return $silent ?? RegisterOutcome::Throttled;
        }

        $index = BlindIndex::of('email', $email);
        $active = RestockRequest::query()->whereIn('status', RestockStatus::ACTIVE);

        if ((clone $active)->count() >= self::SHOP_ACTIVE_MAX) {
            return RegisterOutcome::Full;
        }

        if ((clone $active)->where('email_index', $index)->where('variant_id', '<>', $variantId)->count() >= self::EMAIL_ACTIVE_MAX) {
            return $silent ?? RegisterOutcome::Full;
        }

        try {
            return DB::transaction(function () use ($listingId, $variantId, $email, $index, $member, $silent): RegisterOutcome {
                $existing = RestockRequest::query()
                    ->where('variant_id', $variantId)
                    ->where('email_index', $index)
                    ->whereIn('status', RestockStatus::ACTIVE)
                    ->lockForUpdate()
                    ->first();

                // もう入荷を待っている: 何も送らない（ゲストには同じ答え）
                if ($existing?->status === RestockStatus::Waiting) {
                    if ($member !== null && $existing->customer_account_id === null) {
                        $existing->forceFill(['customer_account_id' => $member->id])->save();
                    }

                    return $silent ?? RegisterOutcome::Already;
                }

                $now = CarbonImmutable::now();
                $token = self::token();
                // 確かめ待ちの登録がもうあれば、合い言葉を新しくして送り直す（前のリンクは使えなくなる）。会員なら確かめ済みにする
                $row = $existing ?? new RestockRequest(['listing_id' => $listingId, 'variant_id' => $variantId, 'email' => $email]);
                $row->fill([
                    'token_hash' => self::hash($token),
                    'mail_token' => $token,
                    'status' => $member === null ? RestockStatus::Pending : RestockStatus::Waiting,
                    'expires_at' => $member === null ? $now->addHours(self::CONFIRM_HOURS) : null,
                    'confirmed_at' => $member === null ? null : $now,
                ]);
                $row->customer_account_id = $member->id ?? $row->customer_account_id;
                $row->save();

                $kind = $member === null ? 'confirm' : 'registered';
                $id = $row->id;
                DB::afterCommit(static fn () => SendRestockMail::dispatch($id, $kind));

                return $silent ?? RegisterOutcome::Registered;
            });
        } catch (UniqueConstraintViolationException) {
            // 同じ時に送られた 2 つ目（DB の部分の一意の索引が拒んだ）
            return $silent ?? RegisterOutcome::Already;
        }
    }

    /**
     * 確かめのリンクを押した（ゲストの登録を有効にする）。もう入荷していれば、すぐ知らせる
     */
    public function confirm(string $token): TokenOutcome
    {
        return DB::transaction(function () use ($token): TokenOutcome {
            $row = RestockRequest::query()->where('token_hash', self::hash($token))->lockForUpdate()->first();
            $state = self::state($row);

            if ($row === null || $state !== TokenOutcome::Pending) {
                return $state;
            }

            $row->forceFill(['status' => RestockStatus::Waiting, 'confirmed_at' => CarbonImmutable::now(), 'expires_at' => null, 'mail_token' => null])->save();
            $variantId = $row->variant_id;
            DB::afterCommit(static fn () => NotifyRestock::dispatch($variantId));

            return TokenOutcome::Confirmed;
        });
    }

    /**
     * 取り消しのリンクを押した。その登録だけを終え、メールも消す（ほかの登録には触れない）
     */
    public function cancel(string $token): TokenOutcome
    {
        return DB::transaction(function () use ($token): TokenOutcome {
            $row = RestockRequest::query()->where('token_hash', self::hash($token))->lockForUpdate()->first();
            $state = self::state($row);

            if ($row === null || ! in_array($state, [TokenOutcome::Pending, TokenOutcome::Waiting], true)) {
                return $state;
            }

            $row->forceFill(['status' => RestockStatus::Cancelled, 'cancelled_at' => CarbonImmutable::now(), 'email' => null, 'mail_token' => null, 'expires_at' => null])->save();

            return TokenOutcome::CancelledNow;
        });
    }

    /**
     * リンクの登録を、変えずに読む（押す前の画面）
     *
     * @return array{0: TokenOutcome, 1: ?RestockRequest}
     */
    public function peek(string $token): array
    {
        $row = RestockRequest::query()->where('token_hash', self::hash($token))->first();

        return [self::state($row), $row];
    }

    /**
     * 退会した会員が登録した、進行中の登録を取り消す（退会と同じトランザクション）
     */
    public function leave(string $accountId): int
    {
        return RestockRequest::query()
            ->where('customer_account_id', $accountId)
            ->whereIn('status', RestockStatus::ACTIVE)
            ->update(['status' => RestockStatus::Cancelled->value, 'cancelled_at' => CarbonImmutable::now(), 'email' => null, 'email_index' => null, 'mail_token' => null, 'expires_at' => null]);
    }

    /**
     * 確かめられないまま期限を過ぎた登録を消す（数にも入れない。メールごと消える）
     */
    public function prune(CarbonImmutable $now): int
    {
        return RestockRequest::query()->where('status', RestockStatus::Pending->value)->where('expires_at', '<', $now)->delete();
    }

    /**
     * 押したリンクの登録の今の様子
     */
    private static function state(?RestockRequest $row): TokenOutcome
    {
        return match (true) {
            $row === null => TokenOutcome::Invalid,
            $row->status === RestockStatus::Waiting => TokenOutcome::Waiting,
            $row->status === RestockStatus::Notified => TokenOutcome::Notified,
            $row->status === RestockStatus::Cancelled => TokenOutcome::Cancelled,
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
        $source = ClientSource::of($ip);
        $keys = [
            ["restock|{$shop}|pair|".hash('sha256', $source.'|'.$index), self::PAIR_HOURLY, 3600],
            ["restock|{$shop}|source|".$source, self::SOURCE_HOURLY, 3600],
            ["restock|{$shop}|email|".$index, self::EMAIL_DAILY, 86_400],
        ];

        // 先に数えてから比べる（狭い上限から。同時に送っても上限を越えない）
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
