<?php

declare(strict_types=1);

namespace App\Core\Transaction\Events;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Notice;

/**
 * 注文の受渡を発送した（運営・店の操作）。書き込みと同じトランザクションで記録に残し、コミットの後に届く（発送のメールなど。ADR 0089）
 */
final readonly class FulfillmentShipped implements Notice
{
    public function __construct(
        public string $transactionId,
        public string $fulfillmentId,
    ) {}

    public static function type(): string
    {
        return 'notice.fulfillment_shipped';
    }

    public function aggregateId(): string
    {
        return $this->transactionId;
    }

    public function payload(): array
    {
        return ['fulfillment' => $this->fulfillmentId];
    }

    public static function fromEvent(DomainEvent $event): static
    {
        return new self($event->aggregate_id, (string) ($event->payload['fulfillment'] ?? ''));
    }
}
