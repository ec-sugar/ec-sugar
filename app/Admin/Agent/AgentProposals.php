<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Admin\Agent\Models\AgentProposal;
use App\Admin\Models\AgentToken;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\SalesPeriod;
use App\Core\Crypto\TenantCipher;
use App\Core\Event\EventStore;
use App\Core\Payment\Models\Refund;
use App\Core\Shop\Services\AgentSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * AI エージェントの書き込みの受け付けと、承認の一覧（ADR 0101）。案（agent_proposals）の状態を変えるのはここだけ。
 *
 * - submit(): エージェントの書く道具から。店の設定が「すぐ反映」で、承認が必須でない道具（お金に届かない）なら、その場で反映する。
 *   お客さんの情報を渡す店では、ショップに公開される文を書く道具（商品を作る・直す）も承認が要る
 *   （中身の確かめと反映を同じ連鎖のロックの中で）。ほかは案を作るだけで、店のデータは変えない
 * - approve(): 管理画面の承認。押した人がその案に要る段階を持つこと（お金の操作はお金の段階）。ロックの中で「待ち・期限の前」を確かめ、
 *   引数で中身を作り直して、作った時の照らす値（改訂の番号・金額・状態）と比べる。違えば反映せずに failed。
 *   決済代行に頼まない道具は、確かめと反映と結果を 1 つのトランザクションで（失敗したら反映ごと戻して failed を残す）。
 *   取消・返金は、先に approved を書いてから（二度押し・同時の承認はここで断る）ロックの外で反映し、結果を書く
 * - reject()・expireDue()（7 日。定期の処理 agent:expire-proposals）
 *
 * どの書き込みも EventStore::lock() → 案の行のロックの順で、同じトランザクションでイベントを残す（payload は道具・ID・状態だけ。引数の中身は入れない）
 */
final class AgentProposals
{
    public const int DAYS = 7;

    public const string CHANGED = 'この提案が作られた後に、店の内容が変わりました（値段・在庫・注文の状態など）。反映していません。AI エージェントにもう一度依頼してください。';

    public const string KEY_GONE = 'この提案を作った AI エージェントのアクセスキーが取り消されたか、作成者のこの店での権限が足りなくなりました。反映していません。';

    public const string CHECKING = 'Stripe から返金の結果がまだ届いていません。結果は自動で確認し直します。注文の画面で今の状態を確認してください。';

    /** 待ちの案の数の上限（鍵ごと・店ごと。見直しの低 7） */
    public const int MAX_PENDING_PER_TOKEN = 50;

    public const int MAX_PENDING_PER_SHOP = 200;

    public function __construct(
        private readonly EventStore $events,
        private readonly AuditLog $audit,
        private readonly AgentSettings $settings,
    ) {}

