<?php

declare(strict_types=1);

namespace App\Core\Media;

use RuntimeException;

/**
 * 外の URL から写真を取れなかった（RemoteImages）。メッセージはそのまま店の人に見せてよい文（取れなかった理由）
 */
final class RemoteImageRefused extends RuntimeException {}
