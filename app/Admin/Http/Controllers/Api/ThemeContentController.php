<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Plugin\Services\AppSectionCatalog;
use App\Core\Section\Models\ShopPage;
use App\Core\Section\PageSpec;
use App\Core\Section\PageTemplates;
use App\Core\Section\SectionSchema;
use App\Core\Section\Services\ShopPageService;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\StaleThemeVersionException;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\ThemePreviewLink;
use App\Storefront\View\SectionResolver;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * テーマの区画の値（EnterTenant の後。ADR 0028 区切り 2）: 宣言と値を読む・下書きを保存する・公開する。
 * 入っているテーマならどれでも編集できる（切り替える前に準備できる）。宣言はテーマの theme.json から（画面はこれで欄を作る）。
 * 読むのは閲覧、変えるのは運用（EnterTenant の既定）。
 *
 * - /themes/{テーマ}/pages/{ページ}: ページの区画の並び（ADR 0039。足す・消す・並べ替え・パターン）。編集の画面（区切り 2）が使う。
 *   中身を入れるのはこの口だけ（前の「種類 => 値」の口 …/content は で外した。今の形の読み書きは、コマンドと見本の店のために
 *   ThemeContentService::saveDraft に残っている）
 * - /themes/{テーマ}/pages/{ページ}/preview: 下書きのプレビューの署名つきのリンク（編集の画面の真ん中の枠）
 * - /themes/{テーマ}/pages/{ページ}/template: ページの雛形（PageTemplates）で並びを作り直す。下書きに入るだけで、公開するまでショップは変わらない
 *
 * ページは home と、自由なページ page-{ID}（ADR 0039 区切り 3。店にあってゴミ箱に入っていないページだけ。無ければ 404）。
 * 自由なページの「公開する」は、中身（バージョンごと）の公開と、そのページを公開の状態にすることを 1 つのトランザクションで行う
 *
 * ページの区画の並びでは、店で使う App の区画（ADR 0040）も置ける（AppSectionCatalog がテーマの宣言に足す）。止めた App の区画は出さず、置けない
 */
