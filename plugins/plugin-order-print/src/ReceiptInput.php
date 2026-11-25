<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint;

/**
 * 領収書の宛名・但し書きの入力（1 行の文）。前後の空白を除いて 40 文字まで、制御文字と文字の向きを変える印は断る。
 * 書類には文字として出す（HTML として扱わない）ので、< や " は断らない
 */
final class ReceiptInput
{
    public const int MAX = 40;

    /**
     * 確かめた値（空は ''）。形が違えば null
     */
    public static function line(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/\p{C}/u', $value) === 1) {
            return null;
        }

        // 全角の空白も前後から除く
        $value = (string) preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $value);

        return mb_strlen($value) <= self::MAX ? $value : null;
    }
}
