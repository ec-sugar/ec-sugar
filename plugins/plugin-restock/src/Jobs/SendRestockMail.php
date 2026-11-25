<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock\Jobs;

use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Plugin\MemberBenefits;
use App\Core\Plugin\Services\PluginSettings;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use EcSugar\PluginRestock\Mail\RestockMail;
use EcSugar\PluginRestock\Models\RestockRequest;
use EcSugar\PluginRestock\RestockRequests;
use EcSugar\PluginRestock\RestockStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 再入荷のお知らせのメールを送る（キュー。店の文脈は stancl/tenancy がつなぐ）。仕事に載せるのは登録の ID と知らせの種類だけで、
 * 宛先とリンクの合い言葉は仕事の中で店の DB から読む（キューにも失敗の記録にも、平文のメールと合い言葉を残さない。ADR 0010）。
 *
 * - confirm（確かめのお願い）・registered（会員の登録の知らせ）: 送ったら合い言葉を表から消す（表にはハッシュだけが残る）
 * - restocked（入荷の知らせ）: 送ったら登録のメールを消す（知らせは 1 回だけで、終わった登録に連絡先を残さない）
 * どれも、登録が今その状態でなければ送らない（取り消した後・期限の切れた後に届かない）
 */
final class SendRestockMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    private const array STATUS = ['confirm' => RestockStatus::Pending, 'registered' => RestockStatus::Waiting, 'restocked' => RestockStatus::Notified];

    public function __construct(
        public readonly string $requestId,
        public readonly string $kind,
    ) {}

    public function handle(): void
    {
        $tenant = tenant();
        $row = RestockRequest::query()->find($this->requestId);
        $expected = self::STATUS[$this->kind] ?? null;

        if (! $tenant instanceof Tenant || $row === null || $expected === null || $row->status !== $expected
            || $row->email === null || filter_var($row->email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $token = $row->mail_token;

        if ($this->kind !== 'restocked' && $token === null) {
            return;
        }

        // 会員だけ・ランクだけの商品（ADR 0079）は、その相手に見せてよい時だけ知らせる（ゲストの登録・ランクの足りない会員には送らず、メールを消して終える）
        if ($this->kind === 'restocked' && app(MemberBenefits::class)->hides($row->listing_id, $row->customer_account_id)) {
            RestockRequest::query()->whereKey($row->id)->where('status', $expected->value)->where('token_hash', $row->token_hash)->update(['email' => null, 'email_index' => null, 'mail_token' => null]);

            return;
        }

        $listing = Listing::query()->find($row->listing_id);
        $variant = ListingVariant::query()->find($row->variant_id);
        $url = static fn (string $path): string => (string) StorefrontUrls::of($tenant, $path);
        $message = $this->kind === 'confirm' ? (app(PluginSettings::class)->publicValues('restock')['confirm_message'] ?? null) : null;

        Mail::to($row->email)->send(new RestockMail($this->kind, (string) $tenant->getAttribute('name'), [
            'title' => (string) $listing?->title,
            'variant' => (string) $variant?->label(),
            'product' => $url('/products/'.$row->listing_id),
            'confirm' => $token === null ? null : $url('/p/restock/confirm/'.$token),
            'cancel' => $token === null ? null : $url('/p/restock/cancel/'.$token),
            'message' => is_string($message) ? $message : null,
            'hours' => RestockRequests::CONFIRM_HOURS,
        ]));

        $done = $this->kind === 'restocked'
            ? ['email' => null, 'email_index' => null, 'mail_token' => null]
            : ['mail_token' => null];
        RestockRequest::query()->whereKey($row->id)->where('status', $expected->value)->where('token_hash', $row->token_hash)->update($done);
    }
}
