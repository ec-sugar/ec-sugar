<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Admin\Models\AgentOAuthGrant;
use App\Admin\Models\AgentToken;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorGrant;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\OperatorSecurityNotices;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AI エージェントの鍵（ADR 0100）。発行・取り消し・照合はここだけ。
 *
 * - 鍵は `ecs_agent_` ＋ 乱数 32 バイトの base64url。発行のときに一度だけ返し、持つのは SHA-256 と頭の 8 文字だけ
 * - 発行は運営が自分の分だけ（その店で閲覧以上の段階を持つ人）。1 人 × 1 店で使える鍵は 5 本まで、期限は 1〜365 日
 * - 鍵は読むだけ（read）か書ける（write。ADR 0101）。書ける鍵は、その店で運用以上の段階を持つ人だけが作れ、期限は既定 30 日・最長 90 日
 * - 照合は毎回、運営が止まっていないか・その店の段階が今もあるかを引き直す（鍵は段階を持たない）
 * - 発行と取り消しは中央の監査ログに残す（鍵の中身は残さない）
 */
final class AgentTokens
{
    public const string PREFIX = 'ecs_agent_';

    public const int MAX_PER_SHOP = 5;

    public const int MAX_DAYS = 365;

    public const int DEFAULT_DAYS = 90;

    public const string READ = 'read';

    public const string WRITE = 'write';

    /** 書ける鍵の期限（ADR 0101。短くする） */
    public const int WRITE_MAX_DAYS = 90;

    public const int WRITE_DEFAULT_DAYS = 30;

    private const string SHAPE = '/\Aecs_agent_[A-Za-z0-9_-]{43}\z/';

    public function __construct(
        private readonly OperatorAccess $access,
        private readonly AuditLog $audit,
        private readonly OperatorSecurityNotices $notices,
    ) {}

    /**
     * 自分の分の鍵を発行する。返すのは記録と、一度だけ見せる鍵
     *
     * @return array{token: AgentToken, plain: string}
     */
    public function issue(Operator $operator, string $tenantId, mixed $name, mixed $days, ?string $ip = null, mixed $scope = self::READ): array
    {
        $scope = $scope === null || $scope === '' ? self::READ : $scope;

        if (! in_array($scope, [self::READ, self::WRITE], true)) {
            throw ValidationException::withMessages(['scope' => 'アクセスキーの種類は「閲覧のみ」か「閲覧と変更」から選んでください。']);
        }

        $write = $scope === self::WRITE;
        $max = $write ? self::WRITE_MAX_DAYS : self::MAX_DAYS;

        $name = is_string($name) ? trim(preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $name) ?? '') : '';

