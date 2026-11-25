<?php

declare(strict_types=1);

namespace App\Core\Inventory\Services;

use RuntimeException;

final class InsufficientStockException extends RuntimeException {}
