<?php

declare(strict_types=1);

namespace App\Core\Media;

use RuntimeException;

/**
 * 写真の置き場（media）が、この環境のものではない。別の環境の店の写真を消さないように、消す処理を断る。
 * メッセージは運営と開発者向け（直し方を書く）。利用者には見せない。
 */
final class ForeignMediaRootException extends RuntimeException {}
