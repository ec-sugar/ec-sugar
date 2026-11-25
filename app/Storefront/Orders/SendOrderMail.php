<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

use App\Core\Money\Money;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\Refund;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * お客さんへ注文の知らせを送る（キュー。テナントの文脈は stancl/tenancy がつなぐ）。ショップで確定した注文だけ。
 * 同じ注文の同じ知らせは 1 回だけ（送る前に履歴の行を作ってロックし、送れたら済みにする。送れなければ巻き戻して、やり直しに任せる）
 */
final class SendOrderMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly string $transactionId,
        public readonly string $kind,
        public readonly ?string $fulfillmentId = null,
        // 返金の知らせ（order_refunded）は返金ごと（ADR 0048）
        public readonly ?string $refundId = null,
    ) {}

    public function handle(OrderReader $reader): void
    {
        $transaction = $reader->find($this->transactionId);
        $tenant = tenant();

        if (! $transaction instanceof Transaction || ! $tenant instanceof Tenant || ! array_key_exists($this->kind, OrderMail::SUBJECTS)) {
            return;
        }

        $email = $transaction->customer_id === null ? null : Party::query()->find($transaction->customer_id)?->email;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $refund = null;

        if ($this->kind === 'order_refunded') {
            // 済んだ返品・一部返金の返金だけ（額はこの返金の額。住所などは載せない）
            $row = Refund::query()->where('transaction_id', $transaction->id)->whereKey($this->refundId)->where('purpose', 'return')->where('status', 'succeeded')->first();

            if ($row === null) {
                return;
            }

            $refund = ['amountLabel' => Money::format($row->amount, $transaction->currency), 'card' => $row->channel === 'provider'];
        }

        $base = StorefrontUrls::of($tenant, '');
        // メールの記録（ADR 0095）から注文の画面へ移れるように、関係する注文を添える
        $mail = (new OrderMail($this->kind, (string) $tenant->getAttribute('name'), $reader->read($transaction), OrderReader::link($transaction, (string) $base), $refund))
            ->forTransaction($transaction->id);

        DB::transaction(function () use ($mail, $email): void {
            $row = SentMail::query()->firstOrCreate(
                ['transaction_id' => $this->transactionId, 'kind' => $this->kind, 'fulfillment_id' => $this->fulfillmentId, 'refund_id' => $this->refundId],
                ['subject' => $mail->subjectLine(), 'status' => 'sending'],
            );
            $locked = SentMail::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'sent') {
                return;
            }

            Mail::to($email)->send($mail);
            $locked->forceFill(['status' => 'sent', 'sent_at' => CarbonImmutable::now()])->save();
        });
    }
}
