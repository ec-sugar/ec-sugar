<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\OrderDocument\OrderDocument;
use App\Core\Transaction\Models\Transaction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App が出す注文の書類（納品書・領収書など）の係。使っている店だけで呼ばれる。
 *
 * - 管理画面: 注文の画面の書類のボタンと、新しいタブの印刷用のページ。経路・ログイン・店の段階（運用）・見た記録（監査ログ）・
 *   注文を探すこと（店の中の物販の注文だけ、渡した順）・印刷の CSP は核が受け持ち、係は渡された注文でページを描くだけ
 * - お客さん（ご注文の確認とマイページの履歴の「領収書」など）は、この口でなく、ご注文の画面のリンクの口（OrderLinkProvider）で置く
 */
interface OrderDocumentHandler
{
    /**
     * 書類の種類（出す順）
     *
     * @return list<OrderDocument>
     */
    public function documents(): array;

    /**
     * 管理画面の印刷用のページ。GET は見る（発行の前の欄など）、POST は「発行する」を押したとき。
     * 印刷の CSP の nonce は PrintPage::nonce($request) で受け取る（核が先に付けている）。断るときも HTML のページで返す
     *
     * @param  list<Transaction>  $transactions  核が探した注文（書類が bulk でなければ 1 件）
     * @param  string  $actor  operator:<ID>（記録に残す）
     */
    public function staffPage(string $document, array $transactions, Request $request, string $actor): Response;
}
