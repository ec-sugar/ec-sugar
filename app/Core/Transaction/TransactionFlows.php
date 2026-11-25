<?php

declare(strict_types=1);

namespace App\Core\Transaction;

use App\Core\Transaction\Enums\PartyRole;
use App\Core\Transaction\Enums\Phase;
use InvalidArgumentException;

/**
 * 取引の種類のレジストリ。核が登録するのは即時物販（sale）だけ。
 * 買取・定期・仕事などはそれぞれのプラグインが登録する（ListingKinds と対）。
 */
final class TransactionFlows
{
    /** @var array<string, TransactionFlow> */
    private array $flows = [];

    public function register(TransactionFlow $flow): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', $flow->kind) !== 1) {
            throw new InvalidArgumentException("取引の種類名が不正です: {$flow->kind}");
        }

        if (isset($this->flows[$flow->kind])) {
            throw new InvalidArgumentException("取引の種類が二重に登録されています: {$flow->kind}");
        }

        if ($flow->initial->isCrossCutting() || $flow->payer === $flow->payee) {
            throw new InvalidArgumentException("取引の種類 {$flow->kind} の定義が不正です（開始フェーズか、支払う側と受け取る側）");
        }

        foreach ($flow->transitions as $from => $targets) {
            $phase = Phase::tryFrom($from);

            if ($phase === null || $phase->isCrossCutting()) {
                throw new InvalidArgumentException("取引の種類 {$flow->kind} に主フェーズ以外からの遷移があります: {$from}");
            }

            foreach ($targets as $to) {
                if ($to->isCrossCutting()) {
                    throw new InvalidArgumentException("横断フェーズ（{$to->value}）への遷移は核が扱います");
                }
            }
        }

        $this->flows[$flow->kind] = $flow;
    }

    public function has(string $kind): bool
    {
        return isset($this->flows[$kind]);
    }

    public function get(string $kind): TransactionFlow
    {
        return $this->flows[$kind] ?? throw new InvalidArgumentException("未登録の取引の種類です: {$kind}");
    }

    /**
     * 即時物販: Agreement → Payment → Fulfillment → Acceptance → Settlement（CONCEPT 5.4）。
     */
    public static function sale(): TransactionFlow
    {
        return new TransactionFlow(
            kind: 'sale',
            label: '物販',
            initial: Phase::Agreement,
            transitions: [
                Phase::Agreement->value => [Phase::Payment],
                Phase::Payment->value => [Phase::Fulfillment],
                Phase::Fulfillment->value => [Phase::Acceptance],
                Phase::Acceptance->value => [Phase::Settlement],
            ],
            completesAt: [Phase::Settlement],
            payer: PartyRole::Customer,
            payee: PartyRole::Seller,
            reservesInventory: true,
        );
    }
}
