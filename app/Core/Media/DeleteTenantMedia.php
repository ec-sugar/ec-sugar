<?php

declare(strict_types=1);

namespace App\Core\Media;

use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * テナントを消したら、そのテナントの写真の置き場（media の tenants/{id}）と売るファイルの置き場（downloads）も消す。DB と一緒に消し残しを作らない。
 * 消す前に、置き場がこの環境のものかを確かめる（MediaRoot）。
 */
final class DeleteTenantMedia
{
    public function __construct(private readonly TenantWithDatabase $tenant) {}

    public function handle(): void
    {
        $id = (string) $this->tenant->getTenantKey();

        // テナントの ID は作るときに検査済みだが、念のため置き場の外を指せない形だけを通す
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $id) === 1) {
            // 置き場が別の環境のもの（テストが開発の置き場を指しているなど）なら、同じ ID の別の店の写真を消さずに断る
            MediaRoot::current()->ensureOwned();
            Storage::disk('media')->deleteDirectory("tenants/{$id}");
            // 売るファイルの置き場（写真の置き場の名前から決まる隣の場所なので、上の札の確かめが同じ環境かを見ている）と、上げる途中のファイル
            Storage::disk('downloads')->deleteDirectory("tenants/{$id}");
            Storage::disk('downloads')->deleteDirectory("uploads/{$id}");
        }
    }
}
