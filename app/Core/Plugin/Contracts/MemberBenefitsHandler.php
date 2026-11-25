<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\MemberDiscount;

/**
 * 会員の特典の口（ADR 0079。App「会員ランク」が実装する）。店で 1 つの App だけ。
 * 核は「値引きの候補を 1 つ足す」「ポイントの率を上乗せする」「見せない商品を除く」だけを持ち、誰にどの特典があるかは App が決める。
 *
 * - discountFor・pointBonusBp・badgeFor は、この店で App を使っている時だけ呼ばれる（店の文脈の中）。渡す台帳は、ログインした会員の台帳（注文の確定では錠の中で）
 * - hiddenListings は App が入っていれば、使っていない時にも呼ばれる（$enabled が false）。使うのをやめた時に、会員だけの商品を誰にでも見せないため
 */
interface MemberBenefitsHandler
{
    /**
     * その会員の会員価格（商品の代金の割引。自動の割引・クーポンと同じ候補に入り、1 つの注文にいちばん得な 1 つが効く。ADR 0065）。無ければ null
     */
    public function discountFor(string $customerAccountId): ?MemberDiscount;

    /**
     * その会員のポイントの率の上乗せ（万分率。1% は 100。無ければ 0）。店がポイントを使っている時だけ、核が店の率に足す
     */
    public function pointBonusBp(string $customerAccountId): int;

    /**
     * 見る人に見せない商品の ID（ショップの一覧・検索・商品の画面・カート・注文の確定・フィード・サイトマップから除く）。
     * $customerAccountId はログインした会員の台帳（ゲスト・会員でない時は null）。$enabled は App を使っているか
     *
     * @return list<string>
     */
    public function hiddenListings(?string $customerAccountId, bool $enabled): array;

    /**
     * 管理画面のお客さんの画面に出す一言（ランクの名前など。20 文字まで）。無ければ null
     */
    public function badgeFor(string $customerAccountId): ?string;
}
