<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

use InvalidArgumentException;

/**
 * App のマニフェスト（composer.json の extra.ec-sugar）の形が違う。どこが違うかを道筋（storefront.csp.script-src.0 など）で言う
 */
final class InvalidAppManifestException extends InvalidArgumentException
{
    public function __construct(public readonly string $path, string $reason)
    {
        parent::__construct("{$path}: {$reason}");
    }
}
