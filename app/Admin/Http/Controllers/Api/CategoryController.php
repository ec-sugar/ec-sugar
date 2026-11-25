<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Core\Catalog\Models\Category;
use App\Core\Catalog\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 商品カテゴリ（EnterTenant の後）。形の検査と木の守りは CategoryService が持つ（ここは受け渡しだけ）。
 * 経路の ID はモデルに結びつけず、テナント文脈の中で探す。
 */
final class CategoryController
{
    public function __construct(private readonly CategoryService $categories) {}

    public function index(): JsonResponse
    {
        return $this->tree();
    }

    public function store(Request $request): JsonResponse
    {
        $this->categories->create($request->only(['name', 'slug', 'parent_id']), TenantActor::of($request));

        return $this->tree(201);
    }

    public function update(Request $request, string $categoryId): JsonResponse
    {
        $this->categories->update($this->find($categoryId), $request->only(['name', 'slug', 'image_id', 'seo_title', 'seo_description']), TenantActor::of($request));

        return $this->tree();
    }

    public function move(Request $request, string $categoryId): JsonResponse
    {
        $data = $request->validate(['parent_id' => ['present', 'nullable', 'ulid']]);
        $this->categories->move($this->find($categoryId), $data['parent_id'], TenantActor::of($request));

        return $this->tree();
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['present', 'nullable', 'ulid'],
            'ids' => ['present', 'array', 'max:200'],
            'ids.*' => ['ulid', 'distinct'],
        ]);
        /** @var list<string> $ids */
        $ids = array_values($data['ids']);
        $this->categories->reorder($data['parent_id'], $ids, TenantActor::of($request));

        return $this->tree();
    }

    public function destroy(Request $request, string $categoryId): JsonResponse
    {
        $this->categories->delete($this->find($categoryId), TenantActor::of($request));

        return $this->tree();
    }

    public function assign(Request $request, string $listingId): JsonResponse
    {
        $listing = ListingController::find($listingId);
        $data = $request->validate([
            'categories' => ['present', 'array', 'list'],
            'categories.*' => ['string'],
            'primary' => ['present', 'nullable', 'string'],
        ]);
        /** @var list<string> $ids */
        $ids = $data['categories'];
        $this->categories->assign($listing, $ids, $data['primary'], TenantActor::of($request));

        return response()->json(['listing' => ListingController::detail($listing)]);
    }

    private function tree(int $status = 200): JsonResponse
    {
        return response()->json(['categories' => $this->categories->tree(), 'max_depth' => Category::MAX_DEPTH], $status);
    }

    private function find(string $id): Category
    {
        return Category::query()->findOrFail($id);
    }
}
