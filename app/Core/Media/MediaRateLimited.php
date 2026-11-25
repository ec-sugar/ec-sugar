<?php

declare(strict_types=1);

namespace App\Core\Media;

/**
 * 1 分に作り直せる写真の数に触れた（MediaQuota）。少し待てば上げられる
 */
final class MediaRateLimited extends InvalidImageException {}
