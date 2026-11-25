<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ショップは JSON の本文を受けない（システム全体の見直しで見つけた）。
 * ショップの画面と JavaScript（カート・お気に入りなど）が送るのはフォームの形（application/x-www-form-urlencoded・multipart/form-data）だけ。
 * Content-Type が JSON の要求は、本文を読まずに 415 で断る。後ろの CSRF の確かめと「壊れた文字の拒否」（RejectMalformedInput）が JSON を丸ごと読み解く前に止め、
 * 大きな JSON でメモリを使い切らせる道（回数の制限より前に効く）を、ショップでは開けない（全体の文字の整えも、管理画面のホストの外では JSON を読まない。bootstrap/app.php）。
 *
 * 店頭のミドルウェア群（'storefront'）の、店を引いた直後（知らないホストは先に 404。店があるかを 415 で探らせない）・CSRF と壊れた文字の拒否の前に置く。
 * JSON を受ける経路は、本文を生のまま読んで署名を確かめる決済代行・LINE の Webhook だけ。Stripe の通知はセッションの無い群（storefront-media）にあり、
 * ここを通らない。App の Webhook（LINE 連携）は経路で `->withoutMiddleware(RefuseJsonBody::class)` にして外す
 * （大きさは nginx のショップの上限・全体の LimitJsonBody・受け口の上限で見る）
 */
final class RefuseJsonBody
{
    public function handle(Request $request, Closure $next): Response
    {
        // 本文を持たない読み出し（GET・HEAD）は、JSON の見出しが付いていても通す（郵便番号の検索などの読み出しを道具が JSON の形で呼ぶことがある。
        // 本文の大きさは入口の JsonBodyLimit が別に止める）。断るのは本文を送る書き込みだけ
        if (! $request->isJson() || $request->isMethodSafe()) {
            return $next($request);
        }

        $message = 'この形式の送信は受け付けていません。';

        return $request->expectsJson()
            ? new JsonResponse(['message' => $message], Response::HTTP_UNSUPPORTED_MEDIA_TYPE)
            : new Response($message, Response::HTTP_UNSUPPORTED_MEDIA_TYPE, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
