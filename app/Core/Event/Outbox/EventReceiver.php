<?php

declare(strict_types=1);

namespace App\Core\Event\Outbox;

use App\Core\Event\Models\DomainEvent;
use Closure;

/**
 * 名前のある受け手（ADR 0089）。送り出し役（EventRelay）が、受け手ごとに「どこまで流したか」を持って届ける。
 *
 * - name: 受け手の名前（`<App の名前か core>.<何をするか>`。例 `tickets.issue`）。どこまで流したかの印と失敗の記録の鍵になるので、変えると最初から数え直す
 * - types: 受け取る記録の種類（domain_events.type）。ほかの種類は呼ばない（印だけ進める）
 * - handler: 記録を 1 件受け取る。**同じ記録が 2 回届いても同じ結果になる**ように書く（冪等。ADR 0089）。
 *   続けられない時は例外を投げる（送り出し役が報告し、間を空けて届け直す。何度も落ちれば止めて要対応に出す）。投げずに報告だけすると届け直さない
 * - app: App の名前。その App を店で止めている間の記録は届けない（印だけ進める）。止めた App にも届けたい受け手（払った人への約束を守るもの）は null
 */
final readonly class EventReceiver
{
    /**
     * @param  list<string>  $types
     * @param  Closure(DomainEvent): mixed  $handler
     */
    public function __construct(
        public string $name,
        public array $types,
        public Closure $handler,
        public ?string $app = null,
    ) {}

    public function accepts(DomainEvent $event): bool
    {
        return in_array($event->type, $this->types, true);
    }
}
