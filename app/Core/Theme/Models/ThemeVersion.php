<?php

declare(strict_types=1);

namespace App\Core\Theme\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * テーマの区画の値のバージョン（テナント DB。ADR 0028 区切り 2）。店 × テーマ × バージョンで、下書きと公開を持つ。
 * 変えるのは ThemeContentService だけ。
 *
 * @property string $id
 * @property string $theme
 * @property string $name
 * @property bool $current
 * @property array<string, mixed> $draft
 * @property array<string, mixed>|null $published
 * @property int $revision
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ThemeVersion extends Model
{
    use HasUlids;

    protected $table = 'theme_versions';

    protected $fillable = ['theme', 'name', 'current', 'draft', 'published', 'revision', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current' => 'boolean',
            'draft' => 'array',
            'published' => 'array',
            'revision' => 'integer',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
