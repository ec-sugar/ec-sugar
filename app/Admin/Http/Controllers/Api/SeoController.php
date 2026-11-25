<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Core\Shop\Models\Redirect;
use App\Core\Shop\Seo\RedirectPath;
use App\Core\Shop\Seo\SeoText;
use App\Core\Shop\Services\RedirectService;
use App\Core\Shop\Services\SeoSettingsService;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 設定 › SEO（ADR 0061。EnterTenant の後）: トップの題と説明・共有の既定の写真・転送の一覧。
 * 形の検査と鎖・上限の守りは核（SeoSettingsService・RedirectService）が持つ（ここは受け渡しだけ）。経路の ID はモデルに結びつけない
 */
final class SeoController
{
    public function __construct(
        private readonly SeoSettingsService $settings,
        private readonly RedirectService $redirects,
    ) {}

    public function show(): JsonResponse
    {
        return $this->present();
    }

    public function update(Request $request): JsonResponse
    {
        $this->settings->set($request->only(['home_title', 'home_description', 'share_image_id']), TenantActor::of($request));

        return $this->present();
    }

    public function storeRedirect(Request $request): JsonResponse
    {
        $this->redirects->add($request->only(['from', 'to']), TenantActor::of($request));

        return $this->present(201);
    }

    public function destroyRedirect(Request $request, string $redirectId): JsonResponse
    {
        $this->redirects->remove($redirectId, TenantActor::of($request));

        return $this->present();
    }

    private function present(int $status = 200): JsonResponse
    {
        $tenant = Tenant::current();

        return response()->json([
            'settings' => $this->settings->get(),
            // 検索の結果の試し見に使う（店の名前とショップの URL）
            'shop_name' => (string) $tenant->getAttribute('name'),
            'shop_url' => StorefrontUrls::of($tenant, '/'),
            'limits' => [
                'title' => SeoText::TITLE_MAX,
                'description' => SeoText::DESCRIPTION_MAX,
                'title_guide' => SeoText::TITLE_GUIDE,
                'description_guide' => SeoText::DESCRIPTION_GUIDE,
                'redirects' => Redirect::MAX,
                'path' => RedirectPath::MAX,
            ],
            'redirects' => $this->redirects->list()->map(static fn (Redirect $r): array => [
                'id' => $r->id,
                'from' => $r->from_path,
                'to' => $r->to_path,
                'source' => $r->source,
                'created_at' => $r->created_at->toIso8601String(),
            ])->values()->all(),
        ], $status);
    }
}
