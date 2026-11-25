<?php

declare(strict_types=1);

namespace App\Core\Party\Events;

/**
 * 会員が退会した（ADR 0044 の 3）。退会と同じトランザクション・連鎖のロックの中で出す。App は会員だけの申し込み（定期便など）をここで解約する。
 * 聞く側が例外を投げると退会も取り消される
 */
final readonly class CustomerLeft
{
    public function __construct(
        public string $accountId,
        public string $actor,
    ) {}
}
