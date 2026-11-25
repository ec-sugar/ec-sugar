<?php

declare(strict_types=1);

namespace App\Providers;

use App\Admin\Models\Operator;
use App\Admin\Models\OperatorPasskey;
use App\Admin\Services\CentralMigrations;
use App\Admin\Services\Incidents;
use App\Core\Event\Outbox\EventReceiverFailed;
use App\Storefront\Access\ClientSource;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;

/**
 * 運営の管理画面（ADR 0013）。パスキーのパッケージを運営アカウント向けに差し替える。
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // パッケージのルートは使わない（ホスト・ミドルウェア・監査を自分で付けるため routes/admin.php に書く）
        Passkeys::ignoreRoutes();
    }

    public function boot(): void
    {
        Passkeys::useUserModel(Operator::class);
        Passkeys::usePasskeyModel(OperatorPasskey::class);

        // 壊れたことの記録（ADR 0043）: 裏の仕事の失敗（試し直しを使い切った後）と定期の処理の失敗。HTTP の 500 は bootstrap/app.php の報告の口から
        Event::listen(JobFailed::class, static fn (JobFailed $event) => Incidents::jobFailed($event));
        Event::listen(ScheduledTaskFailed::class, static fn (ScheduledTaskFailed $event) => Incidents::taskFailed($event));
        // 記録の受け手が落ちた・止めた（ADR 0089）。見回り（CLI）・裏の仕事の中でも数える
        Event::listen(EventReceiverFailed::class, static fn (EventReceiverFailed $event) => Incidents::receiverFailed($event));
        // 確かめ口（/up）: 中央の DB に流していないマイグレーションがあれば 500
        Event::listen(DiagnosingHealth::class, static fn () => app(CentralMigrations::class)->assertCurrent());

        // ログイン・登録・再確認の試行回数。接続元ごとに 1 分 10 回（総当たりと挑戦の乱発を抑える）。IPv6 は /64 にまとめて数える（第 2 回）
        RateLimiter::for('admin-auth', static fn (Request $request): Limit => Limit::perMinute(10)->by('admin-auth|'.ClientSource::of($request->ip())));
        // 写真のアップロード（作り直しが重い）。運営ごとに 1 分 30 枚まで
        RateLimiter::for('admin-uploads', static fn (Request $request): Limit => Limit::perMinute(30)->by('admin-uploads|'.($request->user('operator')?->getAuthIdentifier() ?? $request->ip())));
        // AI エージェントの OAuth（ADR 0101）。接続元の制限の外の受け口で、クラウドのクライアントは多くの人が同じ出口から来るので、ゆるめの大枠だけ
        // （細かい守りは受け口の中: 鍵の受け渡しと /mcp は断った回だけを /64 と /48 で数える・登録は /64 と /48 ごとの 1 時間と全体で 1 日の上限）。
        // /mcp は鍵ごとの上限（AuthenticateAgent）の外側の、接続元ごとの大枠（PR 186 の見直しの高 1）
        RateLimiter::for('agent-mcp', static fn (Request $request): Limit => Limit::perMinute(600)->by('agent-mcp|'.ClientSource::of($request->ip())));
        RateLimiter::for('agent-oauth', static fn (Request $request): Limit => Limit::perMinute(300)->by('agent-oauth|'.ClientSource::of($request->ip())));
        // 許可の頼み（運営のブラウザから。接続元の制限の中）。1 回で表に 1 行を作るので、接続元ごとに 1 分 30 回まで
        RateLimiter::for('agent-oauth-authorize', static fn (Request $request): Limit => Limit::perMinute(30)->by('agent-oauth-authorize|'.ClientSource::of($request->ip())));
        // 売るファイルの切れ端（8 MB ずつ。500 MB で 63 回）。写真の回数とは分ける
        RateLimiter::for('admin-file-chunks', static fn (Request $request): Limit => Limit::perMinute(240)->by('admin-file-chunks|'.($request->user('operator')?->getAuthIdentifier() ?? $request->ip())));
    }
}
