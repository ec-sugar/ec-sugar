<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use Carbon\CarbonImmutable;
use EcSugar\PluginSalePeriod\Models\SaleWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 商品ごとの公開の予約と販売期間を決める。状態を変えるのはここだけ（モデルを直に書き換えない）。
 * どれも DB のトランザクションの中で、最初に連鎖のロック（EventStore::lock()）を取り、その後に行のロック（lockForUpdate）を取り、
 * 同じトランザクションでイベント（sale_period.set・sale_period.cleared）を残す（conventions.md の 3、ADR 0012）。
 * イベントの中身は商品の ID と日時だけ（店の人の書いた文・個人情報は入れない。ADR 0010）。
 * 核の商品（listings）は読むだけで、状態（下書き・公開・終了）は変えない
 */
final class SaleWindows
{
    public function __construct(private readonly EventStore $events) {}

    /**
     * 商品の欄から保存する。どちらも空なら、その商品の期間をやめる（行を消す）
     *
     * @throws ValidationException 日時の形・順・範囲が違うとき（欄の名前で返す）
     */
    public function save(Listing $listing, ?string $startsAt, ?string $endsAt, string $actor): void
    {
        $starts = self::at($startsAt, 'starts_at');
        $ends = self::at($endsAt, 'ends_at');

        if ($starts !== null && $ends !== null && $starts->greaterThanOrEqualTo($ends)) {
            throw ValidationException::withMessages(['ends_at' => '「販売を終える日時」は、「ショップに公開する日時」より後にしてください。']);
        }

        DB::transaction(function () use ($listing, $starts, $ends, $actor): void {
            $this->events->lock();
            $row = SaleWindow::query()->where('listing_id', $listing->id)->lockForUpdate()->first();

            if ($starts === null && $ends === null) {
                if ($row !== null) {
                    self::forget($row, $actor, $this->events);
                }

                return;
            }

            // 同じ日時の送り直しでは、記録を増やさない
            if ($row !== null && self::same($row->starts_at, $starts) && self::same($row->ends_at, $ends)) {
                return;
            }

            $row ??= new SaleWindow(['listing_id' => $listing->id]);
            $row->fill(['starts_at' => $starts, 'ends_at' => $ends])->save();
            $this->events->append('sale_period.set', 'sale_period_window', $row->id, [
                'listing_id' => $listing->id,
                'starts_at' => $starts?->toIso8601ZuluString(),
                'ends_at' => $ends?->toIso8601ZuluString(),
            ], $actor);
        });
    }

    /**
     * 期間をやめる（App の画面の操作。行が無ければ管理画面では 404）
     */
    public function clear(string $rowId, string $actor): void
    {
        DB::transaction(function () use ($rowId, $actor): void {
            $this->events->lock();
            $row = SaleWindow::query()->whereKey($rowId)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            self::forget($row, $actor, $this->events);
        });
    }

    /**
     * 行を消して記録を残す（連鎖のロックと行のロックを取った後で呼ぶ）
     */
    private static function forget(SaleWindow $row, string $actor, EventStore $events): void
    {
        $listingId = $row->listing_id;
        $id = $row->id;
        $row->delete();
        $events->append('sale_period.cleared', 'sale_period_window', $id, ['listing_id' => $listingId], $actor);
    }

    /**
     * 同じ日時か（どちらも空なら同じ）
     */
    private static function same(?CarbonImmutable $before, ?CarbonImmutable $after): bool
    {
        return $before === null ? $after === null : ($after !== null && $before->equalTo($after));
    }

    /**
     * 欄の値（日本時間の文）を UTC の日時に。空なら null
     *
     * @throws ValidationException
     */
    private static function at(?string $value, string $field): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $at = SalePeriodTime::parse($value);

        if ($at === null) {
            throw ValidationException::withMessages([$field => '日時は「'.SalePeriodTime::FORMAT.'」の形（日本時間）で入れてください。']);
        }

        $floor = SalePeriodTime::parse(SalePeriodTime::FLOOR);
        $ceiling = CarbonImmutable::now()->addYears(SalePeriodTime::MAX_YEARS_AHEAD);

        if ($floor !== null && ($at->lessThan($floor) || $at->greaterThan($ceiling))) {
            throw ValidationException::withMessages([$field => '日時は '.SalePeriodTime::FLOOR.' から '.SalePeriodTime::MAX_YEARS_AHEAD.' 年先までで入れてください。']);
        }

        return $at;
    }
}
