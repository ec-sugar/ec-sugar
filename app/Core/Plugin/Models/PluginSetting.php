<?php

declare(strict_types=1);

namespace App\Core\Plugin\Models;

use App\Core\Crypto\Casts\TenantEncryptedJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * 店の App の設定の値（店の DB。ADR 0034）。変えるのは PluginSettings だけ。
 * secrets は宣言で秘密とした項目で、店の鍵で暗号化して置く
 *
 * @property string $id
 * @property string $plugin
 * @property array<string, bool|string> $values
 * @property array<string, string>|null $secrets
 * @property CarbonImmutable $changed_at
 */
final class PluginSetting extends Model
{
    use HasUlids;

    protected $table = 'plugin_settings';

    protected $fillable = ['plugin', 'values', 'secrets', 'changed_at'];

    // 秘密の項目は、モデルを配列や JSON にしても出さない
    protected $hidden = ['secrets'];

    /**
     * ふつうの値は「項目の名前 => 値」の形（JSON の object）でだけ置く（表の制約 plugin_settings_values_object）。
     * 空のときも {} で置く（秘密の項目だけを入れた・値を全部消した時。並びの [] にすると制約に当たる。で見つけた）
     *
     * @return Attribute<array<string, bool|string>, array<string, bool|string>>
     */
    protected function values(): Attribute
    {
        return Attribute::make(
            get: static function (mixed $value): array {
                $decoded = is_string($value) ? json_decode($value, true, 8, JSON_THROW_ON_ERROR) : null;

                return is_array($decoded) ? $decoded : [];
            },
            set: static fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }

    protected function casts(): array
    {
        return [
            'secrets' => TenantEncryptedJson::class,
            'changed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
