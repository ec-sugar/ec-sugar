<?php

declare(strict_types=1);

namespace App\Core\Media;

/**
 * 作り直した画像の 1 つの大きさ（WebP）。
 */
final readonly class ImageVariant
{
    public function __construct(
        public string $bytes,
        public int $width,
        public int $height,
    ) {}
}
