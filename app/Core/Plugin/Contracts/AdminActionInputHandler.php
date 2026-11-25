<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;

/**
 * 欄のある操作（AdminAction の fields。チケットの「無効にする」の理由と返金するか など）を行う口。
 * 画面の係（AdminPageHandler）がこれも実装したときだけ、欄のある操作を出せる。核は欄の値を足す・直すの欄と同じ決まり（AdminForms）で確かめ、
 * 全部の欄がそろった値を渡す。欄の無い操作は今までどおり AdminPageHandler::act に渡る
 */
interface AdminActionInputHandler
{
    /**
     * @param  array<string, bool|string|null>  $values  欄の名前 => 値
     *
     * @throws AdminActionRefused
     * @throws AdminRecordNotFound
     */
    public function actWith(string $id, string $action, array $values, string $actor): void;
}
