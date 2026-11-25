<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * CBOR のバイト列（major type 2）。文字列（major type 3）と区別するための包み。
 */
final readonly class CborBytes
{
    public function __construct(public string $bytes) {}
}
