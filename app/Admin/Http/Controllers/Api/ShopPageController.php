<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Core\Plugin\Services\AppSectionCatalog;
use App\Core\Section\Models\ShopPage;
use App\Core\Section\PageTemplates;
use App\Core\Section\Services\ShopPageService;
use App\Core\Theme\Services\StaleThemeVersionException;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * 自由なページ（EnterTenant の後。ADR 0039 区切り 3）: 一覧・作る・題と URL とメニューと公開の状態を変える・並べる・ゴミ箱へ・戻す・完全に消す。
 * 形の検査と slug の守りは ShopPageService が持つ（ここは受け渡しだけ）。中身（区画の並び）はページの編集の画面
 * （/themes/{テーマ}/pages/page-{ID}）で変える。読むのは閲覧、変えるのは運用（EnterTenant の既定）。
 * 経路の ID はモデルに結びつけず、テナント文脈の中で探す。答えはいつも一覧の全体
 *
 * 作るときにページの雛形（PageTemplates）を選べる: ページを作るのと、ショップで使っているテーマの下書きに雛形の並びを入れるのを
 * 1 つのトランザクションで行う（どちらかだけにはならない）。選ばなければ今までどおりテーマの最初の並び（白紙）。答えの templates は
 * ショップで使っているテーマの自由なページで、どの区画がどのパターンで入るか（選ぶ画面の絵のため）
 */
final class ShopPageController
{
    public function __construct(
        private readonly ShopPageService $pages,
        private readonly ThemeRegistry $themes,
        private readonly ThemeContentService $content,
        private readonly AppSectionCatalog $apps,
    ) {}

    public function index(): JsonResponse
    {
        return $this->list();
    }

    public function store(Request $request): JsonResponse
    {
        $template = $request->validate(['template' => ['nullable', 'string', Rule::in(PageTemplates::names())]])['template'] ?? null;
        $actor = TenantActor::of($request);

        try {
            $page = DB::transaction(function () use ($request, $template, $actor): ShopPage {
                $page = $this->pages->create($request->only(['title', 'slug', 'in_nav']), $actor);

                if (is_string($template)) {
                    $this->fillFromTemplate($page, $template, $actor);
                }

                return $page;
            });
        } catch (StaleThemeVersionException) {
            $message = '別の操作で先にテーマの内容が更新されました。もう一度作成してください。';

            return response()->json(['message' => $message, 'user_message' => $message], 409);
        }

        return $this->list(201, $page);
    }

    /**
     * 作ったページの中身を、ショップで使っているテーマの下書きに雛形の並びで入れる（公開はしない。置けない種類と上限はいつもの保存の確かめを通る）
     */
    private function fillFromTemplate(ShopPage $page, string $template, string $actor): void
    {
        $theme = $this->themes->forTenant(Tenant::current());
        $schema = $this->apps->schema($theme->schema());
        $spec = $schema->page($page->pageName()) ?? throw new LogicException('自由なページの宣言がありません');
        $sections = PageTemplates::build($template, $schema, $spec, [], static fn (): bool => false) ?? [];
        $version = $this->content->current($theme->name);

        $this->content->savePage($theme->name, $schema, $spec->name, $version?->id, $version->revision ?? 0, $sections, $actor);
    }

    public function update(Request $request, string $pageId): JsonResponse
    {
        $this->pages->update($pageId, $request->only(['title', 'slug', 'in_nav', 'status', 'seo_title', 'seo_description']), TenantActor::of($request));

        return $this->list();
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'list', 'max:'.ShopPage::MAX_PAGES],
            'ids.*' => ['ulid', 'distinct'],
        ]);
        /** @var list<string> $ids */
        $ids = $data['ids'];
        $this->pages->reorder($ids, TenantActor::of($request));

        return $this->list();
    }

    public function destroy(Request $request, string $pageId): JsonResponse
    {
        $this->pages->trash($pageId, TenantActor::of($request));

        return $this->list();
    }

    public function restore(Request $request, string $pageId): JsonResponse
    {
        $this->pages->restore($pageId, TenantActor::of($request));

        return $this->list();
    }

    /**
     * ゴミ箱のページを完全に消す（戻せない。中身もすべてのテーマから消える）
     */
    public function purge(Request $request, string $pageId): JsonResponse
    {
        $this->pages->purge($pageId, TenantActor::of($request));

        return $this->list();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(): array
    {
        $schema = $this->apps->schema($this->themes->forTenant(Tenant::current())->schema());

        return PageTemplates::catalog($schema, $schema->freePage());
    }

    private function list(int $status = 200, ?ShopPage $created = null): JsonResponse
    {
        $tenant = Tenant::current();
        $present = static fn (ShopPage $page): array => [
            'id' => $page->id,
            // ページの編集の画面と中身の API で使う名前（page-{ID}）
            'name' => $page->pageName(),
            'title' => $page->title,
            'slug' => $page->slug,
            'in_nav' => $page->in_nav,
            'status' => $page->status,
            // 検索の結果に出す題と説明（ADR 0061。空なら自動）
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'url' => StorefrontUrls::of($tenant, route('storefront.page', ['slug' => $page->slug], false)),
            'updated_at' => $page->updated_at->toIso8601String(),
            'deleted_at' => $page->deleted_at?->toIso8601String(),
        ];

        return response()->json(array_filter([
            'pages' => $this->pages->list()->map($present)->values()->all(),
            'trash' => $this->pages->list(trashed: true)->map($present)->values()->all(),
            'max_pages' => ShopPage::MAX_PAGES,
            // ページの雛形（作るときに選ぶ。ショップで使っているテーマの自由なページで、どの区画がどのパターンで入るか）
            'templates' => $this->templates(),
            'created' => $created === null ? null : $present($created),
        ], static fn (mixed $value): bool => $value !== null), $status);
    }
}
