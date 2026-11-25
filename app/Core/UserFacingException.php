<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * 文をそのまま利用者に見せてよい例外の印（「入金の前は発送できません」など。内部の事情や秘密を含めない）。
 * 管理画面の API はこの例外を bootstrap/app.php で 1 か所でまとめて、{message, user_message} の JSON にする
 */
interface UserFacingException extends Throwable
{
    /** 答えの HTTP の状態（状態の衝突は 409、外のサービスに届かないなどは 503） */
    public function status(): int;
}
