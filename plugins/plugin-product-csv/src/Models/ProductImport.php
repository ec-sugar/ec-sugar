<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 商品の CSV の取り込みの記録（店の DB）。変えるのは ProductImports だけ。
 *
 * 状態: checking（確かめ中）→ checked（誤り無し）か rejected（誤りあり）→ queued（反映の待ち）→ running（反映中）→ done（済み）か stopped（止まった）。
 * 確かめたまま 1 日たったものは expired（CSV を消す）
 *
 * @property string $id
 * @property string|null $previous_id もう一度取り込んだときの前の取り込み
 * @property string $actor
 * @property string $digest
 * @property string $format
 * @property int $rows
 * @property string $status
 * @property string|null $csv
 * @property array<string, mixed>|null $report 確かめの結果（数と誤りの行）
 * @property array{create: int, update: int, skip: int}|null $applied 反映で入った行の数（進み具合）
 * @property int $applied_rows
 * @property int|null $next_line 確かめ・反映を続ける行（続きの仕事）
 * @property int|null $stopped_line
 * @property string|null $stopped_reason
 * @property array<string, string> $created 新しく作った商品（商品名 => ID）
 * @property string|null $archive 一緒に上げた写真の ZIP を展開した置き場の名前（写真の置き場の tenants/{店}/imports/{archive}/）
 * @property array<string, array{file?: string, digest?: string, problem?: string}>|null $archive_files ZIP の中身の一覧（中の名前 => 置いたファイルと中身のハッシュか、使えない理由）
 * @property int|null $photo_next 反映の後半（写真の段）の続きの位置。null なら商品の段
 * @property array{total?: int, added?: int, kept?: int, failed?: int, problems?: list<array{line: int, column: string, message: string}>, problems_total?: int}|null $photos 写真の反映の結果
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $updated_at
 */
final class ProductImport extends Model
{
    use HasUlids;

    /** 反映の待ち・進行中（店に 1 つだけ） */
    public const array APPLYING = ['queued', 'running'];

    protected $table = 'product_imports';

    protected $fillable = [
        'previous_id', 'actor', 'digest', 'format', 'rows', 'status', 'csv', 'report', 'applied',
        'applied_rows', 'next_line', 'stopped_line', 'stopped_reason', 'created', 'started_at', 'finished_at',
        'archive', 'archive_files', 'photo_next', 'photos',
    ];

    protected $attributes = [
        'applied_rows' => 0,
        'created' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rows' => 'integer',
            'report' => 'array',
            'applied' => 'array',
            'applied_rows' => 'integer',
            'next_line' => 'integer',
            'stopped_line' => 'integer',
            'created' => 'array',
            'archive_files' => 'array',
            'photo_next' => 'integer',
            'photos' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
