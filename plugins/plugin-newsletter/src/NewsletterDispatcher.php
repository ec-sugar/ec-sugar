<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

use App\Core\Event\EventStore;
use App\Core\Mail\SenderFacts;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\Services\PluginSettings;
use Carbon\CarbonImmutable;
use EcSugar\PluginNewsletter\Jobs\SendCampaignMail;
use EcSugar\PluginNewsletter\Models\NewsletterCampaign;
use EcSugar\PluginNewsletter\Models\NewsletterDelivery;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 1 分ごとの配信の係（newsletter:run が店ごとに呼ぶ。actor は system:newsletter）。
 *
 * 1. 予約の日時が来た配信を始める（送り主の表示が足りなければ下書きに戻す）
 * 2. 配信中の配信から、まだ送っていない届ける人を、1 分の上限（設定の「1 分に送る数」）まで選び、送った記録を作ってキューに積む。
 *    上限はまだ終わっていない仕事の数も引いて数える（キューが遅れても積み増さない）。届ける人は、配信を始めた時までに確かめた人だけ
 * 3. 送る人も送っている仕事も無くなった配信を「配信済み」にする
 * 4. 積んでから 1 時間を過ぎても終わらない仕事は「失敗」にする（キューが失った仕事。送り直さない＝2 通にしない）
 *
 * 送った記録（newsletter_deliveries）は「送ったかどうか」の印で、お金・在庫に触れず、記録（イベント）にもしない（conventions.md の 3 の例外。
 * 核の送ったメールの記録 sent_mails と同じ扱い）。配信の状態を変える時だけ、連鎖のロックとイベント
 */
final class NewsletterDispatcher
{
    public const string ACTOR = 'system:newsletter';

    public const int PER_MINUTE_DEFAULT = 10;

    public const int LOST_MINUTES = 60;

    public function __construct(
        private readonly EventStore $events,
        private readonly NewsletterCampaigns $campaigns,
        private readonly PluginSettings $settings,
    ) {}

    /**
     * @return array{started: int, queued: int, finished: int, lost: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $started = $this->startDue($now);
        $lost = NewsletterDelivery::query()->whereIn('status', [NewsletterDelivery::QUEUED, NewsletterDelivery::SENDING])
            ->where('updated_at', '<', $now->subMinutes(self::LOST_MINUTES))
            ->update(['status' => NewsletterDelivery::FAILED, 'updated_at' => $now]);
        $queued = $this->pump($this->perMinute());
        $finished = $this->finish($now);

        return ['started' => $started, 'queued' => $queued, 'finished' => $finished, 'lost' => $lost];
    }

    /**
     * 設定の「1 分に送る数」（m10 → 10）
     */
    public function perMinute(): int
    {
        $value = $this->settings->publicValues('newsletter')['per_minute'] ?? null;
        $number = is_string($value) && preg_match('/\Am(\d{1,3})\z/', $value, $m) === 1 ? (int) $m[1] : self::PER_MINUTE_DEFAULT;

        return max(1, min(120, $number));
    }

    private function startDue(CarbonImmutable $now): int
    {
        $count = 0;

        foreach (NewsletterCampaign::query()->where('status', CampaignStatus::Scheduled->value)->where('scheduled_at', '<=', $now)->orderBy('scheduled_at')->pluck('id') as $id) {
            // 送り主の表示が足りない: 法の表示の無いメールは送らないので、下書きに戻す（ホームの「やること」に出る）
            if (SenderFacts::current()->missing() !== []) {
                try {
                    $this->campaigns->unschedule((string) $id, self::ACTOR, 'sender_missing');
                } catch (AdminActionRefused) {
                    // 同じ時に店の人が予約をやめた
                }

                continue;
            }

            $count += DB::transaction(function () use ($id, $now): int {
                $this->events->lock();
                $campaign = NewsletterCampaign::query()->whereKey($id)->lockForUpdate()->first();

                if ($campaign?->status !== CampaignStatus::Scheduled) {
                    return 0;
                }

                $campaign->forceFill(['status' => CampaignStatus::Sending, 'started_at' => $now])->save();
                $this->events->append('newsletter.campaign_started', 'newsletter_campaign', $campaign->id, ['recipients' => NewsletterCampaigns::recipients(), 'scheduled' => true], self::ACTOR);

                return 1;
            });
        }

        return $count;
    }

    /**
     * まだ送っていない届ける人を、上限まで積む
     */
    private function pump(int $perMinute): int
    {
        $budget = $perMinute - NewsletterDelivery::query()->whereIn('status', [NewsletterDelivery::QUEUED, NewsletterDelivery::SENDING])->count();

        if ($budget <= 0 || SenderFacts::current()->missing() !== []) {
            return 0;
        }

        $queued = 0;

        foreach (NewsletterCampaign::query()->where('status', CampaignStatus::Sending->value)->orderBy('started_at')->get() as $campaign) {
            if ($budget <= 0) {
                break;
            }

            $ids = NewsletterSubscriber::query()
                ->where('status', SubscriberStatus::Active->value)
                ->where('confirmed_at', '<=', $campaign->started_at)
                ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from('newsletter_deliveries')
                    ->where('newsletter_deliveries.campaign_id', $campaign->id)
                    ->whereColumn('newsletter_deliveries.subscriber_id', 'newsletter_subscribers.id'))
                ->orderBy('id')
                ->limit($budget)
                ->pluck('id');

            if ($ids->isEmpty()) {
                continue;
            }

            $now = CarbonImmutable::now();
            $rows = $ids->map(static fn (string $subscriberId): array => [
                'id' => strtolower((string) Str::ulid()), 'campaign_id' => $campaign->id, 'subscriber_id' => $subscriberId,
                'status' => NewsletterDelivery::QUEUED, 'created_at' => $now, 'updated_at' => $now,
            ])->all();

            try {
                NewsletterDelivery::query()->insert($rows);
            } catch (UniqueConstraintViolationException) {
                // 同じ時に動いたほかの係が先に積んだ（次の 1 分で続きを積む）
                continue;
            }

            foreach ($rows as $row) {
                SendCampaignMail::dispatch($row['id']);
            }

            $queued += count($rows);
            $budget -= count($rows);
        }

        return $queued;
    }

    /**
     * 送る人も仕事も残っていない配信を「配信済み」にする
     */
    private function finish(CarbonImmutable $now): int
    {
        $count = 0;

        foreach (NewsletterCampaign::query()->where('status', CampaignStatus::Sending->value)->get() as $campaign) {
            $left = NewsletterSubscriber::query()
                ->where('status', SubscriberStatus::Active->value)
                ->where('confirmed_at', '<=', $campaign->started_at)
                ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from('newsletter_deliveries')
                    ->where('newsletter_deliveries.campaign_id', $campaign->id)
                    ->whereColumn('newsletter_deliveries.subscriber_id', 'newsletter_subscribers.id'))
                ->exists();

            if ($left || NewsletterCampaigns::counts($campaign->id)['waiting'] > 0) {
                continue;
            }

            $count += DB::transaction(function () use ($campaign, $now): int {
                $this->events->lock();
                $row = NewsletterCampaign::query()->whereKey($campaign->id)->lockForUpdate()->first();

                if ($row?->status !== CampaignStatus::Sending) {
                    return 0;
                }

                $row->forceFill(['status' => CampaignStatus::Sent, 'finished_at' => $now])->save();
                $this->events->append('newsletter.campaign_finished', 'newsletter_campaign', $row->id, NewsletterCampaigns::counts($row->id), self::ACTOR);

                return 1;
            });
        }

        return $count;
    }
}
