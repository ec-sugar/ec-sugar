<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter\Jobs;

use App\Core\Mail\SenderFacts;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginNewsletter\CampaignStatus;
use EcSugar\PluginNewsletter\Mail\NewsletterMail;
use EcSugar\PluginNewsletter\Models\NewsletterCampaign;
use EcSugar\PluginNewsletter\Models\NewsletterDelivery;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use EcSugar\PluginNewsletter\NewsletterSubscribers;
use EcSugar\PluginNewsletter\SubscriberStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 配信のメールを 1 通送る（キュー。店の文脈は stancl/tenancy がつなぐ）。仕事に載せるのは送った記録の ID だけで、宛先は仕事の中で店の DB から読む。
 *
 * - 2 回送らない: 「積んだ → 送っている」を 1 文の条件つきの更新で取る（取れなければ、ほかの仕事が送っている・送った）。試し直しはしない（tries = 1）。
 *   送り口が断ったら「失敗」にして、送り直さない（送れたのに断りに見えた時に 2 通にしない）
 * - 送る前に、配信が配信中か・その人が今も届ける人かを確かめる（止めた配信・止めた人には送らない。skipped）
 * - 停止のリンクの合い言葉は、送る時に作ってハッシュだけを記録に置く（メールの中にだけ合い言葉がある）
 */
final class SendCampaignMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $claimed = NewsletterDelivery::query()->whereKey($this->deliveryId)->where('status', NewsletterDelivery::QUEUED)
            ->update(['status' => NewsletterDelivery::SENDING, 'updated_at' => CarbonImmutable::now()]);

        if ($claimed !== 1) {
            return;
        }

        $delivery = NewsletterDelivery::query()->findOrFail($this->deliveryId);
        $campaign = NewsletterCampaign::query()->find($delivery->campaign_id);
        $subscriber = NewsletterSubscriber::query()->find($delivery->subscriber_id);
        $sender = SenderFacts::current();

        if ($campaign?->status !== CampaignStatus::Sending || $subscriber?->status !== SubscriberStatus::Active
            || $subscriber->email === null || filter_var($subscriber->email, FILTER_VALIDATE_EMAIL) === false) {
            self::finish($delivery, NewsletterDelivery::SKIPPED);

            return;
        }

        if ($sender->missing() !== []) {
            // 送り主の表示が足りない（配信の途中で表記を消した）: 法の表示の無いメールは送らない
            self::finish($delivery, NewsletterDelivery::FAILED);

            return;
        }

        $token = NewsletterSubscribers::token();
        $delivery->forceFill(['token_hash' => NewsletterSubscribers::hash($token)])->save();

        try {
            Mail::to($subscriber->email)->send(new NewsletterMail(
                (string) $tenant->getAttribute('name'),
                $campaign->subject,
                $campaign->body,
                $sender,
                (string) StorefrontUrls::of($tenant, '/p/newsletter/unsubscribe/'.$token),
            ));
        } catch (Throwable $e) {
            // 失敗の記録は例外の種類だけ（送る仕組みの例外の文には宛先のメールが入りうる。ADR 0010。中身は残さず report もしない）
            Log::warning('一斉メールを送れなかった', ['delivery' => $delivery->id, 'error' => $e::class]);
            self::finish($delivery, NewsletterDelivery::FAILED);

            return;
        }

        self::finish($delivery, NewsletterDelivery::SENT);
    }

    private static function finish(NewsletterDelivery $delivery, string $status): void
    {
        $now = CarbonImmutable::now();
        NewsletterDelivery::query()->whereKey($delivery->id)->where('status', NewsletterDelivery::SENDING)
            ->update(['status' => $status, 'sent_at' => $status === NewsletterDelivery::SENT ? $now : null, 'updated_at' => $now]);
    }
}
