<?php

declare(strict_types=1);

namespace App\Core\Plugin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 店のプラグインの使う・使わない（店の DB）。変えるのは PluginStates だけ
 *
 * @property string $id
 * @property string $plugin
 * @property bool $enabled
 * @property CarbonImmutable $changed_at
 */
final class PluginState extends Model
{
    use HasUlids;

    protected $table = 'plugin_states';

    protected $fillable = ['plugin', 'enabled', 'changed_at'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'changed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
