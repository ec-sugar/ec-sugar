<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Event\Models\DomainEvent;

/**
 * ドメインイベントがコミットされた後に、書いたのと同じプロセスの中だけで発火する Laravel のイベント（CONCEPT 6.3）。
 * **届かないことがある**（コミットの直後にプロセスが落ちると消え、届け直さない）。メール・ポイント・発行など、届かないと困る仕事には使わず、
 * 名前のある受け手（`App\Core\Event\Outbox\EventReceivers`。少なくとも 1 回届く。ADR 0089）で受ける。
 * ここで受けてよいのは、書いた要求の文脈（Cookie など）が要り、届かなければ何もしなくてよいものだけ（紹介の印を会員に結ぶ など）。
 */
final readonly class DomainEventRecorded
{
    public function __construct(public DomainEvent $event) {}
}
