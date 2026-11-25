<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use App\Core\Party\Models\Party;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use EcSugar\PluginDownloads\Mail\DownloadMail;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 「ダウンロードのご案内」を買った人（注文のご依頼主のメール）へ送る（リンクを作った後のキューの仕事。店の文脈は核のキューが戻す）。
 * 載せるリンクはダウンロードの画面への署名つきの道筋（期限はリンクのいちばん遅い期限）。リンクだけでは開けない（照会か会員の本人）
 */
final class SendDownloadMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 600, 3600];

    public function __construct(public readonly string $orderId) {}

    public function handle(): void
    {
        $tenant = tenant();
        $order = Transaction::query()->find($this->orderId);
        $email = $order === null ? null : Party::query()->find($order->customer_id)?->email;
        $grants = DownloadGrant::query()->where('transaction_id', $this->orderId)->get();

        if (! $tenant instanceof Tenant || $order === null || $grants->isEmpty() || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $until = $grants->max('expires_at');
        $base = StorefrontUrls::of($tenant, '');

        if ($base === null || $until === null) {
            return;
        }

        $facts = [
            'number' => strtoupper($order->id),
            'files' => $grants->count(),
            'expires' => $until->timezone('Asia/Tokyo')->format('Y/m/d H:i'),
            'limit' => (int) $grants->max('max_count'),
            'url' => $base.DownloadLinks::order($order->id, $until),
        ];

        Mail::to($email)->send(new DownloadMail((string) $tenant->getAttribute('name'), $facts));
    }
}
