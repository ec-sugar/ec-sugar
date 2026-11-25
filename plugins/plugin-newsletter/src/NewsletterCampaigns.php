<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

use App\Core\Event\EventStore;
use App\Core\Mail\SenderFacts;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginNewsletter\Mail\NewsletterMail;
use EcSugar\PluginNewsletter\Models\NewsletterCampaign;
use EcSugar\PluginNewsletter\Models\NewsletterDelivery;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 配信を書く・試す・配信する・予約する・止める（店の人の操作。管理画面の App の画面から）。配信の表を書くのはここと NewsletterDispatcher だけ。
 * どれも連鎖のロック（EventStore::lock()）の後にその行のロックを取り、同じトランザクションで記録（イベント）を残す
 * （newsletter.campaign_created・updated・deleted・tested・started・scheduled・unscheduled・stopped・finished）。
 * 記録の中身は ID・数・変えた欄の名前だけ（題と本文は表にあり、記録には「誰が何をしたか」だけを残す）。
 *
 * - 配信と予約は、送り主の表示（特商法の表記の販売業者・所在地・問い合わせ先）がそろっている時だけ（SenderFacts）
 * - 回数の上限: 配信を始める（予約を含む）のは店で 24 時間に STARTS_PER_DAY 回まで。配信は店に CAMPAIGNS_MAX 件まで
 */
final class NewsletterCampaigns
{
    public const int SUBJECT_MAX = 100;

    public const int BODY_MAX = 5000;

    public const int CAMPAIGNS_MAX = 500;

    public const int STARTS_PER_DAY = 5;

    /** 予約できるのは、今から 5 分の後から 90 日の内 */
    public const int SCHEDULE_MIN_MINUTES = 5;

    public const int SCHEDULE_MAX_DAYS = 90;

    /** 予約の日時の書き方（日本の時刻） */
    public const string TIME_FORMAT = 'Y-m-d H:i';

    public const string TIMEZONE = 'Asia/Tokyo';

    public function __construct(private readonly EventStore $events) {}

    /**
     * @param  array<string, bool|string|null>  $values  subject・body・scheduled_at（核が形を確かめた後）
     */
    public function create(array $values, string $actor): string
    {
        $row = self::row($values);

        return DB::transaction(function () use ($row, $actor): string {
            $this->events->lock();

            if (NewsletterCampaign::query()->count() >= self::CAMPAIGNS_MAX) {
                throw ValidationException::withMessages(['subject' => '配信は '.self::CAMPAIGNS_MAX.' 件までです。使わない下書きを削除してから追加してください。']);
            }

            $campaign = NewsletterCampaign::query()->create($row + ['status' => CampaignStatus::Draft]);
            $this->events->append('newsletter.campaign_created', 'newsletter_campaign', $campaign->id, ['scheduled' => $campaign->scheduled_at !== null], $actor);

            return $campaign->id;
        });
    }

