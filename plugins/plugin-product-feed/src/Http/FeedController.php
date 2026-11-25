<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed\Http;

use App\Core\RateLimit\Throttle;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Storefront\Access\ClientSource;
use EcSugar\PluginProductFeed\FeedCache;
use EcSugar\PluginProductFeed\FeedFormat;
use EcSugar\PluginProductFeed\FeedTokens;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * フィードの URL（/p/product-feed/{google|meta}/{トークン}.xml）。ログインなしで開く公開の経路で、Google と Meta が定期的に読みに来る。
 * 守りは推測されないトークン（FeedTokens。店の人が作り直せる）・店と接続元ごとの回数の上限・1 時間のキャッシュ（FeedCache）。
 * 出すのはショップに出ている商品だけ（核のショップの窓口から読む）。トークンが違う・止めた・公開していない店はどれも同じ 404
 * （準備中の店は、その前に核の関所〔RequireShopAccess〕が「準備中です」の画面へ送る。パスワードを入れた人にも 404）
 */
final class FeedController
{
    /** 1 つの店に、1 つの接続元から 1 時間に読める回数（Google は 1 日 1 回、Meta は 1 時間に 1 回ほど） */
    public const int PER_HOUR = 60;

    public function __construct(
        private readonly FeedTokens $tokens,
        private readonly FeedCache $cache,
        private readonly StorefrontAccessService $access,
    ) {}

    public function __invoke(Request $request, string $format, string $token): Response
    {
        // トークンを確かめる前に数える（違うトークンを試し続けても上限で止まる）
        $key = 'product-feed:read:'.(string) tenant()?->getTenantKey().':'.ClientSource::of($request->ip());

        if (! Throttle::allow($key, self::PER_HOUR, 3600)) {
            return self::plain('Too Many Requests', 429)->header('Retry-After', (string) Throttle::availableIn($key));
        }

        $feed = FeedFormat::tryFrom($format);

        if ($feed === null || ! $this->tokens->matches($feed, $token) || $this->access->status() !== ShopStatus::Open) {
            return self::plain('Not Found', 404);
        }

        $xml = $this->cache->xml($feed, (string) tenant()?->getAttribute('name'), url('/'));

        // トークンの入った URL なので、途中のキャッシュに置かず、検索に載せず、ほかへ参照元として渡さない（経路の宣言 secret-link で核が掛ける）
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private static function plain(string $text, int $status): Response
    {
        return response($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
