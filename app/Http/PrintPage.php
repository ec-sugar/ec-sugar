<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;

/**
 * 印刷用のページ（納品書・領収書など。ブラウザの「印刷」で紙か PDF にする）の CSP（コンテンツの読み込みの決まり）。
 * 管理画面とショップの防御ヘッダは、この印の付いた要求の応答だけ、CSP をここの決まりに置き換える。
 * 決まりはどちらの CSP より狭い: スクリプトは一切動かさず、見た目はこの要求の nonce（使い捨ての合い札）の付いた <style> だけ、
 * 画像とフォームの送信先は同じオリジンだけ。印刷のページは 1 枚で完結させ、ビルドした CSS や外の資産を読まない
 */
final class PrintPage
{
    private const string NONCE = 'print_page.nonce';

    /**
     * この要求を印刷用のページにし、<style> に付ける nonce を返す（2 回呼んでも同じ値）
     */
    public static function nonce(Request $request): string
    {
        $nonce = $request->attributes->get(self::NONCE);

        if (! is_string($nonce)) {
            $nonce = bin2hex(random_bytes(16));
            $request->attributes->set(self::NONCE, $nonce);
        }

        return $nonce;
    }

    /**
     * 印刷用のページの CSP（印の無い要求は null。防御ヘッダは元の決まりのまま）
     */
    public static function csp(Request $request): ?string
    {
        $nonce = $request->attributes->get(self::NONCE);

        // 足すのは自分で作った 16 進の値だけ（ほかの値で CSP に別の決まりを足させない）
        if (! is_string($nonce) || preg_match('/\A[0-9a-f]{32}\z/', $nonce) !== 1) {
            return null;
        }

        return implode('; ', [
            "default-src 'none'",
            "script-src 'none'",
            "style-src 'nonce-{$nonce}'",
            "img-src 'self'",
            "form-action 'self'",
            "base-uri 'none'",
            "frame-ancestors 'none'",
        ]);
    }
}
