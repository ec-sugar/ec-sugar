<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use Normalizer;

/**
 * 暗号化した列を完全一致で検索するためのブラインドインデックス。
 * 平文を正規化してから HMAC を取る。正規化の規則は属性の種類で決まる。
 */
final class BlindIndex
{
    public static function of(string $attribute, string $value): string
    {
        return app(TenantCipher::class)->index(self::normalize($attribute, $value));
    }

    public static function normalize(string $attribute, string $value): string
    {
        $value = trim($value);

        return match ($attribute) {
            'email' => mb_strtolower($value),
            'phone' => preg_replace('/\D+/', '', $value) ?? '',
            default => mb_strtolower(self::nfkc(preg_replace('/\s+/u', ' ', $value) ?? '')),
        };
    }

    private static function nfkc(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);

        return $normalized === false ? $value : $normalized;
    }
}
