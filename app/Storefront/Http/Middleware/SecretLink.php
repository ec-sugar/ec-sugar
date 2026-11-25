<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use App\Storefront\Access\SecretLinks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * 「秘密の道筋の画面」の宣言（ADR 0081）。URL に署名か合い言葉（token など）を持つショップの経路に付ける。
 * 宣言した経路の応答には、核がまとめて次を掛ける（エラー・転送の応答〔403・404・419・429〕にも。掛けるのは一番外側の StorefrontSecurityHeaders）:
 *   - Referrer-Policy: no-referrer（次の画面・読み込み先・外のタグへ URL を渡さない）
 *   - Cache-Control: no-store, private（ブラウザ・途中の置き場に残さない）
 *   - X-Robots-Tag: noindex, nofollow（検索に載せない）
 *
 * 書き方は 2 つ:
 *   - `->middleware('secret-link:signed')`: 署名が要る画面。核が署名（期限・店の ID）を確かめ、合わなければ中身の無い 404（あるかどうかを教えない）。
 *     URL は SecretLinks::signed で作る
 *   - `->middleware('secret-link')`: 確かめは受け口がする画面（道筋の合い言葉を App が表と照らす・署名か会員のどちらかで開く・用途ごとの署名など）
 *
 * 署名か合い言葉を持つのにこの宣言の無い経路は、全部の経路を調べるテストが落とす（Tests\Support\RouteAudit の規則 10〜12）
 */
final class SecretLink
{
    public const string NAME = 'secret-link';

    public const string SIGNED = 'secret-link:signed';

    /** 検索に載せない X-Robots-Tag の値 */
    public const string NO_INDEX = 'noindex, nofollow';

    public function handle(Request $request, Closure $next, string $mode = 'checked'): Response
    {
        if ($mode !== 'checked' && $mode !== 'signed') {
            throw new \LogicException("秘密のリンクの宣言の書き方が違います（{$mode}）。secret-link か secret-link:signed");
        }

        abort_if($mode === 'signed' && ! SecretLinks::valid($request), 404);

        return $next($request);
    }

    /**
     * その経路が秘密の道筋の宣言を持つか（経路と群に書いたミドルウェアから。名前でもクラスでも）
     */
    public static function declaredOn(mixed $route): bool
    {
        if (! $route instanceof Route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            foreach ([self::NAME, self::class] as $name) {
                if ($middleware === $name || str_starts_with($middleware, $name.':')) {
                    return true;
                }
            }
        }

        return false;
    }
}
