<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use RuntimeException;

/**
 * 取り込みの 1 行（とその商品）を断る。文はそのまま確かめの画面に出す
 */
final class RowRejected extends RuntimeException
{
    public function __construct(public readonly int $row, public readonly string $column, string $message)
    {
        parent::__construct($message);
    }
}
