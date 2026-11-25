<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 商品に置いた売るファイル（ADR 0084）。書くのは DownloadFiles だけ（連鎖のロックとイベントの中で）
 *
 * @property string $id
 * @property string $listing_id
 * @property string $name
 * @property string $extension
 * @property string $mime
 * @property int $bytes
 * @property string $sha256
 * @property string $path
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable|null $purged_at
 * @property CarbonImmutable $created_at
 */
final class DownloadFile extends Model
{
    use HasUlids;

    protected $table = 'downloads_files';

    protected $fillable = ['id', 'listing_id', 'name', 'extension', 'mime', 'bytes', 'sha256', 'path', 'removed_at', 'purged_at'];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'removed_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
