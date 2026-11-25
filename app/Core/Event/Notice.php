<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Event\Models\DomainEvent;

/**
 * 核が App と核の受け手へ出す知らせ（支払いが確かになった・発送した・返金が確かになった など。ADR 0089）。
 *
 * - 出すのは `EventStore::notice()` だけ。状態の変更と同じトランザクションで、記録（domain_events の `notice.*`）として残す。
 *   コミットの後に送り出し役（EventRelay）が、名前のある受け手（EventReceivers::notice）へ届ける。プロセスが落ちても見回りが届け直す
 * - 中身は ID・金額（整数）・真偽値・短い列挙の文字列だけ（記録の決まり。平文の個人情報を入れない）
 * - `event(new PaymentConfirmed(…))` で直に出すのは、テストで受け手を模擬で動かす時だけ（届け直しの守りが無い）。核のコードは使わない
 */
interface Notice
{
    /**
     * 記録の種類（`notice.` で始まる）
     */
    public static function type(): string;

    /**
     * 集約の ID（今の知らせはどれも取引の ID）
     */
    public function aggregateId(): string;

    /**
     * 記録に入れる中身（集約の ID は除く）
     *
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * 記録から知らせを作り直す
     */
    public static function fromEvent(DomainEvent $event): static;
}
