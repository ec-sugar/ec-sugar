<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

use App\Core\Plugin\Contracts\LoginEntryProvider;
use App\Core\Plugin\LoginEntry;
use EcSugar\PluginLine\Http\LineController;
use EcSugar\PluginLine\Models\LineLink;

/**
 * 会員のログインの画面の「LINE でログイン」（核の口。ADR 0070）。LINE ログインの ID とシークレットがそろった時だけ出す。
 * 途中の値（LINE の認可の state・確かめ待ちの LINE）は、核がログインの画面を開いた時とログインさせた時に消す。
 * つないでいる会員には、マイページの見出しとメールの変更の知らせに「LINE をつないでいます」を出す
 */
final class LineLoginEntry implements LoginEntryProvider
{
    public function loginEntry(): ?LoginEntry
    {
        return app(LineSettings::class)->login() === null ? null : new LoginEntry(LineSettings::PLUGIN, 'LINE でログイン', '/p/line/login');
    }

    public function sessionKeys(): array
    {
        return [LineLogin::SESSION, LineController::PENDING];
    }

    public function linkedLabel(string $accountId): ?string
    {
        return LineLink::query()->where('customer_account_id', $accountId)->exists() ? 'LINE と連携しています' : null;
    }
}