    /**
     * エージェントの書く道具の 1 回。すぐ反映したか、案を作ったかを返す
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function submit(AgentContext $context, ShopWriter $writer, string $tool, array $arguments, ?string $ip = null): array
    {
        $rule = ShopWriter::TOOLS[$tool] ?? throw new ToolInputException('Unknown tool.');
        $operator = Operator::query()->find($context->operatorId) ?? throw new LogicException('鍵の持ち主がいない');
        $plan = $writer->plan($tool, $arguments);

        if ($this->requiresApproval($rule, $plan, $context)) {
            $proposal = $this->create($plan, $context);
            $this->audit->record('operator.agent_proposal_created', $operator, ['token' => $context->tokenId, 'tool' => $tool, 'proposal' => $proposal->id], tenantId: $context->tenantId, ip: $ip);

            return [
                'status' => 'proposed',
                'proposal_id' => $proposal->id,
                'summary' => ShopReader::untrusted($proposal->summary),
                'expires_at' => $proposal->expires_at->setTimezone(SalesPeriod::zone())->toIso8601String(),
                'message' => 'Nothing was changed yet. A proposal was created and the shop staff will approve or reject it in the admin (AI agent proposals). Check the outcome later with get_proposal.',
            ];
        }

        // 中身の確かめ（今の値を読む）と反映を、同じ連鎖のロックの中で（間に別の変更が入らない）
        [$plan, $result] = DB::transaction(function () use ($writer, $tool, $arguments, $context): array {
            $this->events->lock();
            $plan = $writer->plan($tool, $arguments);

            // ロックの前に読んだ値から変わって、承認が要る中身になった（大きな値下げなど）: 反映しない
            if ($plan->approval) {
                throw new ToolInputException('The shop changed while this was being applied. Read it again and retry.');
            }

            return [$plan, $writer->apply($plan, $context->actor())];
        });
        $this->audit->record('operator.agent_write', $operator, ['token' => $context->tokenId, 'tool' => $tool] + self::ids($result), tenantId: $context->tenantId, ip: $ip);

        return ['status' => 'applied', 'summary' => ShopReader::untrusted($plan->summary), 'result' => $result];
    }

    /**
     * 承認が要るか。道具が承認を必須にしている（お金に届く）・決済代行に頼む（ロックの外で反映するので、すぐ反映の道を通さない）・
     * 店の設定が「承認してから」・お客さんの情報を渡す店で外の人に届く値を書く・中身が承認を求める（今の値段の半分より下げる）・
     * 2 店以上を選んだ OAuth のつなぎ（ADR 0104。ほかの店で読んだ文にそそのかされて、この店を書き換える道を人の承認で止める）のどれか
     *
     * @param  array{level: string, approval: bool, external: bool, public: bool}  $rule
     */
    public function requiresApproval(array $rule, WritePlan $plan, ?AgentContext $context = null): bool
    {
        return $rule['approval'] || $rule['external'] || $plan->approval
            || $this->settings->writeMode() === AgentSettings::APPROVAL
            || ($rule['public'] && $this->settings->sharesCustomerData())
            || ($context !== null && self::coversSeveralShops($context));
    }

    /**
     * 2 店以上に届くつなぎか。系統の店（許可で選んだ店）で数える（段階の無くなった店を外した後の数でなく。外れた店があれば系統は取り消し済み）
     */
    public static function coversSeveralShops(AgentContext $context): bool
    {
        if (count($context->reachableShops()) > 1) {
            return true;
        }

        return $context->grantId !== null && count(AgentOAuthGrants::shopsOf($context->grantId)) > 1;
    }

