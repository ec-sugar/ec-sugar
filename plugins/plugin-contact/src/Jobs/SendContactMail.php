<?php

declare(strict_types=1);

namespace EcSugar\PluginContact\Jobs;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\RateLimit\Throttle;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use EcSugar\PluginContact\ContactLinks;
use EcSugar\PluginContact\Mail\ContactMail;
use EcSugar\PluginContact\Models\ContactInquiry;
use EcSugar\PluginContact\Models\ContactReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * お問い合わせのメールを送る（キュー。店の文脈は stancl/tenancy がつなぐ）。仕事に載せるのは ID と知らせの種類だけで、
 * 宛先と文は仕事の中で店の DB から読む（キューにも失敗の記録にも、平文の名前・メール・本文を残さない。ADR 0010）。
 *
 * - received（お客さんへの受け付けの知らせ）: 決まった文と受付番号・種類・付いた商品の名前だけ。**お客さんの書いた文（名前・本文）は載せない**
 *   （フォームには誰でも好きなアドレスを書けるので、写しを載せると、店のメールで他人へ好きな文を送れてしまう）
 * - reply（店からの返信）: 店の人の書いた文と受付番号。送ったら返信に送れた時刻を残す（2 回送らない）
 * - shop（店への知らせ）: 「新しいお問い合わせが 1 件あります」と管理画面のリンクだけ（本文・名前・メールは載せない）。宛先は App の設定の
 *   「知らせを受け取るメールアドレス」（店の鍵で暗号化した秘密の項目）。空なら送らない。店ごとに 1 時間 SHOP_HOURLY 通まで（いたずらの連投で店の受信箱を埋めない）
 *
 * どれも、記録が消えていたら送らない（消した・片付けた後に届かない）
 */
final class SendContactMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string RECEIVED = 'received';

    public const string REPLY = 'reply';

    public const string SHOP = 'shop';

    public const int SHOP_HOURLY = 10;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(
        public readonly string $id,
        public readonly string $kind,
    ) {}

    public function handle(): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $shop = (string) $tenant->getAttribute('name');

        match ($this->kind) {
            self::RECEIVED => $this->received($tenant, $shop),
            self::REPLY => $this->reply($tenant, $shop),
            self::SHOP => $this->shop($tenant, $shop),
            default => null,
        };
    }

    private function received(Tenant $tenant, string $shop): void
    {
        $inquiry = ContactInquiry::query()->find($this->id);

        if ($inquiry === null || filter_var($inquiry->email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $listing = $inquiry->listing_id === null ? null : Listing::query()->find($inquiry->listing_id);

        Mail::to($inquiry->email)->send(new ContactMail(self::RECEIVED, $shop, [
            'number' => $inquiry->number(),
            'kind' => $inquiry->kind->label(),
            'listing' => $listing?->title,
            'order' => $inquiry->transaction_id !== null,
            'form' => (string) StorefrontUrls::of($tenant, ContactLinks::form()),
        ]));
    }

    private function reply(Tenant $tenant, string $shop): void
    {
        $reply = ContactReply::query()->find($this->id);
        $inquiry = $reply === null ? null : ContactInquiry::query()->find($reply->inquiry_id);

        if ($reply === null || $reply->sent_at !== null || $inquiry === null || filter_var($inquiry->email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        Mail::to($inquiry->email)->send(new ContactMail(self::REPLY, $shop, [
            'number' => $inquiry->number(),
            'reply' => $reply->body,
            'form' => (string) StorefrontUrls::of($tenant, ContactLinks::form()),
        ]));

        ContactReply::query()->whereKey($reply->id)->whereNull('sent_at')->update(['sent_at' => now()]);
    }

    private function shop(Tenant $tenant, string $shop): void
    {
        $to = app(PluginSettings::class)->secret('contact', 'notify_email');
        $inquiry = ContactInquiry::query()->find($this->id);

        if ($to === null || filter_var($to, FILTER_VALIDATE_EMAIL) === false || $inquiry === null) {
            return;
        }

        // 店ごとに 1 時間 SHOP_HOURLY 通まで（越えた分はホームの「やること」で気づける）
        if (! Throttle::allow('contact-shop-notice|'.(string) $tenant->getTenantKey(), self::SHOP_HOURLY, 3600)) {
            return;
        }

        Mail::to($to)->send(new ContactMail(self::SHOP, $shop, [
            'admin' => config('admin.url').'/tenants/'.rawurlencode((string) $tenant->getTenantKey()).'/apps/contact/'.$inquiry->id,
        ]));
    }
}
