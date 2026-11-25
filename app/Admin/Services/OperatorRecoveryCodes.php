<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Agent\AgentTokens;
use App\Admin\Mail\OperatorSecurityMail;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorRecoveryCode;
use App\Admin\Models\OperatorRecoveryRequest;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\ClientSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * 運営の回復用のコード（ADR 0038）。パスキーをなくした運営が、サーバーに入らずに新しいパスキーを足すための入口。
 *
 * - 作る: 10 個（英小文字と数字 16 文字、Crockford の base32 で 80 ビット）。平文は作った時に 1 度だけ返す。作り直すと前のものは無効
 * - 保存は APP_KEY から用途で分けた鍵の HMAC-SHA256 だけ（DB が漏れても戻せない。長いので総当たりでも当たらない）
 * - 使う: メールとコードが合えば、そのコードを使用済みにし、登録のリンク（OperatorEnrollments）を出す。できるのはパスキーを足すことだけ
 * - 間違えるほど待ちが延びる（接続元 × メールの組で数える。IPv6 は /64）。組で数えるので、同じ会社の接続元のほかの人の間違いで本人が戻れなくなることはない。
 *   接続元だけでは 1 日 30 回までの大きな上限（多くのメールにばらまく総当たりを止める）。80 ビットあるので、メールだけで数える待ちは置かない
 * - 合っても違っても、運営がいてもいなくても、同じ形・同じ問い合わせで返す（そのメールの運営がいるかを探らせない）
 * - 戻った時（recovered_at）から 24 時間は、回復の前からあるパスキーを消せず、コードも作り直せない（盗んだコードで本人を締め出させない）
 * - 作った時・使った時は本人へメール（コードは載せない）と監査。使った時はほかのオーナーにも知らせる
 */
final class OperatorRecoveryCodes
{
    public const int COUNT = 10;

    /** Crockford の base32（i・l・o・u を使わない） */
    private const string ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    /** 接続元 × メールの組で、何回目の間違いの後に何秒待たせるか（3 回まではすぐ。8 回目からは 24 時間） */
    private const array WAITS = [4 => 60, 5 => 300, 6 => 900, 7 => 3600];

    private const int LONGEST_WAIT = 86_400;

    /** 接続元だけでの 1 日の上限（多くのメールにばらまく総当たりを止める） */
    public const int SOURCE_DAILY_LIMIT = 30;

    public function __construct(
        private readonly AuditLog $audit,
        private readonly OperatorEnrollments $enrollments,
    ) {}

    /**
     * 回復用のコードを作り直す（前のものは無効）。
     *
     * @return list<string> 平文（4 文字ずつ区切った形）。保存はしない
     */
    public function issue(Operator $operator, ?string $ip = null): array
    {
        $codes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = '';

            for ($j = 0; $j < 16; $j++) {
                $raw .= self::ALPHABET[random_int(0, 31)];
            }

            $codes[] = implode('-', str_split($raw, 4));
        }

        DB::connection($operator->getConnectionName())->transaction(function () use ($operator, $codes): void {
            // 運営の行を押さえる（作り直しが同時に 2 回走って 20 個が有効になるのを防ぐ）
            Operator::query()->whereKey($operator->id)->lockForUpdate()->firstOrFail();
            OperatorRecoveryCode::query()->where('operator_id', $operator->id)->delete();

            foreach ($codes as $code) {
                OperatorRecoveryCode::create(['operator_id' => $operator->id, 'code_hash' => self::hash((string) self::normalize($code))]);
            }
        });

        $this->audit->record('operator.recovery_codes_issued', $operator, ['count' => self::COUNT], ip: $ip);
        Mail::to($operator->email)->queue(new OperatorSecurityMail(OperatorSecurityMail::CODES_ISSUED, $operator->name));

