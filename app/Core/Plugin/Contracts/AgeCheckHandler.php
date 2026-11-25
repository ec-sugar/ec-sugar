<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use Illuminate\Http\Request;

/**
 * 年齢の確かめの App の口（ADR 0077）。核は「年齢の確かめが要る品のある注文は、生年月日か会員が前に確かめた年齢と同意がそろわなければ確定しない」
 * 「注文に確かめた事実を残す」だけを持ち、商品ごとの年齢・会員が確かめた年齢・年齢の門は App が持つ。
 * 呼ばれるのは、この店で使っている時の店の文脈の中だけ（店で 1 つの App だけ）
 */
interface AgeCheckHandler
{
    /**
     * 年齢の確かめが要る商品の年齢（AgeRule::AGES のどれか）。要らない商品は入れない
     *
     * @param  list<string>  $listingIds
     * @return array<string, int> 商品の ID => 年齢
     */
    public function requirements(array $listingIds): array;

    /**
     * 会員が前の注文で確かめた年齢（AgeRule::AGES のどれか。無ければ 0）。年は経っても減らないので、これ以下の品では生年月日を聞かない
     */
    public function remembered(string $customerAccountId): int;

    /**
     * 注文の確定のトランザクションの中（連鎖のロックの後）で呼ぶ。会員が生年月日で確かめた年齢を覚える（今より上の時だけ上げる）
     */
    public function remember(string $customerAccountId, int $age, string $actor): void;

    /**
     * 商品の画面の最初の表示で「◯ 歳以上ですか」の画面（年齢の門）へ送るなら、その URL（店の設定で出さない・もう答えた人は null）。
     * 答えは Cookie だけに置き、記録は残さない
     */
    public function gate(int $age, string $returnPath, Request $request): ?string;
}
