<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use App\Core\Sample\Demo\DemoShop;
use App\Core\Sample\Demo\DemoVisitors;
use Closure;
use Illuminate\Http\Request;
use Normalizer;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * デモの店の来訪者の関所（ADR 0086）。デモの店でなければ何もしない。
 *
 * - セッションに来訪者の合い言葉を 1 つ置く（無ければ作る。ログインでセッションの番号を作り直しても中身は引き継がれる）
 * - 送られた入力（本文とクエリ文字列の両方。Laravel の input() は両方を合わせて読む）のうち、メールアドレスになりうる値は、
 *   どれもこの来訪者だけのアドレスに置き換える。デモの店では他人のアドレスで注文・登録できず（そのアドレスへメールを送りつけられない）、
 *   注文・会員・申し込みのメールは来訪者ごとに分かれる。止めたメールは、そのアドレスの受け箱（このセッションだけが開ける）に入る
 *   - 項目の名前に email を含む値（入れ子の下も）は、形を問わず置き換える
 *   - ほかの項目も、全角などを NFKC で寄せた後に @ を含む 1 行の値は置き換える（台帳の索引は NFKC で寄せるので、全角の ＠ や
 *     引用符・空白を含む書き方〔"a b"@example.com〕も同じアドレスとして扱われうる。形を細かく見分けず、広く取る）
 *   - 深すぎる入れ子は捨てる（たどらずに残すと、置き換えを抜ける道になる）
 * - 答えに X-Robots-Tag: noindex を付ける（画面の meta の noindex に加えて。検索に載せない）
 */
final class DemoVisitor
{
    /** 置き換えを見る入力の深さの上限（これより深い入れ子は捨てる） */
    private const int MAX_DEPTH = 6;

    /** 1 行の値として見る長さの上限（これより長い値は自由な文。メールアドレスは 254 文字まで） */
    private const int MAX_ADDRESS = 320;

    public function handle(Request $request, Closure $next): Response
    {
        if (! DemoShop::active()) {
            return $next($request);
        }

        $token = self::token($request);

        if (! $request->isMethodSafe()) {
            $address = DemoVisitors::address($token);
            $bags = [$request->request, $request->query];

            if ($request->isJson()) {
                $bags[] = $request->json();
            }

            foreach ($bags as $bag) {
                self::replace($bag, $address);
            }
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * この来訪者の合い言葉（無ければ作ってセッションに置く）
     */
    public static function token(Request $request): string
    {
        $token = $request->session()->get(DemoVisitors::SESSION);

        if (! DemoVisitors::validToken($token)) {
            $token = DemoVisitors::newToken();
            $request->session()->put(DemoVisitors::SESSION, $token);
        }

        /** @var string $token */
        return $token;
    }

    /**
     * 値がメールアドレスになりうるか（置き換える値か）
     */
    public static function looksLikeAddress(string $value): bool
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
        $value = trim(is_string($normalized) ? $normalized : $value);

        return $value !== '' && str_contains($value, '@') && mb_strlen($value) <= self::MAX_ADDRESS && preg_match('/[\r\n]/', $value) !== 1;
    }

    private static function replace(ParameterBag $bag, string $address): void
    {
        $bag->replace(self::rewrite($bag->all(), $address, 0, false));
    }

    /**
     * @param  array<mixed>  $input
     * @param  bool  $email  上の項目の名前に email を含む（下の値は形を問わず置き換える）
     * @return array<mixed>
     */
    private static function rewrite(array $input, string $address, int $depth, bool $email): array
    {
        foreach ($input as $key => $value) {
            $named = $email || stripos((string) $key, 'email') !== false;

            if (is_array($value)) {
                // 深すぎる入れ子は捨てる（ショップの入力にこの深さの物は無い）
                $input[$key] = $depth < self::MAX_DEPTH ? self::rewrite($value, $address, $depth + 1, $named) : [];
            } elseif (is_string($value) && (($named && trim($value) !== '') || self::looksLikeAddress($value))) {
                $input[$key] = $address;
            }
        }

        return $input;
    }
}
