<?php

declare(strict_types=1);

namespace App\Tenancy;

use RuntimeException;

/**
 * キューの Redis の接続に店の名札（接頭辞）が付く設定で起動しようとした。
 * このまま動くと、ショップで入れた仕事が中央の送信担当に届かず、黙って送られない。
 */
final class QueueConnectionMisconfigured extends RuntimeException {}
