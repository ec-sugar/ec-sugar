<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use InvalidArgumentException;

/**
 * App が会員のログインの画面に置く入口（「LINE でログイン」など。ADR 0070）。文字と行き先だけ。
 * 行き先はその App のショップの経路（/p/<名前>/…）だけ（外の URL や核の画面へは向けない。押した先で App が外の認証へ送る）
 */
final readonly class LoginEntry
{
    public function __construct(
        public string $plugin,
        public string $label,
        public string $path,
    ) {
        if (preg_match('#\A/p/'.preg_quote($plugin, '#').'/[A-Za-z0-9/_-]{1,200}\z#', $path) !== 1 || str_contains($path, '//')) {
            throw new InvalidArgumentException("App {$plugin} のログインの入口は、自分の /p/{$plugin}/ の下だけです");
        }

        if ($label === '' || mb_strlen($label) > 20 || preg_match('/\p{C}/u', $label) === 1) {
            throw new InvalidArgumentException("App {$plugin} のログインの入口の文字は 1〜20 文字です");
        }
    }
}
