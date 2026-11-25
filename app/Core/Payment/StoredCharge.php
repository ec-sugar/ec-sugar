<?php

declare(strict_types=1);

namespace App\Core\Payment;

/**
 * 預けたカードでの請求の答え（ADR 0045 の 1）。状態は 6 つに正規化する（見直し 2026-11-01 の中 2・3）:
 * - succeeded・processing: 通った・途中（確定は Webhook）
 * - requires_action: カード会社が本人確認を求めた（お客さんに確かめてもらう。ref に支払いの ID）
 * - failed: カードが断られた（failureCode に断りの符号。resource_missing はカードか預け先が無い → 登録し直しが要る）
 * - unknown: 答えが分からない（通信の失敗・429・5xx・読めない答え）。24 時間のうちに同じ冪等キーで頼み直せば、Stripe は同じ請求を返す
 * - rejected: 店の設定の誤り（鍵の失効・連結の取り消し）か作りの誤り（同じ冪等キーで違う中身）。お客さんの失敗に数えず、人に知らせる
 * 失敗の理由は符号だけ（個人情報や文は持たない）。確定は Webhook（この答えは「途中」の記録に使うだけ）
 */
final readonly class StoredCharge
{
    public const array STATUSES = ['succeeded', 'processing', 'requires_action', 'failed', 'unknown', 'rejected'];

    public function __construct(
        public ?string $ref,
        public string $status,
        public ?string $failureCode = null,
    ) {}
}
