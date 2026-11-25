<?php

declare(strict_types=1);

namespace App\Core\Crypto\Casts;

use App\Core\Crypto\TenantCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 文字列の列をテナント鍵で暗号化して保存する。
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class TenantEncrypted implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(TenantCipher::class)->decrypt((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(TenantCipher::class)->encrypt((string) $value);
    }
}
