<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Core\Plugin\Services\AdminPages;
use App\Core\Transaction\Models\Transaction;
use Illuminate\Http\JsonResponse;

/**
 * 注文の画面に出す App の 1 件（EnterTenant の後。App「ダウンロード販売」の回数と最後の日時など）。読むだけで、閲覧の段階から。
 * 形は App の画面の 1 件と同じで、個人情報の印の付いた事実と表は出さない（AdminPages::forOrder）。{transactionId} はモデルに結びつけず、テナント文脈の中で探す
 */
final class OrderAppController
{
    public function __construct(private readonly AdminPages $pages) {}

    public function index(string $transactionId): JsonResponse
    {
        $order = Transaction::query()->findOrFail($transactionId);

        return response()->json(['apps' => $order->kind === 'sale' ? $this->pages->forOrder($order) : []]);
    }
}
