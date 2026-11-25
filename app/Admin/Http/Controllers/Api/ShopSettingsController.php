<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Core\Section\Models\ShopPage;
use App\Core\Section\PageSpec;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Themes\ThemeSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 店の設定（EnterTenant の後）: 送料・注文の案内・特商法の表記・プライバシーポリシー・ショップの公開とパスワード・同意の帯（テナント DB）と、店のテーマ（中央のテナントの data.theme）。
 * 見せ方（お知らせの帯・ヒーローなど）はテーマの区画（ThemeContentController。ADR 0028）。
 */
final class ShopSettingsController
{
    public function __construct(
        private readonly ShopSettingsService $settings,
        private readonly ThemeRegistry $themes,
        private readonly StorefrontAccessService $access,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->present());
    }

    public function updateTheme(Request $request, ThemeSelector $selector): JsonResponse
    {
        $data = $request->validate(['theme' => ['present', 'nullable', 'string', 'in:'.implode(',', $this->themes->names())]]);
        $operator = $request->user('operator');
        assert($operator instanceof Operator);

        $selector->change(Tenant::current(), $data['theme'], $operator->actor(), $operator, $request->ip());

        return response()->json($this->present());
    }

    /**
     * テーマを替える前の知らせ（ADR 0039 区切り 3）: 替えると写せない区画（テーマ固有の区画）がある自由なページと、写せない区画・写す区画の数。
     * 読むだけなので閲覧の段階から。theme は替える先（空なら既定のテーマ）
     */
    public function themeImpact(Request $request, ThemeSelector $selector): JsonResponse
    {
        $data = $request->validate(['theme' => ['nullable', 'string', 'in:'.implode(',', $this->themes->names())]]);
        $impact = $selector->impact(Tenant::current(), $data['theme'] ?? null);
        $pages = ShopPage::query()->whereIn('id', array_map(static fn (array $p): string => (string) PageSpec::freeId($p['name']), $impact))->get()->keyBy('id');

        return response()->json(['pages' => array_values(array_filter(array_map(static function (array $p) use ($pages): ?array {
            $page = $pages->get((string) PageSpec::freeId($p['name']));

            return $page instanceof ShopPage ? ['id' => $page->id, 'title' => $page->title, 'slug' => $page->slug, 'dropped' => $p['dropped'], 'copied' => $p['copied']] : null;
        }, $impact)))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(): array
    {
        $chosen = Tenant::current()->getAttribute('theme');
        $access = $this->access->current();

        return [
            'order_terms' => $this->settings->orderTerms(),
            'sell_free_items' => $this->settings->sellsFreeItems(),
            // 解析・広告のタグの同意の帯を出すか（既定は出さない）
            'consent_banner' => $this->settings->showsConsentBanner(),
            'legal' => $this->settings->legal(),
            // プライバシーポリシーの本文（ADR 0094。無ければ null）
            'privacy_policy' => $this->settings->privacyPolicy(),
            // ショップの公開。パスワードは置いたかどうかだけ（値もハッシュも返さない）
            'publish' => ['status' => $access['status']->value, 'has_password' => $access['has_password']],
            'theme' => [
                'chosen' => is_string($chosen) && $this->themes->has($chosen) ? $chosen : null,
                'default' => $this->themes->active()->name,
                'options' => array_map(fn (string $name): array => ['name' => $name, 'label' => $this->themes->get($name)->label], $this->themes->names()),
            ],
        ];
    }

    public function updateOrderTerms(Request $request): JsonResponse
    {
        $this->settings->setOrderTerms($request->only(['delivery', 'returns']), TenantActor::of($request));

        return response()->json($this->present());
    }

    public function updateFreeItems(Request $request): JsonResponse
    {
        $data = $request->validate(['sell' => ['required', 'boolean']]);
        $this->settings->setSellFreeItems((bool) $data['sell'], TenantActor::of($request));

        return response()->json($this->present());
    }

    /**
     * 解析・広告のタグの同意の帯を出すか。ほかの店の設定と同じく運用の段階から
     */
    public function updateConsentBanner(Request $request): JsonResponse
    {
        $data = $request->validate(['show' => ['required', 'boolean']], attributes: ['show' => '同意バナー']);
        $this->settings->setConsentBanner((bool) $data['show'], TenantActor::of($request));

        return response()->json($this->present());
    }

    public function updateLegal(Request $request): JsonResponse
    {
        $this->settings->setLegal($request->only(['items']), TenantActor::of($request));

        return response()->json($this->present());
    }

    /**
     * プライバシーポリシーの本文（ADR 0094）。ほかの店の設定と同じく運用の段階から（閲覧は 403）。形の検査はサービスで
     */
    public function updatePrivacyPolicy(Request $request): JsonResponse
    {
        $this->settings->setPrivacyPolicy($request->only(['body']), TenantActor::of($request));

        return response()->json($this->present());
    }

    /**
     * ショップの公開の状態（準備中・公開）
     */
    public function updatePublish(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', Rule::enum(ShopStatus::class)]], attributes: ['status' => 'ショップの公開']);
        $this->access->setStatus(ShopStatus::from($data['status']), TenantActor::of($request));

        return response()->json($this->present());
    }

    /**
     * 準備中のショップに入るパスワード。null で外す。形の検査はサービスで（値は記録にもログにも残さない）
     */
    public function updateStorefrontPassword(Request $request): JsonResponse
    {
        $request->validate(['password' => ['present', 'nullable', 'string']]);
        $password = $request->input('password');
        $this->access->setPassword(is_string($password) ? $password : null, TenantActor::of($request));

        return response()->json($this->present());
    }
}
