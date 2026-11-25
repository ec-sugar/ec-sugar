<?php

declare(strict_types=1);

namespace App\Core\Trace;

use Illuminate\Database\Eloquent\Model;

/**
 * 行を作った処理の ID（trace_id の列）を、作る時に写す（ADR 0087）。モデルに use し、表に trace_id の列（空でよい）を足す。
 * 行を後から直す時は変えない（最初に作った処理を指す）。ID の無い処理（ID を決める前の起動の途中など）では空のまま
 *
 * @mixin Model
 */
trait RecordsTrace
{
    public static function bootRecordsTrace(): void
    {
        static::creating(static function (Model $model): void {
            if ($model->getAttribute(TraceId::KEY) === null) {
                $model->setAttribute(TraceId::KEY, TraceId::current());
            }
        });
    }
}
