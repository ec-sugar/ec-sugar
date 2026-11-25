<?php

declare(strict_types=1);

namespace App\Core\Inventory\Services;

use App\Core\Inventory\Enums\UnitStatus;
use App\Core\Inventory\Models\InventoryUnit;
use Illuminate\Support\Facades\DB;

/**
 * 個体在庫の確保・解除・出庫。StockService と同じ語彙で、行ロックを取って状態を移す。
 * 同じ 1 点を 2 つの取引が同時に確保できないようにする。
 */
final class UnitService
{
    public function reserve(InventoryUnit $unit): InventoryUnit
    {
        return $this->move($unit, UnitStatus::Available, UnitStatus::Reserved);
    }

    public function release(InventoryUnit $unit): InventoryUnit
    {
        return $this->move($unit, UnitStatus::Reserved, UnitStatus::Available);
    }

    public function commit(InventoryUnit $unit): InventoryUnit
    {
        return $this->move($unit, UnitStatus::Reserved, UnitStatus::Sold);
    }

    /**
     * 売れる状態の 1 点を廃棄にする（代引きの受取拒否で戻ってきた品を在庫に戻さない時。ADR 0074）。記録は呼ぶ側が残す
     */
    public function dispose(InventoryUnit $unit): InventoryUnit
    {
        return $this->move($unit, UnitStatus::Available, UnitStatus::Disposed);
    }

    private function move(InventoryUnit $unit, UnitStatus $from, UnitStatus $to): InventoryUnit
    {
        return DB::transaction(static function () use ($unit, $from, $to): InventoryUnit {
            $locked = InventoryUnit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $from) {
                throw new InsufficientStockException("個体 {$locked->id} は {$from->value} ではありません（現在 {$locked->status->value}）");
            }

            $locked->status = $to;
            $locked->save();

            return $locked;
        });
    }
}
