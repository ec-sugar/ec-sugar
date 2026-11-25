<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder;

use App\Core\Listing\Models\Listing;
use App\Core\Party\Models\Party;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Models\Tenant;
use EcSugar\PluginPreorder\Mail\PreorderDateChangedMail;
use EcSugar\PluginPreorder\Models\PreorderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 発売日が変わった知らせを、予約したお客さんへ送る（キュー。店の文脈は stancl/tenancy がつなぐ。ADR 0073 の 5）。
 * 仕事に載せるのは注文の ID と前の日・新しい日だけで、宛先は仕事の中で店の DB から読む（ADR 0010）。
 * 送るまでの間に取り消された注文には送らない
 */
final class SendPreorderMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 600, 3600];

    public function __construct(
        public readonly string $transactionId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $listingId = '',
    ) {}

    public function handle(): void
    {
        $tenant = tenant();
        $transaction = Transaction::query()->find($this->transactionId);
        $email = $transaction?->customer_id === null ? null : Party::query()->find($transaction->customer_id)?->email;

        if (! $tenant instanceof Tenant || $transaction === null || $transaction->status !== TransactionStatus::Open
            || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        // 注文の品をまとめて送る日（注文の中のいちばん遅い発売日）
        $shipsOn = substr((string) PreorderItem::query()->where('transaction_id', $transaction->id)->max('release_date'), 0, 10);
        $facts = [
            'number' => strtoupper($transaction->id),
            'items' => TransactionLine::query()->where('transaction_id', $transaction->id)->where('line_type', LineType::Item)->orderBy('position')->get()
                ->map(static fn (TransactionLine $line): string => $line->description.' × '.$line->quantity)->all(),
            'from' => PreorderTerms::dateLabel($this->from),
            'to' => PreorderTerms::dateLabel($this->to),
            'shipsOn' => $shipsOn === '' ? PreorderTerms::dateLabel($this->to) : PreorderTerms::dateLabel($shipsOn),
            'later' => $this->to > $this->from,
            // どの商品の発売日が変わったか（注文の時の名前。予約の品が 2 つ以上の注文でも分かるように）
            'listing' => (string) (TransactionLine::query()->where('transaction_id', $transaction->id)->where('line_type', LineType::Item)->where('listing_id', $this->listingId)->orderBy('position')->value('description')
                ?? Listing::query()->whereKey($this->listingId)->value('title') ?? ''),
        ];

        Mail::to($email)->send(new PreorderDateChangedMail((string) $tenant->getAttribute('name'), $facts));
    }
}
