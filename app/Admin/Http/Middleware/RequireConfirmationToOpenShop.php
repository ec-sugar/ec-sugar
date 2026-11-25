<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use App\Core\Shop\ShopStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ショップを公開にするときだけ、パスキーでの再確認を求める（運営の判断）。公開すると、だれでも見られて本番の鍵で注文を受ける。
 * 準備中に戻すのは閉じる側なので求めない。公開を求める送信かは送られた値で見る（送られた値が公開でなければ、サービスは公開にしない）
 */
final class RequireConfirmationToOpenShop
{
    public function __construct(private readonly RequireRecentConfirmation $confirmation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->input('status') !== ShopStatus::Open->value) {
            return $next($request);
        }

        return $this->confirmation->handle($request, $next);
    }
}
