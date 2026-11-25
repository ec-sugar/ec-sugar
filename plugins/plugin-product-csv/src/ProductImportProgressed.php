<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

/**
 * 取り込みの反映で、商品 1 つを確定して記録の進み具合を書いた後（トランザクションの外）。
 * 今は聞く人がいない（テストが「商品の間は連鎖のロックを持っていない」ことを確かめるのに使う）
 */
final readonly class ProductImportProgressed
{
    public function __construct(
        public string $importId,
        public int $appliedRows,
    ) {}
}
