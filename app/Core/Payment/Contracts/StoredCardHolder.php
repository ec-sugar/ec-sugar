<?php

declare(strict_types=1);

namespace App\Core\Payment\Contracts;

/**
 * 預けたカードを、まだ使う App（ADR 0080）。お客さんの使い終わったカードを外す前に、ほかの App がまだ請求に使うかを聞く
 * （定期便が全部終わっても、抽選の申し込みの結果を待つ間はカードを外さない。逆も同じ）。退会では聞かない（いつも外す）。
 * App はサービスプロバイダで、コンテナの札 TAG を付けて登録する
 */
interface StoredCardHolder
{
    public const string TAG = 'ec-sugar.stored-card-holders';

    /**
     * そのお客さんの預けたカードを、この App がまだ請求に使うか（会員でなくなったお客さんには偽を返してよい）
     */
    public function holds(string $accountId): bool;
}
