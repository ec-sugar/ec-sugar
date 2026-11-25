<?php

declare(strict_types=1);

namespace App\Core\Payment\Events;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Notice;

/**
 * 返金が確かになった（決済代行の返金が成功した・店の人が外で返したと記録した・止めた返金を確かめて記録した。ADR 0048）。
 * 書き込みと同じトランザクションで記録に残し、コミットの後に届く（ADR 0089）。
 * App はこれを聞いて、返した品に結びつくもの（デジタルのチケットなど）を止める。お客さんへの返金の知らせのメールもこれで送る。
 * 個人情報は入れない（取引・返金・明細の位置と数と額だけ）
 *
 * - purpose: return（返品・一部返金）・external（決済代行の画面でした返金を記録）・cancel（発送の前の取消）・shipping（贈り物の送料の差額）
 * - lines: 返金の行（返品・一部返金と、記録した外の返金だけ。取消は全部なので空）。kind は item・addon・shipping・amount
 * - full: この返金の後、受け取った額をもう全部返した
 */
final readonly class RefundSettled implements Notice
{
    /**
     * @param  list<array{kind: string, position: int|null, quantity: int, amount: int}>  $lines
     */
    public function __construct(
        public string $transactionId,
        public string $refundId,
        public string $purpose,
        public int $amount,
        public bool $full,
        public ?string $returnId = null,
        public array $lines = [],
    ) {}

    public static function type(): string
    {
        return 'notice.refund_settled';
    }

    public function aggregateId(): string
    {
        return $this->transactionId;
    }

    public function payload(): array
    {
        return [
            'refund' => $this->refundId,
            'purpose' => $this->purpose,
            'amount' => $this->amount,
            'full' => $this->full,
            'return' => $this->returnId,
            'lines' => $this->lines,
        ];
    }

    public static function fromEvent(DomainEvent $event): static
    {
        $payload = $event->payload;
        $lines = [];

        foreach (is_array($payload['lines'] ?? null) ? $payload['lines'] : [] as $line) {
            if (is_array($line)) {
                $lines[] = [
                    'kind' => (string) ($line['kind'] ?? ''),
                    'position' => isset($line['position']) ? (int) $line['position'] : null,
                    'quantity' => (int) ($line['quantity'] ?? 0),
                    'amount' => (int) ($line['amount'] ?? 0),
                ];
            }
        }

        return new self(
            $event->aggregate_id,
            (string) ($payload['refund'] ?? ''),
            (string) ($payload['purpose'] ?? ''),
            (int) ($payload['amount'] ?? 0),
            ($payload['full'] ?? false) === true,
            is_string($payload['return'] ?? null) ? $payload['return'] : null,
            $lines,
        );
    }
}
