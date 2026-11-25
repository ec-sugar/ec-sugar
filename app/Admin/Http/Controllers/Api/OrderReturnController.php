<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Presenters\TransactionPresenter;
use App\Admin\Http\Requests\ReturnOrderRequest;
use App\Admin\Http\TenantActor;
use App\Core\Order\Services\OrderReturns;
use App\Core\Transaction\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 注文の返品・一部返金・済んだ注文の返金と、断られた返金のやり直し（ADR 0048。EnterTenant の後）。
 * 経路はお金の段階（ADR 0024）。確かめの画面（preview）は読むだけ、実行とやり直しはお金が動くので直前の再確認も（経路の RequireRecentConfirmation）。
 * 経路の ID はモデルに結びつけない（店の文脈の中で探す。ほかの店の取引は見つからない）
 */
final class OrderReturnController
{
    public function __construct(
        private readonly OrderReturns $returns,
        private readonly TransactionPresenter $presenter,
    ) {}

    /**
     * 確かめの画面: 返す行・税率ごとの額と消費税・合計（書き込まない）
     */
    public function preview(ReturnOrderRequest $request, string $transactionId): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($transactionId);

        return response()->json(['plan' => $this->returns->preview($transaction, $request->toReturnRequest())->toArray()]);
    }

    /**
     * 返金する（確かめの画面の合計を照らす）。断られたら 409 と見せてよい文（返金の 1 回は残り、やり直せる）
     */
    public function store(ReturnOrderRequest $request, string $transactionId): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($transactionId);
        $this->returns->record($transaction, $request->toReturnRequest(), $request->expectedTotal(), TenantActor::of($request));

        return response()->json(['transaction' => $this->presenter->present(Transaction::query()->findOrFail($transaction->id))]);
    }

    /**
     * 断られた返金を頼み直す（新しい冪等キー）。返品の返金は「外で返した（記録だけ）」にもできる
     */
    public function retry(Request $request, string $transactionId, string $refundId): JsonResponse
    {
        $input = $request->validate(['channel' => ['required', 'string', 'in:provider,outside']], [], ['channel' => '返金の方法']);
        $transaction = Transaction::query()->findOrFail($transactionId);
        /** @var 'provider'|'outside' $channel */
        $channel = $input['channel'];
        $this->returns->retry($transaction, strtolower($refundId), $channel, TenantActor::of($request));

        return response()->json(['transaction' => $this->presenter->present(Transaction::query()->findOrFail($transaction->id))]);
    }
}
