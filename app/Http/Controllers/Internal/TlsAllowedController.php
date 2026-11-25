<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Requests\Internal\TlsAllowedRequest;
use Illuminate\Http\Response;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * 入口の Caddy が、初めて来た名前の証明書をその場で取る（on-demand TLS）前に聞く（ADR 0031）。
 *
 * 取ってよいのは、管理画面のホストと、ショップのドメインの 1 つ下の名前（<店>.<ショップのドメイン>）のうち、
 * 中央のホスト（CENTRAL_DOMAINS。決済代行の通知を受ける名前）か中央 DB の domains にある名前だけ。ほかは 404
 * （知らない名前で証明書を取らせない。Let's Encrypt の回数の上限を食い潰させない）。
 * 店の独自ドメインと 2 つ下の名前は、domains にあっても今は取らない（入口の nginx が受けないので、取っても開けない。受ける形を作るときに広げる）。
 * 中央 DB だけを読む（店の文脈に入らない・店の DB に触らない）。書き込まない。
 */
final class TlsAllowedController
{
    public function __invoke(TlsAllowedRequest $request): Response
    {
        $domain = $request->domain();

        return response('', $this->allowed($domain) ? 200 : 404);
    }

    private function allowed(string $domain): bool
    {
        // 1 店舗の形（既定）は、Caddy が 2 つの名前を起動のときに取り、その場では取らない。ここで何も許さない（攻め口ごと無くす）
        if (config('tls.shops') !== 'multi') {
            return false;
        }

        if ($domain === config('admin.domain')) {
            return true;
        }

        $shopDomain = config('tls.shop_domain');

        // ショップのドメインの 1 つ下の名前だけ（形は TlsAllowedRequest で確かめ済み。ラベルに点は入らない）
        if (! is_string($shopDomain) || $shopDomain === '' || preg_match('/\A[a-z0-9-]+\.'.preg_quote($shopDomain, '/').'\z/D', $domain) !== 1) {
            return false;
        }

        /** @var list<string> $central */
        $central = config('tenancy.central_domains');

        if (in_array($domain, $central, true)) {
            return true;
        }

        /** @var class-string<Domain> $model */
        $model = config('tenancy.domain_model');

        return $model::query()->where('domain', $domain)->exists();
    }
}