final class ThemeContentController
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ThemeContentService $content,
        private readonly ShopPageService $pages,
        private readonly AppSectionCatalog $apps,
        private readonly SectionResolver $resolver,
    ) {}

    /**
     * ページの区画の並び（ADR 0039）: 宣言・ページの宣言・下書きの並び（バージョンが無ければテーマの最初の並び）
     */
    public function showPage(string $theme, string $page): JsonResponse
    {
        $definition = $this->theme($theme);

        return response()->json($this->presentPage($definition, $this->page($definition, $page)));
    }

    /**
     * ページの区画の並びを下書きに保存する（足す・消す・並べ替え・パターンの選び直し・値の変更をまとめて置き換える）
     */
    public function updatePage(Request $request, string $theme, string $page): JsonResponse
    {
        $definition = $this->theme($theme);
        $spec = $this->page($definition, $page);
        // 自由なページは核の汎用の区画でも作れるので、テーマが区画を宣言していなくてもよい。店で使う App の区画を足す（ADR 0040）
        $schema = $this->apps->schema($spec->free ? $definition->schema() : $this->schema($definition));
        $data = $request->validate([
            'version_id' => ['present', 'nullable', 'ulid'],
            'revision' => ['required', 'integer', 'min:0'],
            // 中の形は核（SectionValues::validatePage）が宣言どおりに確かめる。ここでは並びであることと数だけ
            'sections' => ['present', 'array', 'list', 'max:'.PageSpec::MAX_SECTIONS],
        ]);

        try {
            $this->content->savePage($definition->name, $schema, $spec->name, $data['version_id'], (int) $data['revision'], $data['sections'], TenantActor::of($request));
        } catch (StaleThemeVersionException $e) {
            return $this->stale($e);
        }

        return response()->json($this->presentPage($definition, $spec));
    }

    /**
     * ページの雛形で並びを作り直す。今の並びを雛形の並びに置き換えて下書きに保存する（公開はしない）。
     * 外枠が読む区画（お知らせの帯・フッターなど、ページに描く部品の無い区画）は今の並びから残す。
     * 置けない種類と上限は、いつもの保存と同じ確かめを通る。決まった並びのページは作り直せない（足す・消すができないので）
     */
    public function applyTemplate(Request $request, string $theme, string $page): JsonResponse
    {
        $definition = $this->theme($theme);
        $spec = $this->page($definition, $page);

        if ($spec->fixed) {
            $message = 'このテーマはセクションの並びが決まっているため、雛形から作り直せません。';

            return response()->json(['message' => $message, 'user_message' => $message], 422);
        }

        $schema = $this->apps->schema($spec->free ? $definition->schema() : $this->schema($definition));
        $data = $request->validate([
            'version_id' => ['present', 'nullable', 'ulid'],
            'revision' => ['required', 'integer', 'min:0'],
            'template' => ['required', 'string', Rule::in(PageTemplates::names())],
        ]);
        $draws = $this->resolver->drawsInPage($definition);
        $current = $this->content->draftPage($this->content->current($definition->name), $schema, $spec->name);
        $sections = PageTemplates::build($data['template'], $schema, $spec, $current, static fn (array $section): bool => ! $draws($section['type'], $section['pattern']));

        try {
            $this->content->savePage($definition->name, $schema, $spec->name, $data['version_id'], (int) $data['revision'], $sections, TenantActor::of($request));
        } catch (StaleThemeVersionException $e) {
            return $this->stale($e);
        }

        return response()->json($this->presentPage($definition, $spec));
    }

    /**
     * 公開する（バージョンごと。すべてのページの下書きをショップに出す）。答えはこのページの形。
     * 自由なページでは、そのページも公開の状態にする（同じトランザクションで。どちらかだけにはならない）
     */
    public function publishPage(Request $request, string $theme, string $page): JsonResponse
    {
        $definition = $this->theme($theme);
        $spec = $this->page($definition, $page);
        $schema = $this->apps->schema($spec->free ? $definition->schema() : $this->schema($definition));
        $data = $request->validate([
            'version_id' => ['required', 'ulid'],
            'revision' => ['required', 'integer', 'min:0'],
        ]);
        $actor = TenantActor::of($request);

        try {
            DB::transaction(function () use ($definition, $schema, $spec, $data, $actor): void {
                $this->content->publish($definition->name, $schema, $data['version_id'], (int) $data['revision'], $actor);

                if (($id = PageSpec::freeId($spec->name)) !== null) {
                    $this->pages->publish($id, $actor);
                }
            });
        } catch (StaleThemeVersionException $e) {
            return $this->stale($e);
        }

        return response()->json($this->presentPage($definition, $spec));
    }

    /**
     * 下書きのプレビューのリンク（ADR 0039 区切り 2）。管理画面のログインはショップのホストに届かないので、そのテーマのそのページの
     * 下書きだけを開ける署名つきの期限つきのリンク（30 分）を作って返す。編集の画面は真ん中の枠（iframe）にこれを開く。
     * 閲覧の段階から（見るだけの操作）。作ったことは、どのテーマ・ページかと一緒に監査ログに 1 行残す（EnterTenant の監査は経路の型だけ）
     */
    public function previewPage(Request $request, AuditLog $audit, string $theme, string $page): JsonResponse
    {
        $definition = $this->theme($theme);
        $spec = $this->page($definition, $page);
        $tenant = Tenant::current();
        $link = ThemePreviewLink::editorOrigin() === null ? null : ThemePreviewLink::issue($tenant, $definition->name, $spec->name);

        if ($link === null) {
            $message = 'ショップの URL が設定されていないため、プレビューを開けません。';

            return response()->json(['message' => $message, 'user_message' => $message], 422);
        }

        $operator = Auth::guard('operator')->user();
        $audit->record('operator.theme_preview_issued', $operator instanceof Operator ? $operator : null, [
            'theme' => $definition->name,
            'page' => $spec->name,
            'expires_at' => $link['expires_at']->toIso8601String(),
        ], (string) $tenant->getTenantKey(), $request->ip());

        return response()->json(['url' => $link['url'], 'expires_at' => $link['expires_at']->toIso8601String()]);
    }

    /**
     * ページの宣言。自由なページは店にあってゴミ箱に入っていないものだけ（題をページの名前にする）
     */
    private function page(ThemeDefinition $theme, string $name): PageSpec
    {
        $spec = $this->apps->schema($theme->schema())->page($name) ?? abort(404);
        $id = PageSpec::freeId($name);

        if ($id === null) {
            return $spec;
        }

        $page = $this->pages->find($id) ?? abort(404);

        return $spec->withTitle($page->title);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPage(ThemeDefinition $theme, PageSpec $page): array
    {
        $schema = $this->apps->schema($theme->schema());
        $version = $this->content->current($theme->name);
        // JSON で欄の値が空の対応（{}）になるように
        $object = static fn (array $values): object => (object) $values;

        return [
            'theme' => [
                'name' => $theme->name,
                'label' => $theme->label,
                'active' => $this->themes->forTenant(Tenant::current())->name === $theme->name,
            ],
            // このページに置ける種類の宣言（自由なページでは核の汎用の区画も）
            'schema' => (object) $schema->toArrayFor($page),
            'page' => $page->toArray(),
            // 自由なページの住所と公開の状態（トップでは null）
            'shop_page' => $this->shopPage($page),
            'version' => $version === null ? null : self::version($version),
            // ページの雛形。このテーマのこのページで、どの区画がどのパターンで入るか（決まった並びのページでは空）
            'templates' => $page->fixed ? [] : PageTemplates::catalog($schema, $page),
            // 下書きの並び（バージョンが無い・まだこのページを保存していなければ、テーマの最初の並びを空の値で）
            'sections' => array_map(static fn (array $s): array => ['values' => $object($s['values']),
                'blocks' => array_map(static fn (array $b): array => ['type' => $b['type'], 'values' => $object($b['values'])], $s['blocks'])] + $s,
                $this->content->draftPage($version, $schema, $page->name)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shopPage(PageSpec $spec): ?array
    {
        $id = PageSpec::freeId($spec->name);
        $page = $id === null ? null : $this->pages->find($id);

        return $page instanceof ShopPage ? [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'status' => $page->status,
            'url' => StorefrontUrls::of(Tenant::current(), route('storefront.page', ['slug' => $page->slug], false)),
        ] : null;
    }

    private function theme(string $name): ThemeDefinition
    {
        abort_unless($this->themes->has($name), 404);

        return $this->themes->get($name);
    }

    /**
     * 変えるときの宣言。区画を 1 つも宣言しないテーマは変えられない（空のバージョンを作ると、前の見せ方の控えが写らなくなる）
     */
    private function schema(ThemeDefinition $theme): SectionSchema
    {
        $schema = $theme->schema();

        return $schema->sections === [] ? abort(response()->json(['message' => 'このテーマには、編集できるセクションがありません。'], 422)) : $schema;
    }

    private function stale(StaleThemeVersionException $e): JsonResponse
    {
        return response()->json([
            'message' => '別の操作で先に更新されています。最新の内容を読み込み直してから、もう一度保存してください。',
            'version_id' => $e->versionId,
            'current_revision' => $e->currentRevision,
        ], 409);
    }

    /**
     * バージョンの様子（見た目の口〔ThemeStyleController〕も同じ形で返す）
     *
     * @return array<string, mixed>
     */
    public static function version(ThemeVersion $version): array
    {
        return [
            'id' => $version->id,
            'name' => $version->name,
            'revision' => $version->revision,
            'published_at' => $version->published_at?->toIso8601String(),
            // 公開してから下書きを変えたか（公開のボタンを出すかどうか）
            'unpublished_changes' => $version->published === null || $version->published !== $version->draft,
        ];
    }
}
