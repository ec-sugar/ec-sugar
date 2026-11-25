<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks\Jobs;

use App\Core\Party\Models\CustomerAccount;
use App\Core\Points\Services\PointSettings;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginMemberRanks\Mail\RankUpMail;
use EcSugar\PluginMemberRanks\Models\RankChange;
use EcSugar\PluginMemberRanks\Ranks;
use EcSugar\PluginMemberRanks\RankSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ランクが上がった知らせを 1 通送る（キュー。店の文脈は stancl/tenancy がつなぐ。ADR 0079）。仕事に載せるのは変わった記録の ID だけで、
 * 宛先は送る時に会員の台帳の暗号化された値から読む（キューにも失敗の記録にも平文のメールを残さない。ADR 0010）。
 *
 * - 取る: queued の記録だけを 1 文の条件つきの更新で sent にしてから送る（同じ記録の仕事が 2 つ動いても 1 通。送れなかったら failed）。試し直さない
 * - 送る前に確かめ直す: App を使っている・知らせる設定・会員のまま・今のランクがその記録のランク（送る前にまた変わっていれば送らない）。違えば skipped
 */
final class SendRankNotice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $changeId) {}

    public function handle(RankSettings $settings, Ranks $ranks, PointSettings $points): void
    {
        $tenant = tenant();
        $change = RankChange::query()->whereKey($this->changeId)->where('notice', 'queued')->first();

        if (! $tenant instanceof Tenant || $change === null) {
            return;
        }

        $account = CustomerAccount::query()->find($change->customer_account_id);
        $rank = $settings->ranks()[$change->to_rank] ?? null;
        $email = $account?->email;

        if (! $settings->enabled() || ! $settings->notify() || ! $account instanceof CustomerAccount || ! Ranks::eligible($account) || $rank === null
            || $ranks->current($account->id) !== $change->to_rank || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            RankChange::query()->whereKey($change->id)->where('notice', 'queued')->update(['notice' => 'skipped', 'noticed_at' => CarbonImmutable::now()]);

            return;
        }

        $claimed = RankChange::query()->whereKey($change->id)->where('notice', 'queued')->update(['notice' => 'sent', 'noticed_at' => CarbonImmutable::now()]);

        if ($claimed !== 1) {
            return;
        }

        try {
            Mail::to($email)->send(new RankUpMail((string) $tenant->getAttribute('name'), $rank->name, $rank->benefits($points->enabled()), (string) StorefrontUrls::of($tenant, '/p/member-ranks/account')));
        } catch (Throwable $e) {
            // 失敗の記録は例外の種類だけ（送る仕組みの例外の文には宛先が入りうる。ADR 0010）
            Log::warning('ランクの通知を送れなかった', ['change' => $change->id, 'error' => $e::class]);
            RankChange::query()->whereKey($change->id)->where('notice', 'sent')->update(['notice' => 'failed']);
        }
    }
}
