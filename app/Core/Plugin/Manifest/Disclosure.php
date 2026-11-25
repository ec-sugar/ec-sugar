<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

/**
 * 外部送信に関する公表の 1 行（ADR 0034 の 5、電気通信事業法の外部送信規律）。App がマニフェストに書き、
 * 核がその店で使っている App の分を集めて公表のページを作る。文は作り手が書いたものなので、画面では文字として出すだけ
 */
final readonly class Disclosure
{
    public function __construct(
        public string $company,
        public string $data,
        public string $purpose,
        public string $policy,
    ) {}
}
