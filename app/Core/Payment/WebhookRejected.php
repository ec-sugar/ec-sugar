<?php

declare(strict_types=1);

namespace App\Core\Payment;

use RuntimeException;

/**
 * 決済代行の通知を受け取らない（署名が無い・違う・古い、形が違う、鍵が無い、test と live の取り違え）。理由はログにだけ残す
 */
final class WebhookRejected extends RuntimeException {}
