<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Agent\AgentTokens;
use App\Admin\Mail\OperatorSecurityMail;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorRecoveryRequest;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\ClientSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * メールでの戻し（ADR 0038 の区切り 2）。回復用のコードも無くした運営が、メールで申し込み、24 時間待ってから新しいパスキーを足す。
 *
 * - 申し込む: メールを入れると、一度きりのリンク（15 分）をそのメールへ送る。運営がいてもいなくても同じ答え、同じ時間（調べて送るのは応答の後）。
 *   回数は接続元 × メールで 1 日 3 回・メールだけで 1 日 10 回・接続元だけで 1 時間 10 回。新しい申し込みで前のリンクは消さない（メールを知っているだけの人に本人の申し込みを止めさせない）
 * - リンクを開いて「受け付ける」: 24 時間の待ちに入り、そのブラウザに印（Cookie。保存はハッシュだけ）を渡す。ほかのまだ開いていないリンクは取り消す。本人とほかのオーナーへ知らせる
 * - 24 時間の後、72 時間のうちに同じブラウザで: 登録のリンクを受け取り、新しいパスキーを足す（戻った印も付ける。OperatorRecoveryCodes::markRecovered）。過ぎたら使えない
 * - 取り消し: 本人が元のパスキーでログイン・再確認・登録した時、ログイン中にホームの知らせから取り消した時（cancelFor）、運営を止めた・サーバーで取り消した時（OperatorRecoveryCodes::forget）
 */
final class OperatorEmailRecovery
{
    public const int LINK_MINUTES = 15;

    public const int WAIT_HOURS = 24;

    public const string COOKIE = 'ec_recovery';

    /** 待ちの後、受け取れる時間（過ぎたら使えない） */
    public const int USABLE_HOURS = 72;

    public const int PAIR_DAILY_LIMIT = 3;

    public const int EMAIL_DAILY_LIMIT = 10;

    public const int SOURCE_HOURLY_LIMIT = 10;

    public function __construct(
        private readonly AuditLog $audit,
        private readonly OperatorEnrollments $enrollments,
    ) {}

    /**
     * 申し込む。運営がいてもいなくても、回数を超えても、何も言わない（呼び出し側はいつも同じ答えを返す）。
     * 調べて送るのは応答の後（運営がいるかで答えの時間が変わらないように）。
     */
    public function request(string $email, ?string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $source = ClientSource::of($ip);
        $keys = [
            ['operator-recovery-mail|pair|'.hash_hmac('sha256', $source.'|'.$email, self::key()), self::PAIR_DAILY_LIMIT, 86_400],
            ['operator-recovery-mail|source|'.$source, self::SOURCE_HOURLY_LIMIT, 3600],
            ['operator-recovery-mail|email|'.hash_hmac('sha256', $email, self::key()), self::EMAIL_DAILY_LIMIT, 86_400],
        ];

        // 先に足してから比べる（同時に頼んでも上限を超えない。第 2 回。核の Throttle で狭い上限から）
        if (Throttle::firstOver($keys) !== null) {
            return;
        }

        \Illuminate\Support\defer(fn () => $this->send($email, $ip));
    }

    private function send(string $email, ?string $ip): void
    {
        $operator = Operator::query()->whereEmail($email)->whereNull('disabled_at')->first();

        if ($operator === null) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        OperatorRecoveryRequest::create([
            'operator_id' => $operator->id,
            'link_hash' => self::hash($token),
            'link_expires_at' => CarbonImmutable::now()->addMinutes(self::LINK_MINUTES),
        ]);

        $this->audit->record('operator.recovery_email_sent', $operator, [], ip: $ip);
        Mail::to($operator->email)->queue(new OperatorSecurityMail(OperatorSecurityMail::RECOVERY_LINK, $operator->name, link: config('admin.url').'/recover/email#'.$token));
    }

