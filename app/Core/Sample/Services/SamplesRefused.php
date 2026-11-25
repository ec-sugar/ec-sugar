<?php

declare(strict_types=1);

namespace App\Core\Sample\Services;

use RuntimeException;

/**
 * サンプルを入れない（お店の商品がもうある）。文はそのままコマンドに出す
 */
final class SamplesRefused extends RuntimeException {}
