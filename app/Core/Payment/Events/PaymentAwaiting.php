<?php

declare(strict_types=1);

namespace App\Core\Payment\Events;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Notice;

/**
 * 後から払う手段（コンビニ払い・銀行振込）の入金待ちになった（ADR 0058・0074）。書き込みと同じトランザクションで記録に残し、コミットの後に届く
 * （「入金をお待ちしています」の知らせのため。ADR 0089）
 */
final readonly class PaymentAwaiting implements Notice
{
    public function __construct(public string $transactionId) {}

    public static function type(): string
    {
        return 'notice.payment_awaiting';
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
