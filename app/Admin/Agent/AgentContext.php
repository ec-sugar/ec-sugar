<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Admin\Services\OperatorAccess;

/**
 * AI エージェントの 1 回の要求の文脈（ADR 0100・0101・0104）。AuthenticateAgent が鍵を確かめた後に、コンテナへ置く。
 * 道具はここから段階と鍵の種類（読むだけ・書ける）を読む（段階を引数で選ばせない）。
 *
 * - shops: この鍵が届く店と、店ごとの今の段階（管理画面で作った鍵は 1 店、OAuth の鍵は許可で選んだ店。要求ごとに引き直す）
 * - tenantId: この要求で入った店（AuthenticateAgent が shops の中から選んで、その店の文脈に入る）。
 *   複数の店の鍵で、道具の呼び出しに shop が無い・届かない店を指した時と、道具の一覧などの店に入らない要求は null
 * - level: 入った店の段階。店に入っていない時は、届く店の中のいちばん高い段階（道具を一覧に出すかを決めるだけ。
 *   道具を動かすのは店に入った時だけなので、ほかの店の段階で動くことはない）
 */
final readonly class AgentContext
{
    /**
     * @param  array<string, string>  $shops  店の ID → 今の段階
     */
    public function __construct(
        public string $tokenId,
        public string $operatorId,
        public ?string $tenantId,
        public string $level,
        public string $scope = AgentTokens::READ,
        // OAuth で出した鍵なら、その許可の系統（ADR 0101。1 時間ごとに鍵が入れ替わるので、回数とおかしな動きは系統で数える）
        public ?string $grantId = null,
        public array $shops = [],
    ) {}

    /**
     * 回数の上限とおかしな動きを数える単位: OAuth の鍵は許可の系統、管理画面で作った鍵は鍵そのもの
     * （リフレッシュのたびに新しい鍵になるので、鍵ごとに数えると上限を割られる。PR 186 の見直しの中 2）
     */
    public function limitKey(): string
    {
        return $this->grantId !== null ? 'grant:'.$this->grantId : $this->tokenId;
    }

    public function allows(string $required): bool
    {
        return OperatorAccess::LEVELS[$this->level] >= OperatorAccess::LEVELS[$required];
    }

    /**
     * 書く道具を使える鍵か（書ける鍵で、今の段階がその道具に足りる）
     */
    public function canWrite(string $required): bool
    {
        return $this->scope === AgentTokens::WRITE && $this->allows($required);
    }

    /**
     * この要求で店に入ったか
     */
    public function inShop(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * 届く店（店の ID → 段階）。shops を渡さずに作った文脈は、入った店だけ
     *
     * @return array<string, string>
     */
    public function reachableShops(): array
    {
        if ($this->shops !== []) {
            return $this->shops;
        }

        return $this->tenantId === null ? [] : [$this->tenantId => $this->level];
    }

    /**
     * 変更の記録の actor（`agent:<鍵の ID>`。管理画面の `operator:<ID>` と見分ける。誰の鍵かは中央の鍵の表と監査ログに。
     * TenantActor::of は EnterTenant を通った要求だけなので使わない）
     */
    public function actor(): string
    {
        return 'agent:'.$this->tokenId;
    }
}
