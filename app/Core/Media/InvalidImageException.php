<?php

declare(strict_types=1);

namespace App\Core\Media;

use RuntimeException;

/**
 * 受け付けられない画像。メッセージはそのまま利用者に見せてよい文（何が悪いかと直し方）。
 * 置き場の上限に触れたときは、下の 2 つの型で投げる（受け取る側は今までどおりこの型で受けてよい。CSV の取り込みは型で待つか止めるかを分ける）
 */
class InvalidImageException extends RuntimeException {}
