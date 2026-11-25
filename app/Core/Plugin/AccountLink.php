<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use InvalidArgumentException;

/**
 * App が会員のマイページに置く入口（定期便の管理など）。文字と行き先だけ。行き先はその App のショップの経路（/p/<名前>/…）だけ
 */
final readonly class AccountLink
{
    public function __construct(
        public string $plugin,
        public string $label,
        public string $path,
    ) {
        if (preg_match('#\A/p/'.preg_quote($plugin, '#').'/[A-Za-z0-9/_-]{1,200}\z#', $path) !== 1 || str_contains($path, '//')) {
            throw new InvalidArgumentException("App {$plugin} のマイページの入口は、自分の /p/{$plugin}/ の下だけです");
        }

        if ($label === '' || mb_strlen($label) > 20) {
            throw new InvalidArgumentException("App {$plugin} のマイページの入口の文字が長すぎます");
        }
    }
}
