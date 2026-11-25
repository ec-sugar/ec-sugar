<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Auth;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorEmailRecovery;
use App\Admin\Services\OperatorSessions;
use App\Admin\Services\SystemVersion;
use App\Core\IsolatedCopy;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SessionController
{
    public function show(Request $request): JsonResponse
    {
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);

        // 動いているバージョンはログインした運営にだけ見せる（メニューの下。ADR 0031）
        // 自分のパスキーの本数（1 本だけならホームで知らせる）
        return response()->json([
            'operator' => self::present($operator),
            'system' => SystemVersion::present(),
            'passkeys' => $operator->passkeySummary(),
            // メールでの戻しの待ち（ADR 0038）。心当たりが無ければホームから取り消せる
            'recovery_pending' => app(OperatorEmailRecovery::class)->pending($operator)?->utc()->format('Y-m-d\TH:i:s\Z'),
            // いつもと違う接続元から入った、今の画面でないセッションが生きている。心当たりが無ければホームから終える
            'unusual_session' => app(OperatorSessions::class)->unusualOther($operator, app(OperatorSessions::class)->current($request)),
            // 隔離した写し（ADR 0082）なら、画面の上に帯を出す（本番と取り違えないように）
            'isolated' => IsolatedCopy::enabled(),
            // 入っている App の名前（ヘルプが、入っていない App の説明のページを出さないため。公開用の 1.0 には定期便などが無い。ADR 0098）。
            // 店で使っているかではなく、この EC-SUGAR に入っているか（どの店でも同じ）
            'apps' => array_map(static fn (PluginDefinition $plugin): string => $plugin->name, app(PluginRegistry::class)->all()),
        ]);
    }

    /**
     * @return array{id: string, name: string, email: string, role: string}
     */
    public static function present(Operator $operator): array
    {
        return ['id' => $operator->id, 'name' => $operator->name, 'email' => $operator->email, 'role' => $operator->role];
    }
}
