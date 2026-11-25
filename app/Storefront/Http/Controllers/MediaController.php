<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\Models\ShopMedia;
use App\Core\Media\Services\ShopMediaService;
use App\Models\Tenant;
use App\Storefront\Access\ListingPreviewLink;
use App\Storefront\Access\MemberPhotoLink;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Http\Middleware\StorefrontSecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 店頭の商品の写真とショップの写真（ADR 0028）。公開中の商品の写真だけを返す（下書き・終了した商品の写真は「見つからない」）。
 * 保存してあるのは作り直した WebP だけなので、そのまま返す。変わらない内容なので ETag で 304 を返す。
 * 商品のプレビューの画面の写真だけは、プレビューの署名が合えば公開していない商品のものも返す（キャッシュに置かない）。
 */
final class MediaController
{
    // 共有のキャッシュにも 1 日置く。消した写真や非公開にした商品の写真は、最長でこの間キャッシュに残る
    private const int MAX_AGE = 86400;

    public function listing(Request $request, Catalog $catalog, ListingImageService $images, string $imageId, string $variant): Response
    {
        $tenant = Tenant::current();
        $image = $catalog->publishedImage($imageId);
        $preview = false;
        $member = false;

        // 会員だけ・ランクだけの商品（ADR 0079）: 写真の経路はセッションを持たず、いつもゲストとして読むので、見てよい会員に描いた商品の画面の署名で返す（共有のキャッシュに置かない）
        if ($image === null && $request->has('member_token')) {
            $image = $catalog->forShop()->publishedImage($imageId);
            $member = $image !== null && MemberPhotoLink::grants((string) $tenant->getTenantKey(), $image->listing_id, $request->query('member_expires'), $request->query('member_token'));
            $image = $member ? $image : null;
        }

        // プレビュー: 公開していない商品の写真は、その商品のプレビューの署名が合うときだけ（共有のキャッシュに置かない）
        if ($image === null && $request->has('token')) {
            $image = $catalog->previewImage($imageId);
            $preview = $image !== null && ListingPreviewLink::grants((string) $tenant->getTenantKey(), $image->listing_id, $request->query('expires'), $request->query('token'));
            $image = $preview ? $image : null;
        }

        abort_if($image === null, 404);

        $response = response()->noContent(200)->setEtag($image->checksum.'-'.$variant);

        if ($preview) {
            $response->setPrivate()->headers->addCacheControlDirective('no-store');
            StorefrontSecurityHeaders::withoutReferrer($request);
        } elseif ($member || ! $catalog->forEveryone($image->listing_id)) {
            // 会員だけ・ランクだけの商品の写真（ADR 0079）は、見てよい会員にだけ返し、共有のキャッシュに置かない（ゲストへ配らない）
            $response->setPrivate()->headers->addCacheControlDirective('no-store');
            StorefrontSecurityHeaders::withoutReferrer($request);
        } else {
            $response->setPublic()->setMaxAge(self::MAX_AGE);
        }

        if ($response->isNotModified($request)) {
            return $response;
        }

        $path = ListingImage::path((string) $tenant->getTenantKey(), $image->listing_id, $image->id, $variant);
        $disk = $images->disk();
        abort_unless($disk->exists($path), 404);

        return $response->setContent((string) $disk->get($path))->header('Content-Type', 'image/webp');
    }

    /**
     * ショップの写真（ADR 0028 区切り 1。ヒーローなど）。置き場に今ある写真だけを返す（消した写真の ID は「見つからない」）。
     * 写真の ID は推測できない ULID で、使っている所が無い写真も ID を知っていれば見える（ショップに出すための写真の置き場）
     */
    public function shop(Request $request, ShopMediaService $media, string $mediaId, string $variant): Response
    {
        $image = $media->find($mediaId);
        abort_if($image === null, 404);
        $tenant = Tenant::current();

        $response = response()->noContent(200)
            ->setEtag($image->checksum.'-'.$variant)
            ->setPublic()
            ->setMaxAge(self::MAX_AGE);

        if ($response->isNotModified($request)) {
            return $response;
        }

        $path = ShopMedia::path((string) $tenant->getTenantKey(), $image->id, $variant);
        $disk = $media->disk();
        abort_unless($disk->exists($path), 404);

        return $response->setContent((string) $disk->get($path))->header('Content-Type', 'image/webp');
    }
}