        return $codes;
    }

    /**
     * まだ使えるコードの数と作った時（作っていなければ null）。
     *
     * @return array{remaining: int, created_at: string|null}
     */
    public function summary(Operator $operator): array
    {
        $codes = OperatorRecoveryCode::query()->where('operator_id', $operator->id);
        $created = (clone $codes)->max('created_at');

        return [
            'remaining' => (clone $codes)->whereNull('used_at')->count(),
            'created_at' => $created === null ? null : CarbonImmutable::parse((string) $created)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * メールと回復用のコードで、新しいパスキーの登録のリンクを受け取る。
     *
     * @return array{url: string, minutes: int}|null 合わなければ null（理由は言わない）
     *
     * @throws RecoveryThrottled 間違えた回数が多く、まだ待つ時間のうち
     */
    public function redeem(string $email, string $code, ?string $ip = null): ?array
    {
        $email = mb_strtolower(trim($email));
        $source = ClientSource::of($ip);
        $pairKey = 'operator-recovery|pair|'.hash_hmac('sha256', $source.'|'.$email, self::key());
        $sourceKey = 'operator-recovery|source|'.$source;
        $wait = $this->waitLeft($pairKey);

        if ($wait > 0) {
            throw new RecoveryThrottled($wait);
        }

        // 接続元ごとの間違いの数（1 日に SOURCE_DAILY_LIMIT 回）は、確かめる前に先に数えて比べる（同時に送っても越えない）。合えば戻す
        if (! Throttle::allow($sourceKey, self::SOURCE_DAILY_LIMIT, self::LONGEST_WAIT)) {
            throw new RecoveryThrottled(max(1, Throttle::availableIn($sourceKey)));
        }

        $normalized = self::normalize($code);
        // 運営がいてもいなくても、形が違っても、同じ 1 つの問い合わせで探す（時間の差で探らせない）
        $candidate = OperatorRecoveryCode::query()
            ->join('operators', 'operators.id', '=', 'operator_recovery_codes.operator_id')
            ->where(static fn ($q) => Operator::matchEmail($q, $email, 'operators.'))
            ->whereNull('operators.disabled_at')
            ->where('operator_recovery_codes.code_hash', self::hash($normalized ?? 'invalid'))
            ->whereNull('operator_recovery_codes.used_at')
            ->value('operator_recovery_codes.id');
        $result = null;
        $operator = null;

        if ($normalized !== null && is_string($candidate)) {
            [$operator, $result] = DB::connection((new OperatorRecoveryCode)->getConnectionName())->transaction(function () use ($candidate): array {
                $row = OperatorRecoveryCode::query()->whereKey($candidate)->whereNull('used_at')->lockForUpdate()->first();
                $owner = $row === null ? null : Operator::query()->whereKey($row->operator_id)->lockForUpdate()->first();

                // 同時に同じコードを使った後の方・その間に止められた運営は、ここで外れる
                if ($row === null || $owner === null || ! $owner->isActive()) {
                    return [null, null];
                }

                $now = CarbonImmutable::now();
                $row->used_at = $now;
                $row->save();
                self::markRecovered($owner, $now);
                $issued = $this->enrollments->issue($owner, 'recovery_code');
                // 回復で戻った運営の AI エージェントの鍵は切る（盗まれた側が作った鍵を残さない。ADR 0100）
                app(AgentTokens::class)->revokeAllFor($owner, 'recovery_code');

                return [$owner, ['url' => $issued['url'], 'minutes' => $issued['minutes']]];
            });
        }

        if ($result === null || $operator === null) {
            $this->failed($pairKey);
            $this->audit->record('operator.recovery_failed', null, [], ip: $ip);

            return null;
        }

        Cache::forget($pairKey);
        Throttle::refund($sourceKey, self::LONGEST_WAIT);
        $remaining = OperatorRecoveryCode::query()->where('operator_id', $operator->id)->whereNull('used_at')->count();
        $this->audit->record('operator.recovery_code_used', $operator, ['remaining' => $remaining], ip: $ip);
        Mail::to($operator->email)->queue(new OperatorSecurityMail(OperatorSecurityMail::CODE_USED, $operator->name, $remaining));

        // ほかのオーナーにも知らせる（盗んだコードで入られた時に、本人以外も気づけるように）
        Operator::query()->where('role', 'owner')->whereNull('disabled_at')->whereKeyNot($operator->id)->get()
            ->each(static fn (Operator $owner) => Mail::to($owner->email)->queue(new OperatorSecurityMail(OperatorSecurityMail::OTHER_CODE_USED, $owner->name, null, $operator->name)));

        return $result;
    }

    /**
     * 戻った印を付ける（コードでもメールでも）。今あるパスキーは 24 時間消せない（盗んだもので入った人に本人を締め出させない）。これから足すものには付かない
     */
    public static function markRecovered(Operator $owner, CarbonImmutable $now): void
    {
        $owner->forceFill(['recovered_at' => $now])->save();
        $owner->passkeys()->update(['protected_until' => $now->addDay()]);
    }

    /**
     * 運営を止めた時・サーバーでパスキーを取り消した時の後始末: 回復用のコードを消し、メールでの戻しの申し込みを取り消す（乗っ取った人が作ったもので戻らせない）
     *
     * @return int 消したコードの数
     */
    public static function forget(Operator $operator, string $by): int
    {
        OperatorRecoveryRequest::query()->where('operator_id', $operator->id)->whereNull('used_at')->whereNull('cancelled_at')
            ->update(['cancelled_at' => CarbonImmutable::now(), 'cancelled_by' => $by]);

        return OperatorRecoveryCode::query()->where('operator_id', $operator->id)->delete();
    }

    /**
     * 打たれた文字を比べる形にする（大文字・空白・区切り・読み違えやすい文字をそろえる）。16 文字の英数字でなければ null。
     */
    public static function normalize(string $code): ?string
    {
        // 日本語の入力で打った全角の英数字・「－」・空白も受ける
        $code = mb_convert_kana($code, 'as');
        $code = strtr(strtolower((string) preg_replace('/[\s\-_ー‐―]+/u', '', $code)), ['i' => '1', 'l' => '1', 'o' => '0']);

        return preg_match('/\A['.self::ALPHABET.']{16}\z/', $code) === 1 ? $code : null;
    }

    private static function hash(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, self::key());
    }

    private static function key(): string
    {
        return hash_hkdf('sha256', (string) config('app.key'), 32, 'operator-recovery-code');
    }

    private function waitLeft(string $key): int
    {
        /** @var array{count: int, until: int}|null $state */
        $state = Cache::get($key);

        return $state === null ? 0 : max(0, $state['until'] - CarbonImmutable::now()->getTimestamp());
    }

    private function failed(string $key): void
    {
        // 調べてから書くので、同じ組の同時の間違いで数えを取りこぼさないよう鍵で順番にする
        Cache::lock($key.'|lock', 5)->block(3, function () use ($key): void {
            /** @var array{count: int, until: int}|null $state */
            $state = Cache::get($key);
            $count = ($state['count'] ?? 0) + 1;
            $wait = $count <= 3 ? 0 : (self::WAITS[$count] ?? self::LONGEST_WAIT);
            Cache::put($key, ['count' => $count, 'until' => CarbonImmutable::now()->getTimestamp() + $wait], CarbonImmutable::now()->addDay());
        });
    }
}
