<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder;

use RuntimeException;

/**
 * 予約販売の操作を、今の状態では行えない（次の予約を始める。ADR 0073）。文は店の人に見せてよいもの
 */
final class PreorderRefused extends RuntimeException {}
