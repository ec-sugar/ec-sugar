<?php

declare(strict_types=1);

namespace App\Core\Discount\Services;

/**
 * 1 回のカートの計算で、値引きを照らすのに要る数をまとめて読んだもの（ADR 0065）。
 * 割引ごとに数え直さないので、クエリの数が割引の数に比例しない（注文の確定は店全体の台帳のロックの中で走る）。
 * used は使った数（reserved と used）、usedBy は渡したお客さんの台帳（会員とメール）で使った数、categories は商品 => 入っているカテゴリ
 */
final readonly class DiscountContext
{
    /**
     * @param  array<string, int>  $used  割引の ID => 使った数
     * @param  array<string, int>  $usedBy  割引の ID => そのお客さんが使った数
     * @param  array<string, list<string>>  $categories  商品の ID => カテゴリの ID
     */
    public function __construct(
        public array $used,
        public array $usedBy,
        public array $categories,
    ) {}
}
