<?php

declare(strict_types=1);

namespace App\Core\Event;

/**
 * イベント連鎖の検証結果。brokenAt は最初に食い違った sequence（無傷なら null）。
 * headSequence・headHash は、確かめられた最後のイベント（連鎖の先頭）。空の連鎖なら 0 と null。
 * 先頭を外に控えておくと、末尾を切り落とす改竄（残りはつながったままなので、連鎖だけ見ても無傷に見える）に気づける（ADR 0012）
 */
final readonly class ChainVerification
{
    public function __construct(
        public int $checked,
        public ?int $brokenAt,
        public int $headSequence = 0,
        public ?string $headHash = null,
    ) {}

    public function intact(): bool
    {
        return $this->brokenAt === null;
    }
}
