<?php

declare(strict_types=1);

namespace App\Storefront\Apps;

use App\Core\Plugin\Manifest\CspSources;

/**
 * ショップの CSP（config('storefront.csp')）に、差し込んだ App の宛先を足す（ADR 0034 の 2）。
 * 足せるのは決まった 4 つの種類だけで、宛先はもう一度形を確かめる（マニフェストで確かめた後でも、ここを通らないものは足さない）。
 * 元に無い種類（frame-src）は新しく足す。ほかの種類（default-src・form-action・require-trusted-types-for・trusted-types など）は変えない
 */
final class AppCsp
{
    /**
     * @param  array<string, list<string>>  $additions
     */
    public static function merge(string $base, array $additions): string
    {
        $directives = [];

        foreach (explode(';', $base) as $part) {
            $part = trim($part);

            if ($part !== '') {
                $name = strtolower(strtok($part, " \t") ?: '');
                $directives[$name] = $part;
            }
        }

        foreach ($additions as $directive => $sources) {
            if (! in_array($directive, CspSources::DIRECTIVES, true)) {
                continue;
            }

            $sources = array_values(array_filter($sources, static fn (string $s): bool => CspSources::problem($s) === null));

            if ($sources === []) {
                continue;
            }

            $current = $directives[$directive] ?? $directive;
            $have = preg_split('/\s+/', $current) ?: [];
            $directives[$directive] = implode(' ', array_values(array_unique([...$have, ...$sources])));
        }

        return implode('; ', $directives);
    }
}