    /**
     * 案を作る（店のデータは変えない）。待ちの案は鍵ごとに 50 件・店ごとに 200 件まで、同じ中身の待ちの案は 1 つだけ
     */
    public function create(WritePlan $plan, AgentContext $context): AgentProposal
    {
        $fingerprint = app(TenantCipher::class)->index('agent-proposal|'.$plan->tool.'|'.json_encode(self::canonical($plan->arguments), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($plan, $context, $fingerprint): AgentProposal {
            $this->events->lock();
            $now = CarbonImmutable::now();
            $pending = AgentProposal::query()->where('status', AgentProposal::PENDING)->where('expires_at', '>', $now);

            if ((clone $pending)->where('fingerprint', $fingerprint)->exists()) {
                throw new ToolInputException('The same proposal is already waiting for the shop staff. Check it with get_proposal instead of sending it again.');
            }

            if ((clone $pending)->where('token_id', $context->tokenId)->count() >= self::MAX_PENDING_PER_TOKEN) {
                throw new ToolInputException('This key already has '.self::MAX_PENDING_PER_TOKEN.' proposals waiting. Ask the shop staff to approve or reject them first.');
            }

            if ((clone $pending)->count() >= self::MAX_PENDING_PER_SHOP) {
                throw new ToolInputException('This shop already has '.self::MAX_PENDING_PER_SHOP.' proposals waiting. Ask the shop staff to approve or reject them first.');
            }

            // 期限の過ぎた待ちの案は、指紋の一意の索引から外す（定期の処理の前でも同じ中身を作り直せる）
            AgentProposal::query()->where('status', AgentProposal::PENDING)->where('fingerprint', $fingerprint)->where('expires_at', '<=', $now)
                ->get()->each(fn (AgentProposal $old) => $this->close($old, AgentProposal::EXPIRED, null, null, 'system:agent-proposals'));

            $proposal = AgentProposal::create([
                'fingerprint' => $fingerprint,
                'tool' => $plan->tool,
                'level' => $plan->level,
                'arguments' => $plan->arguments,
                'basis' => $plan->basis,
                'summary' => $plan->summary,
                'token_id' => $context->tokenId,
                'operator_id' => $context->operatorId,
                'expires_at' => $now->addDays(self::DAYS),
            ]);
            $this->events->append('agent.proposal_created', 'agent_proposal', $proposal->id, ['tool' => $plan->tool, 'level' => $plan->level, 'token' => $context->tokenId], $context->actor());

            return $proposal;
        });
    }

    /**
     * 承認して反映する。$level は押した人のこの店の今の段階。反映できなかったら failed にして返す（例外にしない）
     */
    public function approve(string $id, Operator $operator, string $level, ShopWriter $writer): AgentProposal
    {
        $actor = $operator->actor();

        $claimed = DB::transaction(function () use ($id, $operator, $level, $writer, $actor): array {
            $this->events->lock();
            $proposal = $this->lockPending($id, $level);

            if ($proposal === null) {
                return ['expired' => true];
            }

            // 作った鍵が取り消された・作った人のこの店の段階が足りなくなった案は反映しない（却下にして理由を残す。見直しの中 1）
            if (! self::creatorStillAllowed($proposal)) {
                return ['proposal' => $this->close($proposal, AgentProposal::REJECTED, $operator->id, ['error' => self::KEY_GONE], $actor), 'plan' => null];
            }

            try {
                $plan = $writer->plan($proposal->tool, $proposal->arguments);
            } catch (ToolInputException $e) {
                return ['proposal' => $this->close($proposal, AgentProposal::FAILED, $operator->id, ['error' => $e->getMessage()], $actor), 'plan' => null];
            }

            if (! $plan->sameBasis($proposal->basis)) {
                return ['proposal' => $this->close($proposal, AgentProposal::FAILED, $operator->id, ['error' => self::CHANGED], $actor), 'plan' => null];
            }

            if ($plan->external) {
                // 先に決めた印を書く（二度押し・同時の承認はここで断られる）。反映は外へ頼むのでロックの外で
                return ['proposal' => $this->close($proposal, AgentProposal::APPROVED, $operator->id, null, $actor), 'plan' => $plan];
            }

            try {
                $result = DB::transaction(static fn (): array => $writer->apply($plan, $actor));
            } catch (ToolInputException $e) {
                return ['proposal' => $this->close($proposal, AgentProposal::FAILED, $operator->id, ['error' => $e->getMessage()], $actor), 'plan' => null];
            }

            return ['proposal' => $this->close($proposal, AgentProposal::APPROVED, $operator->id, ['ok' => true] + $result, $actor), 'plan' => null];
        });

        if (isset($claimed['expired'])) {
            throw self::expired();
        }

        /** @var AgentProposal $proposal */
        $proposal = $claimed['proposal'];
        $this->audit->record('operator.agent_proposal_decided', $operator, ['proposal' => $proposal->id, 'tool' => $proposal->tool, 'status' => $proposal->status, 'token' => $proposal->token_id], tenantId: (string) tenant()?->getTenantKey());

        if (! $claimed['plan'] instanceof WritePlan) {
            return $proposal;
        }

        // 取消・返金: ロックの外で反映し、答えを書く（答えの分からない返金は、核の見回りが同じ冪等キーで続きを進める）
        try {
            $result = ['ok' => true] + $writer->apply($claimed['plan'], $actor);
        } catch (AgentWriteUncertain $e) {
            // 答えが分からない: 返金の記録が途中なら、見回りが同じ冪等キーで続きを進める。failed にせず「確かめ中」（見直しの中 3）
            $order = (string) ($claimed['plan']->arguments['order_id'] ?? '');
            $result = Refund::query()->where('transaction_id', $order)->where('status', 'requested')->exists()
                ? ['ok' => true, 'checking' => true, 'order_id' => $order, 'message' => self::CHECKING]
                : ['error' => $e->getMessage()];
        } catch (ToolInputException $e) {
            $result = ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            $this->finish($proposal->id, ['error' => '反映が途中で止まりました。注文の画面で今の状態を確認してください。'], $actor);

            throw $e;
        }

        return $this->finish($proposal->id, $result, $actor);
    }

    public function reject(string $id, Operator $operator, string $level): AgentProposal
    {
        $proposal = DB::transaction(function () use ($id, $operator, $level): ?AgentProposal {
            $this->events->lock();
            $proposal = $this->lockPending($id, $level, rejecting: true);

            return $proposal === null ? null : $this->close($proposal, AgentProposal::REJECTED, $operator->id, null, $operator->actor());
        }) ?? throw self::expired();
        $this->audit->record('operator.agent_proposal_decided', $operator, ['proposal' => $proposal->id, 'tool' => $proposal->tool, 'status' => $proposal->status, 'token' => $proposal->token_id], tenantId: (string) tenant()?->getTenantKey());

        return $proposal;
    }

    /**
     * 期限の過ぎた待ちの案を expired にする（定期の処理から）。した数を返す
     */
    public function expireDue(CarbonImmutable $now, string $actor = 'system:agent-proposals'): int
    {
        $count = 0;

        foreach (AgentProposal::query()->where('status', AgentProposal::PENDING)->where('expires_at', '<=', $now)->orderBy('expires_at')->limit(1000)->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id, $now, $actor): int {
                $this->events->lock();
                $proposal = AgentProposal::query()->whereKey($id)->lockForUpdate()->first();

                if (! $proposal instanceof AgentProposal || $proposal->status !== AgentProposal::PENDING || $proposal->expires_at->gt($now)) {
                    return 0;
                }

                $this->close($proposal, AgentProposal::EXPIRED, null, null, $actor);

                return 1;
            });
        }

