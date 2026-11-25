<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * お客さんが「読む権利（鍵）」を持っているかを答える口。有料の記事・会員だけのコンテンツを出すかの判断に使う。
 *
 * 核は、記事が要求する鍵の一覧を、この口を持つ App 全部（その店で使っている分だけ）に聞き、**1 つでも真なら通す**。
 * これで売り方（買い切り・月額・会員ランク）が増えても、見せる側（お知らせ・ブログなど）は売り方を知らないままでいられる
 * （App 同士を直につながない。`StoredCardHolder`〔ADR 0080〕と同じ型）。
 *
 * 鍵は App が決める文字列で、`<種類>:<名前>` の形にする（例 `membership:standard`・`post:01J…`）。
 * 核は中身を解釈せず、文字が合うかだけを見る。
 *
 * **ここで真を返すことは「本文を送ってよい」という意味**なので、答える側は期限・解約・返金を必ず見ること
 * （購読が切れた翌日・返金した後は偽）。迷ったら偽を返す（送らない方へ倒す）。
 */
interface ContentAccessProvider
{
    /**
     * そのお客さんが持っている鍵（頼まれた鍵のうち）。持っていなければ空の配列。
     *
     * $accountId はお客さんの台帳の ID。ログインしていない人には呼ばれない。
     * 頼まれていない鍵を返しても核は捨てる（App の誤りで別のものを開けない）
     *
     * @param  list<string>  $keys  記事が要求する鍵（1 つでも持っていれば読める）
     * @return list<string> 持っている鍵
     */
    public function held(string $accountId, array $keys): array;
}
