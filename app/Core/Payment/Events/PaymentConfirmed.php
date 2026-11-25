<?php

declare(strict_types=1);

namespace App\Core\Payment\Events;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Notice;

/**
 * 取引の支払いが確定した（Webhook で確かめた・問い合わせで確かめた・店の人が銀行振込の入金を確かめた）。確定の書き込みと同じトランザクションで記録に残し、
 * コミットの後に送り出し役が受け手へ届ける（確認のメールなど、外への知らせのため。ADR 0089）。
 * 代金引換の注文（ADR 0074）は、注文を受け付けた時（払う前に発送できる状態になった時）に onDelivery つきで 1 回だけ出す
 * （ご注文の確認のメール・店への知らせ）。代引きの入金を確かめた時には出さない。払う前なので、クーポンを「使った」にはしない
 */
final readonly class PaymentConfirmed implements Notice
{
    public function __construct(public string $transactionId, public bool $onDelivery = false) {}

    public static function type(): string
    {
        return 'notice.payment_confirmed';
    }

    public function aggregateId(): string
    {
        return $this->transactionId;
    }

    public function payload(): array
    {
        return $this->onDelivery ? ['on_delivery' => true] : [];
    }

    public static function fromEvent(DomainEvent $event): static
    {
        return new self($event->aggregate_id, ($event->payload['on_delivery'] ?? false) === true);
    }
}
