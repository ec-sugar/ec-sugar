<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use InvalidArgumentException;

/**
 * 商品の画面の開いて読む並び（ProductPanel）の 1 項目。見出し（よくある質問の問い）と本文（答え）。どちらも文字だけ（改行は本文だけ生かす）。
 * レビュー（2026-11-04）のために、任意で星（1〜5）・添え書き（書いた人の表示名と日付など。1 行）・店からの返信（改行は生かす）を持てる
 */
final readonly class ProductPanelItem
{
    public const int SUMMARY_MAX = 200;

    public const int BODY_MAX = 5000;

    public const int META_MAX = 100;

    public function __construct(
        public string $summary,
        public string $body,
        public ?int $rating = null,
        public ?string $meta = null,
        public ?string $reply = null,
    ) {
        if ($summary === '' || ! mb_check_encoding($summary, 'UTF-8') || mb_strlen($summary) > self::SUMMARY_MAX) {
            throw new InvalidArgumentException('商品の画面の項目の見出しは 1〜'.self::SUMMARY_MAX.' 文字');
        }

        if ($body === '' || ! mb_check_encoding($body, 'UTF-8') || mb_strlen($body) > self::BODY_MAX) {
            throw new InvalidArgumentException('商品の画面の項目の本文は 1〜'.self::BODY_MAX.' 文字');
        }

        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            throw new InvalidArgumentException('商品の画面の項目の星は 1〜5');
        }

        if ($meta !== null && ($meta === '' || ! mb_check_encoding($meta, 'UTF-8') || mb_strlen($meta) > self::META_MAX || str_contains($meta, "\n"))) {
            throw new InvalidArgumentException('商品の画面の項目の添え書きは 1 行で 1〜'.self::META_MAX.' 文字');
        }

        if ($reply !== null && ($reply === '' || ! mb_check_encoding($reply, 'UTF-8') || mb_strlen($reply) > self::BODY_MAX)) {
            throw new InvalidArgumentException('商品の画面の項目の返信は 1〜'.self::BODY_MAX.' 文字');
        }
    }
}
