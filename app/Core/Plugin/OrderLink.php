<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use InvalidArgumentException;

/**
 * App がお客さんのご注文の画面（ご注文の確認・マイページの注文の履歴）に置くリンク（「レビューを書く」「領収書」など。2026-11-04）。文字と行き先だけ。
 * 行き先はその App のショップの経路（/p/<名前>/…）だけ。ゲストに開かせるときは、App が自分の経路に署名と期限をつけて渡す（問い合わせの部分〔?…〕はそのため）。
 * newTab は新しいタブで開くか（印刷用のページなど。ご注文の画面をそのまま残す）
 */
final readonly class OrderLink
{
    public const int LABEL_MAX = 20;

    public const int PATH_MAX = 1000;

    public function __construct(
        public string $plugin,
        public string $label,
        public string $path,
        public bool $newTab = false,
    ) {
        if (preg_match('#\A/p/'.preg_quote($plugin, '#').'/[A-Za-z0-9/_-]{1,200}(\?[A-Za-z0-9=&%._~-]{1,700})?\z#', $path) !== 1
            || str_contains($path, '//') || strlen($path) > self::PATH_MAX) {
            throw new InvalidArgumentException("App {$plugin} のご注文のリンクは、自分の /p/{$plugin}/ の下だけです");
        }

        if ($label === '' || ! mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > self::LABEL_MAX || preg_match('/\p{C}/u', $label) === 1) {
            throw new InvalidArgumentException("App {$plugin} のご注文のリンクの文字は 1〜".self::LABEL_MAX.' 文字の 1 行で');
        }
    }
}
