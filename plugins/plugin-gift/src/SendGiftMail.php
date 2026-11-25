<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

use App\Core\Party\Models\Party;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use EcSugar\PluginGift\Mail\GiftMail;
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 贈り物の知らせを買う人へ送る（キュー。店の文脈は stancl/tenancy がつなぐ）。仕事に載せるのは贈り物の ID と知らせの種類だけで、
 * 宛先は仕事の中で店の DB から読む（ADR 0010）。受け取られた知らせに、受け取る人の名前やお届け先は載せない
 */
final class SendGiftMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 600, 3600];

    public function __construct(
        public readonly string $giftId,
        public readonly string $kind,
    ) {}

    public function handle(): void
    {
        $tenant = tenant();
        $gift = GiftOrder::query()->find($this->giftId);
        $transaction = $gift === null ? null : Transaction::query()->find($gift->transaction_id);
        $email = $transaction?->customer_id === null ? null : Party::query()->find($transaction->customer_id)?->email;

        if (! $tenant instanceof Tenant || $gift === null || $transaction === null || ! array_key_exists($this->kind, GiftMail::SUBJECTS)
            || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        // 期限の知らせは、まだ受け取り待ちの時だけ（送るまでの間に受け取られた・取り消した）
        if ($this->kind === 'expiring' && $gift->status !== 'waiting') {
            return;
        }

        $base = (string) StorefrontUrls::of($tenant, '');
        // App を使うのをやめた店では、受け取りの画面と買う人の画面が開かないので、リンクを載せない（期限の知らせと返金の知らせは送る）
        $open = app(PluginStates::class)->enabled('gift');
        $facts = [
            'number' => strtoupper($transaction->id),
            'items' => TransactionLine::query()->where('transaction_id', $transaction->id)->where('line_type', LineType::Item)->orderBy('position')->get()
                ->map(static fn (TransactionLine $line): string => $line->description.' × '.$line->quantity)->all(),
            'expiresOn' => $gift->expires_at === null ? null : GiftStatus::date($gift->expires_at),
            'link' => $open && $this->kind === 'expiring' && $gift->token_sealed !== null ? $base.'/p/gift/'.$gift->token_sealed : null,
            'manage' => $open ? $base.GiftCheckout::signedManagePath($transaction->id) : null,
        ];

        Mail::to($email)->send(new GiftMail($this->kind, (string) $tenant->getAttribute('name'), $facts));
    }
}
