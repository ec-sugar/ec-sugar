<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

/**
 * お客さんに見せる注文のポイント（ADR 0068。テーマ API の一部。OrderView::$points）。
 * spent は使ったポイント、earn は付く予定の数、earned は付いた数。state は pending（付く予定）・earned（付いた）・revoked（返金で取り消した）・
 * cancelled（取消で付かない）・none（付かない注文）。inPerson は来店で受け渡す注文（予約の品）で、付く時の言い方を「ご来店の後に」にする
 */
final readonly class OrderPointsView
{
    public function __construct(
        public int $spent,
        public int $earn,
        public int $earned,
        public string $state,
        public bool $inPerson = false,
    ) {}

    /**
     * @param  array{spent: int, earn: int, earned: int, state: string}|null  $points  PointService::forOrder の答え
     */
    public static function of(?array $points, bool $inPerson = false): ?self
    {
        return $points === null ? null : new self($points['spent'], $points['earn'], $points['earned'], $points['state'], $inPerson);
    }

    /**
     * 一言（ご注文の状況・注文のメール）。言うことが無ければ null
     */
    public function note(): ?string
    {
        return match ($this->state) {
            'pending' => number_format($this->earn).' ポイントが付く予定です（'.($this->inPerson ? 'ご来店の後に' : '発送時に').'付きます）',
            'earned' => number_format($this->earned).' ポイントが付きました',
            'revoked' => 'ご返金のため、このご注文のポイントは取り消しました',
            default => null,
        };
    }
}
