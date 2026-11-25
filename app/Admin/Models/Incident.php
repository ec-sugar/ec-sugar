<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * 起きたエラー（中央 DB。指紋ごとに 1 行。ADR 0043）: どこで（種類・場所・店）・何が（例外のクラス）・何回・いつから。
 * メッセージ・スタック・リクエストの中身・利用者は持たない。書くのは Incidents（回数）と operations:alert（知らせた印）だけ
 *
 * @property string $fingerprint
 * @property string $kind http_error | job_failed | command_failed | event_failed
 * @property string $exception
 * @property string $location
 * @property string|null $tenant_id
 * @property int $count
 * @property CarbonImmutable $first_at
 * @property CarbonImmutable $last_at
 * @property CarbonImmutable|null $notified_at
 * @property int|null $notified_count
 * @property string|null $last_trace_id 最後に起きた時の処理の ID（ADR 0087）
 */
final class Incident extends Model
{
    use CentralConnection;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'incidents';

    protected $primaryKey = 'fingerprint';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'notified_count' => 'integer',
            'first_at' => 'immutable_datetime',
            'last_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
        ];
    }
}
