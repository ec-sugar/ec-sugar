<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 壊れた文字（不正な UTF-8）と NUL 文字を含む入力を、入口で断る（店頭にも掛けたのは ）。
 * JSON を求める呼び出し（管理画面の API）には 422、画面（店頭）には 400 のエラーの画面を返す。
 *
 * そのまま進むと、Postgres が「不正なバイト列」で拒んで 500 になったり、応答の JSON を作れずに 500 になったりする。
 * 攻撃者はわざとこういう値を送れるので、個々の検査に頼らず、全経路の手前で止める。
 * 経路の引数（URL の一部）は、経路の形の制約（whereUlid など）で別に絞っている。
 */
final class RejectMalformedInput
{
    // 入れ子の深さの上限（深すぎる JSON で再帰を重くさせない）
    private const int MAX_DEPTH = 16;

    public function handle(Request $request, Closure $next): Response
    {
        $field = $this->firstMalformed($request->query->all(), '', 0)
            ?? ($request->isJson() ? $this->firstMalformed($request->json()->all(), '', 0) : $this->firstMalformed($request->request->all(), '', 0));

        if ($field !== null) {
            if (! $request->expectsJson()) {
                abort(400, '使えない文字が含まれています。');
            }

            throw ValidationException::withMessages([$field === '' ? 'input' : $field => '使えない文字が含まれています。']);
        }

        return $next($request);
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    private function firstMalformed(array $input, string $prefix, int $depth): ?string
    {
        if ($depth > self::MAX_DEPTH) {
            return $prefix;
        }

        foreach ($input as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (! self::clean((string) $key)) {
                return 'input';
            }

            if (is_array($value)) {
                $found = $this->firstMalformed($value, $name, $depth + 1);

                if ($found !== null) {
                    return $found;
                }
            } elseif (is_string($value) && ! self::clean($value)) {
                return mb_check_encoding($name, 'UTF-8') ? $name : 'input';
            }
        }

        return null;
    }

    private static function clean(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }
}
