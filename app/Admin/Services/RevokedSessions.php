<?php

declare(strict_types=1);

namespace App\Admin\Services;

use Illuminate\Support\Facades\Cache;

/**
 * ログアウトしたセッションの番号を覚えておく。
 *
 * Laravel のセッションは「リクエストの最初に読み、最後に書く」作りで鍵をかけない。ログアウトの前に始まった別のリクエストが
 * ログアウトの後に終わると、消したはずのセッション（ログイン済み）を書き戻すことがある。番号を取り消し済みにしておけば、
 * 書き戻されても EnsureOperatorIsActive が締め出す。覚えるのはセッションの最長の期間（admin.session_max_hours）だけ
 */
final class RevokedSessions
{
    public function revoke(string $sessionId): void
    {
        Cache::put($this->key($sessionId), true, now()->addHours(max(1, (int) config('admin.session_max_hours'))));
    }

    public function isRevoked(string $sessionId): bool
    {
        return Cache::has($this->key($sessionId));
    }

    private function key(string $sessionId): string
    {
        // 番号そのものは置かない（キャッシュが見えてもセッションを名乗れない）
        return 'admin-revoked-session:'.hash('sha256', $sessionId);
    }
}
