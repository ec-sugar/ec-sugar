<?php

declare(strict_types=1);

namespace App\Core\Media;

/**
 * 写真の置き場の量の上限（店の合計・その日の量）に触れた（MediaQuota）。待っても今日は空かないので、まとめての取り込みはここで止める
 */
final class MediaQuotaExceeded extends InvalidImageException {}
