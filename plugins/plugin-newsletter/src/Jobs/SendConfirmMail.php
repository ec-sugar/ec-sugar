<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter\Jobs;

use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use EcSugar\PluginNewsletter\Mail\ConfirmMail;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use EcSugar\PluginNewsletter\NewsletterSubscribers;
use EcSugar\PluginNewsletter\SubscriberStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 購読の確かめのメールを送る（キュー。店の文脈は stancl/tenancy がつなぐ）。仕事に載せるのは購読の ID だけで、
 * 宛先とリンクの合い言葉は仕事の中で店の DB から読む（キューにも失敗の記録にも、平文のメールと合い言葉を残さない。ADR 0010）。
 * 送ったら合い言葉を表から消す（ハッシュだけが残る）。今も確かめ待ちでなければ送らない
 */
final class SendConfirmMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(public readonly string $subscriberId) {}

    public function handle(): void
    {
        $tenant = tenant();
        $row = NewsletterSubscriber::query()->find($this->subscriberId);

        if (! $tenant instanceof Tenant || $row === null || $row->status !== SubscriberStatus::Pending || $row->mail_token === null
            || $row->email === null || filter_var($row->email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        Mail::to($row->email)->send(new ConfirmMail(
            (string) $tenant->getAttribute('name'),
            (string) StorefrontUrls::of($tenant, '/p/newsletter/confirm/'.$row->mail_token),
            NewsletterSubscribers::CONFIRM_HOURS,
        ));

        NewsletterSubscriber::query()->whereKey($row->id)->where('status', SubscriberStatus::Pending->value)->where('token_hash', $row->token_hash)->update(['mail_token' => null]);
    }
}
