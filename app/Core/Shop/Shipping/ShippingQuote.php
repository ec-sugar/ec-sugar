<?php

declare(strict_types=1);

namespace App\Core\Shop\Shipping;

use App\Core\Money\Money;

/**
 * カート・注文の送料（ADR 0064）。便ごとの送料（ShippingGroup）の集まり。labelled は店に配送の方法が 2 つ以上ある
 * （送料の行と見せ方に方法の名前を付ける）。estimate はお届け先をまだ知らない目安
 */
final readonly class ShippingQuote
{
    /**
     * @param  list<ShippingGroup>  $groups
     */
    public function __construct(
        public array $groups,
        public bool $labelled,
        public bool $estimate,
    ) {}

    public static function none(): self
    {
        return new self([], false, false);
    }

    public function fee(): int
    {
        return array_sum(array_map(static fn (ShippingGroup $g): int => $g->fee, $this->groups));
    }

    /**
     * 目安のいちばん高い額（目安でない・幅が無いなら null）
     */
    public function upTo(): ?int
    {
        $max = array_sum(array_map(static fn (ShippingGroup $g): int => $g->max, $this->groups));

        return $this->estimate && $max > $this->fee() ? $max : null;
    }

    /**
     * 送料無料のクーポン（ADR 0054）: すべての便を無料に
     */
    public function waived(): self
    {
        return new self(array_map(static fn (ShippingGroup $g): ShippingGroup => $g->waived(), $this->groups), $this->labelled, $this->estimate);
    }

    /**
     * 便に方法の名前を付けて見せるか（店に方法が 2 つ以上・便が 2 つ以上）
     */
    public function named(): bool
    {
        return $this->labelled || count($this->groups) > 1;
    }

    /**
     * 送料の行の名前（「送料」か「送料（クール便）」）
     */
    public function lineTitle(ShippingGroup $group): string
    {
        return $this->named() ? '送料（'.$group->name.'）' : '送料';
    }

    /**
     * 見せ方の添え書き（「通常便 ¥800・クール便 ¥1,200」「クール便」。名前を付けないなら null）
     */
    public function detail(): ?string
    {
        if (! $this->named() || $this->groups === []) {
            return null;
        }

        if (count($this->groups) === 1 || $this->upTo() !== null) {
            return implode('・', array_map(static fn (ShippingGroup $g): string => $g->name, $this->groups));
        }

        return implode('・', array_map(static fn (ShippingGroup $g): string => $g->name.' '.($g->fee === 0 ? '無料' : Money::format($g->fee)), $this->groups));
    }

    /**
     * あといくらで送料無料か（いちばん近い便。無ければ null）
     *
     * @return array{amount: int, name: string|null}|null
     */
    public function remainingForFree(): ?array
    {
        $best = null;

        foreach ($this->groups as $group) {
            if ($group->remaining !== null && ($best === null || $group->remaining < $best['amount'])) {
                $best = ['amount' => $group->remaining, 'name' => $this->named() ? $group->name : null];
            }
        }

        return $best;
    }

    /**
     * 確かめの画面の指紋に入れる形（便ごとの方法と額）
     */
    public function fingerprint(): string
    {
        return implode(',', array_map(static fn (ShippingGroup $g): string => ($g->methodId ?? '-').':'.$g->fee.':'.$g->basis.':'.implode('+', $g->keys), $this->groups));
    }
}
