<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Agent\AgentOAuthClients;
use App\Admin\Models\AgentOAuthRefreshToken;
use App\Admin\Models\AgentOAuthRequest;
use App\Admin\Models\AgentToken;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * AI エージェントの OAuth（ADR 0101）の使い終わった行を 1 時間ごとに消す（routes/console.php）。中央の表だけ。
 *
 * - 期限から 1 日を過ぎた許可の頼み（認可コードは 10 分で切れている）
 * - 期限から 1 日を過ぎたリフレッシュトークン（入れ替えた後の古いものを含む。使い回しを見つける期間は期限まで）
 * - 期限から EXPIRED_TOKEN_DAYS 日を過ぎた OAuth のアクセストークンの行（1 時間ごとに入れ替わるので溜まる。AI エージェントの案は作った鍵を
 *   承認の時に確かめるので、案の期限〔7 日〕より長く残す）
 * - 登録から 1 時間を過ぎて、頼みも系統も無いクライアント（登録は誰でもできるので、使われない登録で表を太らせず、全体の 1 日の枠を空ける）
 *
 * 許可の系統は消さない（取り消した系統も、誰がいつつないだかの記録として残す。系統の行は運営を消した時だけ一緒に消える）
 */
final class PruneAgentOAuthCommand extends Command
{
    /** 期限の切れた OAuth のアクセストークンの行を残す日数（AI エージェントの案の 7 日より長く） */
    public const int EXPIRED_TOKEN_DAYS = 8;

    protected $signature = 'agent:prune-oauth';

    protected $description = 'AI エージェントの OAuth の使い終わったリクエスト・鍵・使われないクライアントを消す';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $requests = AgentOAuthRequest::query()->where('expires_at', '<', $now->subDay())
            ->where(static fn ($q) => $q->whereNull('code_expires_at')->orWhere('code_expires_at', '<', $now->subDay()))->delete();
        $refresh = AgentOAuthRefreshToken::query()->where('expires_at', '<', $now->subDay())->delete();
        $tokens = AgentToken::query()->whereNotNull('oauth_grant_id')->where('expires_at', '<', $now->subDays(self::EXPIRED_TOKEN_DAYS))->delete();
        $clients = AgentOAuthClients::unused()->where('created_at', '<', $now->subMinutes(AgentOAuthClients::UNUSED_MINUTES))->delete();

        $this->info("OAuth のリクエスト {$requests} 件・リフレッシュトークン {$refresh} 件・期限の切れた鍵 {$tokens} 件・使われないクライアント {$clients} 件を削除しました");

        return self::SUCCESS;
    }
}
