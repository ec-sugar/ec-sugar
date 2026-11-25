<?php

declare(strict_types=1);

namespace App\Core\Payment\Events;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Notice;

/**
 * 後から払う手段（コンビニ払い）が期限までに払われず、注文を取り消した（ADR 0058）。書き込みと同じトランザクションで記録に残し、コミットの後に届く
 * （お客さんへの知らせのため。ADR 0089）
 */
final readonly class PaymentLapsed implements Notice
{
    public function __construct(public string $transactionId) {}

    public static function type(): string
    {
        return 'notice.payment_lapsed';
    }

    public function aggregateId(): string
    {
        return $this->transactionId;
    }

    public function payload(): array
    {
        return [];
    }

    public static function fromEvent(DomainEvent $event): static
    {
        return new self($event->aggregate_id);
    }
}
