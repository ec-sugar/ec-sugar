<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\Gift\GiftNote;
use App\Core\Plugin\Gift\GiftShare;

/**
 * 贈り物の App の口（ADR 0055 の 1）。核は「お届け先の入力を待つ受渡」と注文の入力の印だけを持ち、
 * 差出人とメッセージ・受け取りのリンク・受け取りの画面・期限は App が持つ。呼ばれるのは、この店で使っている時の店の文脈の中だけ
 */
interface GiftHandler
{
    /**
     * 注文の確定のトランザクションの中（連鎖のロックの後）で呼ぶ。差出人とメッセージを App の表に暗号化して置く。
     * 例外を投げると注文ごと巻き戻る
     */
    public function placed(string $transactionId, GiftNote $note, string $actor): void;

    /**
     * 払いが確かになった（0 円の注文も。注文の知らせのメールを送る前に核が呼ぶ）。受け取りのリンクを作り、期限を決める。
     * 何度呼ばれても 1 回だけ。贈り物でない注文では何もしない
     */
    public function paid(string $transactionId): void;

    /**
     * 買う人に見せる様子。贈り物でない注文は null。
     * $private が false の画面（App のタグを差し込む、注文を受け付けた画面）には、受け取りのリンク（url）を入れない
     */
    public function share(string $transactionId, bool $private): ?GiftShare;
}
