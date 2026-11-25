<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\OrderCharge\ChargeCart;
use App\Core\Plugin\OrderCharge\ChargeForm;
use App\Core\Plugin\OrderCharge\ChargeQuote;
use App\Core\Plugin\OrderCharge\ChargeRejected;

/**
 * App が注文に料金の行を足す口（ADR 0078。最初の使い手は App「ラッピング・のし」）。
 * 核は注文の入力に App の欄を出し（<x-storefront::charge-fields>）、選んだ値を App に見積もらせ、答えの行を調整の行（税込み・標準の率）として注文に足す。
 * 額はいつも App がサーバーの値（店の設定）で決め、核が形（種類・名前・額の範囲）を確かめる。お客さんの入力の額は使わない。
 * 呼ばれるのは、この店で使っている時の店の文脈の中だけ（店で 1 つの App だけ）
 */
interface OrderChargeHandler
{
    /**
     * 注文の入力に出す欄（このカートで選べるものが無ければ null）。選択肢と値段は店の今の設定から
     */
    public function form(ChargeCart $cart): ?ChargeForm;

    /**
     * 入れた値（欄の鍵 => 文字。核が形をそろえた後）を確かめて、注文に足す行と確かめの画面の要約を決める。
     * 何も選んでいなければ空の見積もり。選べない・形の違う値は ChargeRejected（欄の鍵 => 理由）
     *
     * @param  array<string, string>  $values
     *
     * @throws ChargeRejected
     */
    public function quote(ChargeCart $cart, array $values): ChargeQuote;

    /**
     * 注文の確定のトランザクションの中（連鎖のロックの後。行は注文に入った後）で呼ぶ。選んだ値を App の表に残す（個人情報は暗号化して）。
     * 例外を投げると注文ごと巻き戻る
     *
     * @param  array<string, string>  $values  quote で確かめた値
     */
    public function placed(string $transactionId, ChargeCart $cart, array $values, string $actor): void;

    /**
     * 注文の備考（納品書・配送の CSV・管理画面の個人情報の入口）。名前と値の文字だけ（HTML は返さない）。
     * $private が false の時は、個人情報（名入れの文字など）を入れない。足すものが無ければ空
     *
     * @return list<array{name: string, value: string}>
     */
    public function notes(string $transactionId, bool $private): array;
}
