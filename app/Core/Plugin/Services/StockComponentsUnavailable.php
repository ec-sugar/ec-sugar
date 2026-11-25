<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use RuntimeException;

/**
 * 在庫の構成（セット商品が引く品）を読めなかった。在庫を引かずに売らないよう、取引を止める
 */
final class StockComponentsUnavailable extends RuntimeException {}
