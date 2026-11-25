<?php

declare(strict_types=1);

namespace App\Core\Transaction;

use App\Core\Transaction\Enums\PartyRole;
use App\Core\Transaction\Enums\Phase;

/**
 * 取引の種類の定義（拡張点「取引の種類」の Transaction 側、CONCEPT 6.3）。プラグインはこれを TransactionFlows に登録する。
 *
 * transitions は主フェーズ同士の遷移だけを書く（'quote' => [Phase::Agreement] のように）。
 * 同じフェーズに何度戻ってもよい（買取: 仮査定 → 合意 → 送付 → 本査定 → 合意 → 精算）。
 * 取消・紛争・返金は核の規則で、ここには書かない。
 */
final readonly class TransactionFlow
{
    /**
     * @param  array<string, list<Phase>>  $transitions  フェーズの値 => 次に進めるフェーズ
     * @param  list<Phase>  $completesAt  ここに入ったら取引は完了（status = completed）
     * @param  bool  $reservesInventory  出品者から顧客へ物が出る流れか（合意で在庫を確保し、受渡で出庫する）
     */
    public function __construct(
        public string $kind,
        public string $label,
        public Phase $initial,
        public array $transitions,
        public array $completesAt,
        public PartyRole $payer,
        public PartyRole $payee,
        public bool $reservesInventory,
    ) {}

    public function allows(Phase $from, Phase $to): bool
    {
        return in_array($to, $this->transitions[$from->value] ?? [], true);
    }

    public function completes(Phase $phase): bool
    {
        return in_array($phase, $this->completesAt, true);
    }
}