        return $count;
    }

    /**
     * 待ちの案の数（ホームのやること。期限の過ぎたものは数えない）
     */
    public static function pendingCount(): int
    {
        return AgentProposal::query()->where('status', AgentProposal::PENDING)->where('expires_at', '>', CarbonImmutable::now())->count();
    }

    /**
     * 待ちで期限の前の案を押さえる。もう決まった・段階が足りないは断る。期限の過ぎた案はここで expired にして null
     * （呼ぶ側はトランザクションを閉じてから断る。期限切れの記録を戻さないため）
     */
    private function lockPending(string $id, string $level, bool $rejecting = false): ?AgentProposal
    {
        $proposal = AgentProposal::query()->whereKey(strtolower($id))->lockForUpdate()->first() ?? throw new AgentProposalRefused('提案が見つかりません。', 404);

        if ($proposal->status !== AgentProposal::PENDING) {
            throw new AgentProposalRefused('この提案は、すでに処理が済んでいます（'.self::label($proposal->status).'）。');
        }

        // 承認には、その案に要る段階（お金の操作はお金の段階）。保存した段階と今の道具の段階の高い方（決まりを上げた後の古い案も）。却下は運用の段階から（店のデータを変えない）
        $required = self::requiredLevel($proposal);

        if (! $rejecting && OperatorAccess::LEVELS[$level] < OperatorAccess::LEVELS[$required]) {
            throw new AgentProposalRefused('この提案の承認には「'.($required === 'money' ? '会計' : '運用').'」の権限が必要です。', 403);
        }

        if ($proposal->expires_at->lte(CarbonImmutable::now())) {
            $this->close($proposal, AgentProposal::EXPIRED, null, null, 'system:agent-proposals');

            return null;
        }

        return $proposal;
    }

    /**
     * 承認に要る段階（保存した段階と、今の ShopWriter::TOOLS の段階の高い方）
     */
    public static function requiredLevel(AgentProposal $proposal): string
    {
        $now = ShopWriter::TOOLS[$proposal->tool]['level'] ?? 'money';

        return OperatorAccess::LEVELS[$now] > OperatorAccess::LEVELS[$proposal->level] ? $now : $proposal->level;
    }

    /**
     * 案を作った鍵が今も使えて、作った人がこの店で案に要る段階を今も持つか（中央の鍵と運営を読む。管理画面の文脈で、決めるための読み出し）
     */
    public static function creatorStillAllowed(AgentProposal $proposal): bool
    {
        $token = AgentToken::query()->find($proposal->token_id);
        $operator = Operator::query()->find($proposal->operator_id);
        $tenant = tenant()?->getTenantKey();

        if (! $token instanceof AgentToken || $token->revoked_at !== null || ! $operator instanceof Operator || ! $operator->isActive() || ! is_string($tenant)) {
            return false;
        }

        // 鍵がこの店に届くこと（管理画面で作った鍵はその店、OAuth の鍵は系統の店のどれか。ADR 0104）
        $covers = $token->oauth_grant_id === null ? $token->tenant_id === $tenant : in_array($tenant, AgentOAuthGrants::shopsOf($token->oauth_grant_id), true);

        if (! $covers) {
            return false;
        }

        $level = app(OperatorAccess::class)->levelFor($operator, $tenant);

        return $level !== null && OperatorAccess::LEVELS[$level] >= OperatorAccess::LEVELS[self::requiredLevel($proposal)];
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        ksort($value);

        return array_map(static fn (mixed $v): mixed => is_array($v) ? self::canonical($v) : $v, $value);
    }

    private static function expired(): AgentProposalRefused
    {
        return new AgentProposalRefused('この提案は期限（'.self::DAYS.' 日）が過ぎました。AI エージェントにもう一度依頼してください。');
    }

    /**
     * 決めた・反映した結果を書いてイベントを残す（ロックの中で呼ぶ）
     *
     * @param  array<string, mixed>|null  $result
     */
    private function close(AgentProposal $proposal, string $status, ?string $decidedBy, ?array $result, string $actor): AgentProposal
    {
        $proposal->forceFill(['status' => $status, 'decided_by' => $decidedBy, 'decided_at' => CarbonImmutable::now(), 'result' => $result])->save();
        $this->events->append('agent.proposal_'.$status, 'agent_proposal', $proposal->id, ['tool' => $proposal->tool] + ($result === null ? [] : self::ids($result)), $actor);

        return $proposal;
    }

    /**
     * 取消・返金の答えを書く（approved のまま結果を足すか、断られたら failed へ）
     *
     * @param  array<string, mixed>  $result
     */
    private function finish(string $id, array $result, string $actor): AgentProposal
    {
        return DB::transaction(function () use ($id, $result, $actor): AgentProposal {
            $this->events->lock();
            $proposal = AgentProposal::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $failed = isset($result['error']);
            $proposal->forceFill(['status' => $failed ? AgentProposal::FAILED : AgentProposal::APPROVED, 'result' => $result])->save();
            $type = $failed ? 'agent.proposal_failed' : (isset($result['checking']) ? 'agent.proposal_checking' : 'agent.proposal_applied');
            $this->events->append($type, 'agent_proposal', $proposal->id, ['tool' => $proposal->tool] + self::ids($result), $actor);

            return $proposal;
        });
    }

    /**
     * 記録に残してよい結果（ID・状態・額。断った理由の文は残さない）
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function ids(array $result): array
    {
        $out = [];

        foreach ($result as $key => $value) {
            if ($key === 'error') {
                $out['failed'] = true;
            } elseif (is_int($value) || is_bool($value) || (is_string($value) && preg_match('/\A[0-9a-z_]{1,32}\z/', $value) === 1)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            AgentProposal::PENDING => '承認待ち',
            AgentProposal::APPROVED => '承認・反映済み',
            AgentProposal::REJECTED => '却下',
            AgentProposal::EXPIRED => '期限切れ',
            default => '反映に失敗',
        };
    }
}
