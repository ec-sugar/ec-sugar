<?php

declare(strict_types=1);

namespace App\Core\Section;

use InvalidArgumentException;

/**
 * テーマの区画の宣言（theme.json）の形が違う。どこが違うかを道筋（sections.hero.fields.heading.max など）で言う
 */
final class InvalidSectionSchemaException extends InvalidArgumentException
{
    public function __construct(public readonly string $path, string $reason)
    {
        parent::__construct("{$path}: {$reason}");
    }
}
