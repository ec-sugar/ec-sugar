<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\ImageProcessor;
use App\Core\Media\InvalidImageException;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 商品の写真（EnterTenant の後）。受け取ったファイルは ListingImageService が作り直してから保存する。
 * 経路の ID はモデルに結びつけず、テナント文脈の中で、この商品の写真であることを確かめて探す。
 */
final class ListingImageController
{
    public function __construct(private readonly ListingImageService $images) {}

    public function store(Request $request, string $listingId): JsonResponse
    {
        $listing = ListingController::find($listingId);
        $data = $request->validate([
            // 大きさ（KB）の検査はここでも。形式は送られてきた種類を信じず、ImageProcessor が中身で判定する
            'image' => ['required', 'file', 'max:'.intdiv(ImageProcessor::MAX_BYTES, 1024)],
            'alt' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $file = $data['image'];
        assert($file instanceof UploadedFile);

        try {
            $image = $this->images->add($listing, (string) $file->getRealPath(), $data['alt'] ?? null, TenantActor::of($request));
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }

        return response()->json(['image' => self::present($image), 'listing' => ListingController::detail($listing)], 201);
    }

    public function update(Request $request, string $listingId, string $imageId): JsonResponse
    {
        $image = $this->find($listingId, $imageId);
        $data = $request->validate(['alt' => ['present', 'nullable', 'string', 'max:200']]);

        return response()->json(['image' => self::present($this->images->updateAlt($image, $data['alt'], TenantActor::of($request)))]);
    }

    public function order(Request $request, string $listingId): JsonResponse
    {
        $listing = ListingController::find($listingId);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.ListingImageService::MAX_PER_LISTING],
            'ids.*' => ['required', 'ulid', 'distinct'],
        ]);

        try {
            /** @var list<string> $ids */
            $ids = array_values($data['ids']);
            $this->images->reorder($listing, $ids, TenantActor::of($request));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['ids' => $e->getMessage()]);
        }

        return response()->json(['listing' => ListingController::detail($listing)]);
    }

    public function destroy(Request $request, string $listingId, string $imageId): JsonResponse
    {
        $this->images->remove($this->find($listingId, $imageId), TenantActor::of($request));

        return response()->json(['listing' => ListingController::detail(ListingController::find($listingId))]);
    }

    /**
     * 管理画面で見せる写真（下書きの商品の写真も見える。店頭は公開中の商品だけ）
     */
    public function show(string $listingId, string $imageId, string $variant): Response
    {
        abort_unless(array_key_exists($variant, ImageProcessor::VARIANTS), 404);
        $image = $this->find($listingId, $imageId);
        $tenant = Tenant::current();
        $path = ListingImage::path((string) $tenant->getTenantKey(), $image->listing_id, $image->id, $variant);
        $disk = $this->images->disk();
        abort_unless($disk->exists($path), 404);

        return response((string) $disk->get($path), 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ListingImage $image): array
    {
        return [
            'id' => $image->id,
            'position' => $image->position,
            'alt' => $image->alt,
            'width' => $image->width,
            'height' => $image->height,
        ];
    }

    private function find(string $listingId, string $imageId): ListingImage
    {
        return ListingImage::query()->whereKey($imageId)->where('listing_id', ListingController::find($listingId)->id)->firstOrFail();
    }
}