    /**
     * 下書きだけを直せる
     *
     * @param  array<string, bool|string|null>  $values
     */
    public function update(string $id, array $values, string $actor): void
    {
        $row = self::row($values);

        DB::transaction(function () use ($id, $row, $actor): void {
            $this->events->lock();
            $campaign = self::draft($id);
            $changed = array_keys(array_filter($row, static fn (mixed $value, string $key): bool => self::differs($campaign->getAttribute($key), $value), ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                return;
            }

            $campaign->fill($row)->save();
            $this->events->append('newsletter.campaign_updated', 'newsletter_campaign', $campaign->id, ['changed' => $changed], $actor);
        });
    }

    public function delete(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $campaign = self::draft($id);
            $campaign->delete();
            $this->events->append('newsletter.campaign_deleted', 'newsletter_campaign', $campaign->id, [], $actor);
        });
    }

    /**
     * 試しに送る（宛先は操作した店の人のメール。本物と同じ文と表示で、題に【試し】。停止のリンクは働かない合い言葉）。
     * 送ってから記録を残す（送り口を待つ間、連鎖のロックを持たない）
     */
    public function test(string $id, string $email, string $actor): void
    {
        $campaign = NewsletterCampaign::query()->find($id) ?? throw new AdminRecordNotFound;
        $tenant = tenant();
        $sender = self::sender();

        if (! $tenant instanceof Tenant || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new AdminActionRefused('テスト送信の宛先（あなたのメールアドレス）がありません。');
        }

        try {
            Mail::to($email)->send(new NewsletterMail(
                (string) $tenant->getAttribute('name'),
                $campaign->subject,
                $campaign->body,
                $sender,
                (string) StorefrontUrls::of($tenant, '/p/newsletter/unsubscribe/'.NewsletterSubscribers::token()),
                test: true,
            ));
        } catch (Throwable $e) {
            report($e);

            throw new AdminActionRefused('テスト送信できませんでした。メールの設定を確認して、もう一度お試しください。');
        }

        DB::transaction(function () use ($campaign, $actor): void {
            $this->events->lock();
            $this->events->append('newsletter.campaign_tested', 'newsletter_campaign', $campaign->id, [], $actor);
        });
    }

    /**
     * 今すぐ配信を始める（届ける人へは、1 分ごとの配信の係が少しずつ送る）
     */
    public function sendNow(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $campaign = self::draft($id);
            self::sender();
            $this->guardStarts();
            $recipients = self::recipients();

            if ($recipients === 0) {
                throw new AdminActionRefused('お知らせのメールに登録している人がまだいません。');
            }

            $campaign->forceFill(['status' => CampaignStatus::Sending, 'started_at' => CarbonImmutable::now(), 'scheduled_at' => null])->save();
            $this->events->append('newsletter.campaign_started', 'newsletter_campaign', $campaign->id, ['recipients' => $recipients, 'scheduled' => false], $actor);
        });
    }

    /**
     * 下書きの予約の日時に配信するよう予約する
     */
    public function schedule(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $campaign = self::draft($id);
            $at = $campaign->scheduled_at;
            $now = CarbonImmutable::now();

            if ($at === null || $at->lt($now->addMinutes(self::SCHEDULE_MIN_MINUTES)) || $at->gt($now->addDays(self::SCHEDULE_MAX_DAYS))) {
                throw new AdminActionRefused('予約の日時は、今から '.self::SCHEDULE_MIN_MINUTES.' 分後〜'.self::SCHEDULE_MAX_DAYS.' 日後の間で、下書きを直して入れてください。');
            }

            self::sender();
            $this->guardStarts();
            $campaign->forceFill(['status' => CampaignStatus::Scheduled])->save();
            $this->events->append('newsletter.campaign_scheduled', 'newsletter_campaign', $campaign->id, ['scheduled_at' => $at->toIso8601String()], $actor);
        });
    }

    /**
     * 予約をやめて下書きに戻す（始まる前だけ）
     */
    public function unschedule(string $id, string $actor, string $reason = 'operator'): void
    {
        DB::transaction(function () use ($id, $actor, $reason): void {
            $this->events->lock();
            $campaign = NewsletterCampaign::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;

            if ($campaign->status !== CampaignStatus::Scheduled) {
                throw new AdminActionRefused('この配信は、もう予約ではありません。');
            }

            $campaign->forceFill(['status' => CampaignStatus::Draft])->save();
            $this->events->append('newsletter.campaign_unscheduled', 'newsletter_campaign', $campaign->id, ['reason' => $reason], $actor);
        });
    }

    /**
     * 配信の途中で止める（まだ送っていない分は送らない。送ったメールは戻せない）
     */
    public function stop(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $campaign = NewsletterCampaign::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;

            if ($campaign->status !== CampaignStatus::Sending) {
                throw new AdminActionRefused('この配信は、もう配信中ではありません。');
            }

            $now = CarbonImmutable::now();
            $skipped = NewsletterDelivery::query()->where('campaign_id', $campaign->id)->where('status', NewsletterDelivery::QUEUED)
                ->update(['status' => NewsletterDelivery::SKIPPED, 'updated_at' => $now]);
            $campaign->forceFill(['status' => CampaignStatus::Stopped, 'finished_at' => $now])->save();
            $this->events->append('newsletter.campaign_stopped', 'newsletter_campaign', $campaign->id, ['skipped' => $skipped, ...self::counts($campaign->id)], $actor);
        });
    }

    /**
     * 配信ごとの数（送った・失敗・止めた配信の分・この配信の停止のリンクで止めた人）
     *
     * @return array{sent: int, failed: int, skipped: int, waiting: int, unsubscribed: int}
     */
    public static function counts(string $campaignId): array
    {
        $row = DB::table('newsletter_deliveries')->where('campaign_id', $campaignId)
            ->selectRaw("count(*) FILTER (WHERE status = 'sent') AS sent")
            ->selectRaw("count(*) FILTER (WHERE status = 'failed') AS failed")
            ->selectRaw("count(*) FILTER (WHERE status = 'skipped') AS skipped")
            ->selectRaw("count(*) FILTER (WHERE status IN ('queued', 'sending')) AS waiting")
            ->selectRaw('count(unsubscribed_at) AS unsubscribed')
            ->first();

        return ['sent' => (int) ($row->sent ?? 0), 'failed' => (int) ($row->failed ?? 0), 'skipped' => (int) ($row->skipped ?? 0), 'waiting' => (int) ($row->waiting ?? 0), 'unsubscribed' => (int) ($row->unsubscribed ?? 0)];
    }

    /**
     * いま届ける人の数（確かめ済み）
     */
    public static function recipients(): int
    {
        return NewsletterSubscriber::query()->where('status', SubscriberStatus::Active->value)->count();
    }

    /**
     * 予約の日時の欄（日本の時刻の文）を読む。空は null、読めなければ ValidationException
     */
    public static function parseTime(?string $text): ?CarbonImmutable
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $at = preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}\z/', $text) === 1 ? CarbonImmutable::createFromFormat(self::TIME_FORMAT, $text, self::TIMEZONE) : null;

        if (! $at instanceof CarbonImmutable || $at->format(self::TIME_FORMAT) !== $text) {
            throw ValidationException::withMessages(['scheduled_at' => '予約の日時は「2026-11-20 09:00」の形で入れてください（日本の時刻）。']);
        }

        return $at->utc();
    }

    /**
     * 送り主の表示がそろっているか（足りなければ断る）
     */
    private static function sender(): SenderFacts
    {
        $sender = SenderFacts::current();
        $missing = $sender->missing();

        if ($missing !== []) {
            throw new AdminActionRefused('配信のメールには送り主の表示が要ります（特定電子メール法）。設定の「特商法の表記」に「'.implode('」「', $missing).'」を書いてから送ってください。');
        }

        return $sender;
    }

    /**
     * 配信を始める回数（予約を含む）の上限
     */
    private function guardStarts(): void
    {
        $since = CarbonImmutable::now()->subDay();
        $starts = NewsletterCampaign::query()
            ->where(static fn ($q) => $q->where('started_at', '>', $since)->orWhere('status', CampaignStatus::Scheduled->value))
            ->count();

        if ($starts >= self::STARTS_PER_DAY) {
            throw new AdminActionRefused('配信を始めるのは 24 時間に '.self::STARTS_PER_DAY.' 回までです（予約を含みます）。時間をおいてください。');
        }
    }

    /**
     * 直せる下書き（行のロックつき）
     */
    private static function draft(string $id): NewsletterCampaign
    {
        $campaign = NewsletterCampaign::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;

        if ($campaign->status !== CampaignStatus::Draft) {
            throw new AdminActionRefused('この配信は下書きではありません（すでに予約したか、配信しています）。');
        }

        return $campaign;
    }

    /**
     * 欄の値を表の行にする
     *
     * @param  array<string, bool|string|null>  $values
     * @return array{subject: string, body: string, scheduled_at: ?CarbonImmutable}
     */
    private static function row(array $values): array
    {
        $subject = trim((string) ($values['subject'] ?? ''));
        $body = trim((string) ($values['body'] ?? ''));

        if ($subject === '' || mb_strlen($subject) > self::SUBJECT_MAX) {
            throw ValidationException::withMessages(['subject' => '件名は 1〜'.self::SUBJECT_MAX.' 文字で入れてください。']);
        }

        if ($body === '' || mb_strlen($body) > self::BODY_MAX) {
            throw ValidationException::withMessages(['body' => '本文は 1〜'.self::BODY_MAX.' 文字で入れてください。']);
        }

        return ['subject' => $subject, 'body' => $body, 'scheduled_at' => self::parseTime(is_string($values['scheduled_at'] ?? null) ? $values['scheduled_at'] : null)];
    }

    private static function differs(mixed $current, mixed $next): bool
    {
        if ($current instanceof CarbonImmutable || $next instanceof CarbonImmutable) {
            return ! ($current instanceof CarbonImmutable && $next instanceof CarbonImmutable && $current->equalTo($next));
        }

        return $current !== $next;
    }
}
