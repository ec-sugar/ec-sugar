<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

/**
 * composer.json の extra の中で、プラグインとテーマのマニフェストを置くキー。
 * 新しいキーは ec-sugar。名前を EC-SUGAR にそろえる前のキー sugar-ec（旧名 SUGAR-EC）も当面は読み、
 * 外の人が作った古いキーのテーマや App も今どおり入るようにする。両方あれば新しいキーだけを見る（古い方は混ぜない）
 */
final class PackageManifest
{
    public const string KEY = 'ec-sugar';

    /** 旧名 SUGAR-EC のころのキー。読むだけで、新しく書くパッケージには使わない */
    public const string LEGACY_KEY = 'sugar-ec';

    /**
     * パッケージ（composer.json か installed.json・composer.lock の 1 件）のどのキーにマニフェストがあるか。無ければ null
     */
    public static function key(mixed $package): ?string
    {
        $extra = is_array($package) && is_array($package['extra'] ?? null) ? $package['extra'] : [];

        foreach ([self::KEY, self::LEGACY_KEY] as $key) {
            if (array_key_exists($key, $extra)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * マニフェストの中身（形は確かめない。確かめるのは読む側）。無ければ null
     */
    public static function of(mixed $package): mixed
    {
        $key = self::key($package);

        return $key === null || ! is_array($package) ? null : $package['extra'][$key];
    }
}