        if ($name === '' || mb_strlen($name) > 40 || preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'アクセスキーの名前は、改行なしの 1〜40 文字で入力してください（例「Claude Desktop」）。']);
        }

        $days = $days === null || $days === '' ? ($write ? self::WRITE_DEFAULT_DAYS : self::DEFAULT_DAYS) : $days;

        if (! is_int($days) && ! (is_string($days) && ctype_digit($days))) {
            throw ValidationException::withMessages(['days' => '期限は 1〜'.$max.' 日で選んでください。']);
        }

        $days = (int) $days;

        if ($days < 1 || $days > $max) {
            throw ValidationException::withMessages(['days' => $write ? '「閲覧と変更」のアクセスキーの期限は 1〜'.$max.' 日で選んでください。' : '期限は 1〜'.$max.' 日で選んでください。']);
        }

        $level = Tenant::query()->whereKey($tenantId)->exists() ? $this->access->levelFor($operator, $tenantId) : null;

        if ($level === null) {
            throw ValidationException::withMessages(['tenant' => 'この店のアクセスキーは作れません。']);
        }

        // 書ける鍵は、その店で運用以上の段階の人だけ（閲覧の人は管理画面でも変えられない。ADR 0101）
        if ($write && OperatorAccess::LEVELS[$level] < OperatorAccess::LEVELS['operate']) {
            throw ValidationException::withMessages(['scope' => '「閲覧と変更」のアクセスキーは、この店で「運用」か「会計」の権限を持つ人だけが作れます。']);
        }

        $plain = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $token = DB::connection((string) config('tenancy.database.central_connection'))->transaction(function () use ($operator, $tenantId, $name, $days, $plain, $scope): AgentToken {
            // 同じ人の同じ店の数は、運営の行を押さえてから数える（同時の発行で上限を越えない）
            $locked = Operator::query()->whereKey($operator->id)->lockForUpdate()->firstOrFail();
            // 店の権限の行も押さえて、段階を確かめ直す（権限を下げる・外す・オーナーから外す操作と同時でも、下げる前の段階で鍵を作らない。PR 186 の見直しの低 6）
            OperatorGrant::query()->where('operator_id', $operator->id)->where('tenant_id', $tenantId)->sharedLock()->first();
            $current = $locked->isActive() ? $this->access->levelFor($locked, $tenantId) : null;

            if ($current === null || ($scope === self::WRITE && OperatorAccess::LEVELS[$current] < OperatorAccess::LEVELS['operate'])) {
                throw ValidationException::withMessages(['tenant' => 'この店のアクセスキーは作れません。']);
            }

            // OAuth で作った鍵（1 時間で入れ替わる）は数えない。OAuth の許可の系統の数は AgentOAuthGrants が見る
            $live = AgentToken::query()->where('operator_id', $operator->id)->where('tenant_id', $tenantId)->whereNull('oauth_grant_id')
                ->whereNull('revoked_at')->where('expires_at', '>', CarbonImmutable::now())->count();

            if ($live >= self::MAX_PER_SHOP) {
                throw ValidationException::withMessages(['tenant' => 'この店のアクセスキーは '.self::MAX_PER_SHOP.' 個までです。使っていないアクセスキーを取り消してから作ってください。']);
            }

            return AgentToken::create([
                'operator_id' => $operator->id,
                'tenant_id' => $tenantId,
                'name' => $name,
                'token_hash' => hash('sha256', $plain),
                'prefix' => substr($plain, 0, strlen(self::PREFIX) + 8),
                'scope' => $scope,
                'expires_at' => CarbonImmutable::now()->addDays($days),
            ]);
        });

        $this->audit->record('operator.agent_token_issued', $operator, ['token' => $token->id, 'days' => $days, 'scope' => $scope], tenantId: $tenantId, ip: $ip);
        // 本人へ知らせる（盗まれたセッションから作られた鍵に気づけるように。見直しの中 1）
        $this->notices->agentTokenIssued($operator, $name);

        return ['token' => $token, 'plain' => $plain];
    }

    /**
     * 取り消す。本人か、オーナー。もう取り消した鍵は何もしない
     */
    public function revoke(Operator $operator, AgentToken $token, ?string $ip = null): void
    {
        if ($token->operator_id !== $operator->id && $operator->role !== 'owner') {
            throw ValidationException::withMessages(['token' => 'このアクセスキーは取り消せません。']);
        }

        // OAuth で作った鍵は、許可の系統ごと取り消す（リフレッシュトークンで新しい鍵を取らせない）
        $grant = $token->oauth_grant_id === null ? null : AgentOAuthGrant::query()->find($token->oauth_grant_id);

        if ($grant instanceof AgentOAuthGrant) {
            app(AgentOAuthGrants::class)->revoke($grant, $operator, $ip);
        }

        $done = AgentToken::query()->whereKey($token->id)->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        if ($done === 1) {
            $this->audit->record('operator.agent_token_revoked', $operator, ['token' => $token->id, 'owner' => $token->operator_id], tenantId: $token->tenant_id, ip: $ip);
        }
    }

    /**
     * その運営の使える鍵を全部取り消す（ほかの端末を全部ログアウト・パスキーを消した・回復で戻った・運営を止めた時。
     * 盗まれたセッションから作られた鍵を、セッションと一緒に切る。見直しの中 1）。取り消した数を返す
     */
    public function revokeAllFor(Operator $operator, string $reason, ?string $ip = null): int
    {
        // OAuth の許可の系統も一緒に（リフレッシュトークンで新しい鍵を取らせない。ADR 0101）
        $grants = AgentOAuthGrants::revokeFor($operator->id, null, $reason);
        $ids = AgentToken::query()->where('operator_id', $operator->id)->whereNull('revoked_at')->pluck('id')->all();

        if ($ids === [] && $grants === 0) {
            return 0;
        }

        $count = AgentToken::query()->whereIn('id', $ids)->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
        $this->audit->record('operator.agent_tokens_revoked', $operator, ['count' => $count, 'grants' => $grants, 'reason' => $reason], ip: $ip);

        return $count;
    }

    /**
     * 見られる鍵（自分の分。オーナーは全員の分）。新しい順。OAuth で作った鍵は出さない（1 時間で入れ替わる。一覧には許可の系統を出す）
     *
     * @return list<AgentToken>
     */
    public function visibleTo(Operator $operator): array
    {
        $query = AgentToken::query()->with('operator')->whereNull('oauth_grant_id')->orderByDesc('created_at');

        if ($operator->role !== 'owner') {
            $query->where('operator_id', $operator->id);
        }

        return $query->limit(200)->get()->all();
    }

    /**
     * 渡された鍵を照合し、使えるなら鍵と、届く店ごとの今の段階を返す。どこが違っても null（何が違うかを見せない）。
     * 書ける鍵でも、段階は毎回引き直す（運用より下に下げられた人の鍵は、書く道具が一覧から消えて呼んでも断る。ADR 0101）。
     * 管理画面で作った鍵は 1 店、OAuth の鍵は系統の店（ADR 0104）。段階の無くなった店・無くなった店は外し、1 店も残らなければ null
     *
     * @return array{token: AgentToken, operator: Operator, shops: non-empty-array<string, string>}|null
     */
    public function resolve(mixed $plain): ?array
    {
        if (! is_string($plain) || preg_match(self::SHAPE, $plain) !== 1) {
            return null;
        }

        // ハッシュで引く（ハッシュは一意なので、引いた後の比べ方で時間の差は出ない）
        $token = AgentToken::query()->where('token_hash', hash('sha256', $plain))->first();

        if (! $token instanceof AgentToken || ! hash_equals($token->token_hash, hash('sha256', $plain)) || ! $token->usable()) {
            return null;
        }

        $operator = Operator::query()->find($token->operator_id);

        if (! $operator instanceof Operator || ! $operator->isActive()) {
            return null;
        }

        // OAuth で作った鍵は、許可の系統が今も生きていること（系統の取り消しは鍵も同じトランザクションで取り消すが、念のため二重に）
        if ($token->oauth_grant_id !== null && ! AgentOAuthGrant::query()->whereKey($token->oauth_grant_id)->whereNull('revoked_at')->exists()) {
            return null;
        }

        $candidates = $token->oauth_grant_id !== null ? AgentOAuthGrants::shopsOf($token->oauth_grant_id) : array_filter([$token->tenant_id], 'is_string');
        $existing = $candidates === [] ? [] : Tenant::query()->whereIn('id', $candidates)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $shops = [];

        foreach ($candidates as $tenantId) {
            $level = in_array($tenantId, $existing, true) ? $this->access->levelFor($operator, $tenantId) : null;

            if ($level !== null) {
                $shops[$tenantId] = $level;
            }
        }

        if ($shops === []) {
            return null;
        }

        // 最後に使った時刻（一覧で見せる。1 分に 1 回だけ書く）
        if ($token->last_used_at === null || $token->last_used_at->lt(CarbonImmutable::now()->subMinute())) {
            AgentToken::query()->whereKey($token->id)->update(['last_used_at' => CarbonImmutable::now()]);
        }

        return ['token' => $token, 'operator' => $operator, 'shops' => $shops];
    }
}
