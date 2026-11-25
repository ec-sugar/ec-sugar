<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use Illuminate\Http\Response;

/**
 * 会員の画面の応答（ADR 0044 の 3）。ブラウザにも残さない（共用の端末でログアウトの後に「戻る」で見えないように）
 */
final class MemberPage
{
    public static function private(Response $response): Response
    {
        return $response->header('Cache-Control', 'no-store, private');
    }
}