    /**
     * メールのリンクを開いた: 24 時間の待ちに入る。
     *
     * @return array{browser: string, ready_at: CarbonImmutable}|null リンクが違う・期限切れ・使用済みなら null
     */
    public function confirm(string $token, ?string $ip): ?array
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return null;
        }

        $browser = bin2hex(random_bytes(32));
        $result = DB::connection((new OperatorRecoveryRequest)->getConnectionName())->transaction(function () use ($token, $browser): ?array {
            $row = OperatorRecoveryRequest::query()->where('link_hash', self::hash($token))->whereNull('browser_hash')->whereNull('cancelled_at')
                ->where('link_expires_at', '>', CarbonImmutable::now())->lockForUpdate()->first();
            $operator = $row === null ? null : Operator::query()->whereKey($row->operator_id)->first();

            if ($row === null || $operator === null || ! $operator->isActive()) {
                return null;
            }

            $now = CarbonImmutable::now();
            $row->browser_hash = self::hash($browser);
            $row->ready_at = $now->addHours(self::WAIT_HOURS);
            $row->link_expires_at = $now;
            $row->save();
            // ほかのまだ開いていないリンクは要らない（どれか 1 つで受け付けた）
            OperatorRecoveryRequest::query()->where('operator_id', $operator->id)->whereKeyNot($row->id)->whereNull('browser_hash')->whereNull('cancelled_at')
                ->update(['cancelled_at' => $now, 'cancelled_by' => 'superseded']);

            return [$operator, $row->ready_at];
        });

        if ($result === null) {
            return null;
        }

        [$operator, $readyAt] = $result;
        $this->audit->record('operator.recovery_email_waiting', $operator, ['ready_at' => $readyAt->utc()->format('Y-m-d\TH:i:s\Z')], ip: $ip);
        $this->notify($operator, OperatorSecurityMail::RECOVERY_WAITING, OperatorSecurityMail::OTHER_RECOVERY_WAITING, $readyAt);

        return ['browser' => $browser, 'ready_at' => $readyAt];
    }

    /**
     * 24 時間の後に、同じブラウザで登録のリンクを受け取る。
     *
     * @return array{url: string, minutes: int}|CarbonImmutable|null 受け取れたらリンク、まだ待つならその時、申し込みが無い・取り消されたなら null
     */
    public function finish(?string $browser, ?string $ip): array|CarbonImmutable|null
    {
        if ($browser === null || preg_match('/\A[0-9a-f]{64}\z/', $browser) !== 1) {
            return null;
        }

        // 運営の行を先に押さえる（運営を止める処理・申し込みと同じ順。行き詰まりを作らない）
        $operatorId = OperatorRecoveryRequest::query()->where('browser_hash', self::hash($browser))->value('operator_id');

        if (! is_string($operatorId)) {
            return null;
        }

        $result = DB::connection((new OperatorRecoveryRequest)->getConnectionName())->transaction(function () use ($browser, $operatorId): array|CarbonImmutable|null {
            $operator = Operator::query()->whereKey($operatorId)->lockForUpdate()->first();
            $row = OperatorRecoveryRequest::query()->where('browser_hash', self::hash($browser))->whereNull('used_at')->whereNull('cancelled_at')
                ->lockForUpdate()->first();

            if ($row === null || $operator === null || ! $operator->isActive() || $row->ready_at === null) {
                return null;
            }

            if ($row->ready_at->isFuture()) {
                return $row->ready_at;
            }

            $now = CarbonImmutable::now();

            // 待ちの後に受け取れるのは 72 時間まで（控えた印を何週間も後に使わせない）
            if ($now->greaterThan($row->ready_at->addHours(self::USABLE_HOURS))) {
                $row->cancelled_at = $now;
                $row->cancelled_by = 'expired';
                $row->save();

                return null;
            }

            $row->used_at = $now;
            $row->save();
            OperatorRecoveryCodes::markRecovered($operator, $now);
            $issued = $this->enrollments->issue($operator, 'recovery_email');
            // 回復で戻った運営の AI エージェントの鍵は切る（ADR 0100）
            app(AgentTokens::class)->revokeAllFor($operator, 'recovery_email');

            return ['operator' => $operator, 'url' => $issued['url'], 'minutes' => $issued['minutes']];
        });

        if (! is_array($result)) {
            return $result;
        }

        /** @var Operator $operator */
        $operator = $result['operator'];
        $this->audit->record('operator.recovery_email_used', $operator, [], ip: $ip);
        $this->notify($operator, OperatorSecurityMail::RECOVERY_EMAIL_USED, OperatorSecurityMail::OTHER_CODE_USED);

        return ['url' => $result['url'], 'minutes' => $result['minutes']];
    }

    /**
     * 本人が元のパスキーで入れた（ログイン・再確認・登録）か、ホームの知らせから取り消した: 待っている申し込みを取り消す（乗っ取りの申し込みならここで止まる）。
     */
    public function cancelFor(Operator $operator, string $by, ?string $ip): void
    {
        $count = OperatorRecoveryRequest::query()->where('operator_id', $operator->id)->whereNull('used_at')->whereNull('cancelled_at')
            ->update(['cancelled_at' => CarbonImmutable::now(), 'cancelled_by' => $by]);

        if ($count > 0) {
            $this->audit->record('operator.recovery_email_cancelled', $operator, ['count' => $count, 'by' => $by], ip: $ip);
            Mail::to($operator->email)->queue(new OperatorSecurityMail(OperatorSecurityMail::RECOVERY_CANCELLED, $operator->name));
        }
    }

    /**
     * 待っている申し込み（ホームの知らせのため。開ける時だけ）。無ければ null。
     */
    public function pending(Operator $operator): ?CarbonImmutable
    {
        $readyAt = OperatorRecoveryRequest::query()->where('operator_id', $operator->id)->whereNotNull('ready_at')->whereNull('used_at')->whereNull('cancelled_at')
            ->max('ready_at');

        return $readyAt === null ? null : CarbonImmutable::parse((string) $readyAt);
    }

    private function notify(Operator $operator, string $self, string $others, ?CarbonImmutable $readyAt = null): void
    {
        Mail::to($operator->email)->queue(new OperatorSecurityMail($self, $operator->name, readyAt: $readyAt));
        Operator::query()->where('role', 'owner')->whereNull('disabled_at')->whereKeyNot($operator->id)->get()
            ->each(static fn (Operator $owner) => Mail::to($owner->email)->queue(new OperatorSecurityMail($others, $owner->name, subjectName: $operator->name, readyAt: $readyAt)));
    }

    private static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }

    private static function key(): string
    {
        return hash_hkdf('sha256', (string) config('app.key'), 32, 'operator-recovery-email');
    }
}
