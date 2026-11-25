<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Core\Listing\Models\Listing;
use App\Core\Media\ImageProcessor;
use App\Core\Media\InvalidImageException;
use App\Core\Media\Models\ShopMedia;
use App\Core\Media\Services\ShopMediaInUseException;
use App\Core\Media\Services\ShopMediaService;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ショップの写真の置き場（EnterTenant の後。ADR 0028 区切り 1）: 一覧・上げる・代わりの文字・消す。
 * 経路の ID はモデルに結びつけず、テナント文脈の中で探す（別の店の写真は「見つからない」）。
 */
final class ShopMediaController
{
    public function __construct(private readonly ShopMediaService $media) {}

    /**
     * ?usage=none は、使っている所を数えずに読む（商品の画面の写真の選び方。全部の商品の説明やテーマの値を開かない。ADR 0032）。
     * ほかの値は今までどおり数える
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->query('usage') === 'none') {
            return response()->json([
                'items' => $this->media->all()->map(static fn (ShopMedia $m): array => self::present($m, null))->values()->all(),
                'max' => ShopMediaService::MAX_PER_SHOP,
            ]);
        }

        $usages = $this->media->usageMap();

        return response()->json([
            'items' => $this->media->all()->map(static fn (ShopMedia $m): array => self::present($m, $usages[$m->id] ?? []))->values()->all(),
            'max' => ShopMediaService::MAX_PER_SHOP,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 大きさ（KB）の検査はここでも。形式は送られてきた種類を信じず、ImageProcessor が中身で判定する
            'image' => ['required', 'file', 'max:'.intdiv(ImageProcessor::MAX_BYTES, 1024)],
            'alt' => ['sometimes', 'nullable', 'string', 'max:'.ShopMediaService::MAX_ALT],
        ]);
        $file = $data['image'];
        assert($file instanceof UploadedFile);

        try {
            $media = $this->media->add((string) $file->getRealPath(), $data['alt'] ?? null, TenantActor::of($request));
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }

        return response()->json(['item' => self::present($media, [])], 201);
    }

    public function update(Request $request, string $mediaId): JsonResponse
    {
        $data = $request->validate(['alt' => ['present', 'nullable', 'string', 'max:'.ShopMediaService::MAX_ALT]]);
        $media = $this->media->updateAlt($mediaId, $data['alt'], TenantActor::of($request));

        return response()->json(['item' => self::present($media, $this->media->usages($media->id))]);
    }

    public function destroy(Request $request, string $mediaId): JsonResponse
    {
        try {
            $this->media->remove($mediaId, TenantActor::of($request));
        } catch (ShopMediaInUseException $e) {
            // 核の規則違反は 422（conventions 4）。使っている所を外してから消す
            // 商品の説明は商品の名前で（消した商品は数えていないので、名前が見つかる）
            $titles = Listing::query()->whereIn('id', array_map(static fn (string $u): string => substr($u, strlen('listing:')),
                array_filter($e->usages, static fn (string $u): bool => str_starts_with($u, 'listing:'))))->pluck('title', 'id')->all();
            $places = implode('・', array_map(static fn (string $usage): string => self::usageLabel($usage, $titles), $e->usages));

            throw ValidationException::withMessages(['media' => "この写真は{$places}で使われているため削除できません。先にそちらで別の写真に変更するか、写真を外してください。"]);
        }

        return $this->index($request);
    }

    /**
     * 使っている所の画面での呼び名。テーマの区画の値は theme:テーマ:バージョン、商品の説明は listing:商品の ID
     *
     * @param  array<string, string>  $titles  商品の ID => 名前
     */
    private static function usageLabel(string $usage, array $titles = []): string
    {
        if (str_starts_with($usage, 'listing:')) {
            $title = $titles[substr($usage, strlen('listing:'))] ?? null;

            return $title === null ? '商品の説明' : "商品「{$title}」の説明";
        }

        if (str_starts_with($usage, 'theme:')) {
            [, $theme, $version] = explode(':', $usage, 3) + [null, '', ''];

            return "テーマ「{$theme}」の「{$version}」";
        }

        return match ($usage) {
            'legacy:hero' => '前のデザインのバックアップ（ヒーロー。テーマを切り替えるとコピーされます）',
            // 設定 › SEO の共有の既定の写真（ADR 0061）
            'seo:share' => '設定の「SEO」の共有の写真',
            default => $usage,
        };
    }

    /**
     * 管理画面で見せる写真（この店の置き場にある写真だけ）
     */
    public function show(string $mediaId, string $variant): Response
    {
        abort_unless(array_key_exists($variant, ImageProcessor::SHOP_VARIANTS), 404);
        $media = $this->media->find($mediaId);
        abort_if($media === null, 404);
        $path = ShopMedia::path((string) Tenant::current()->getTenantKey(), $media->id, $variant);
        $disk = $this->media->disk();
        abort_unless($disk->exists($path), 404);

        return response((string) $disk->get($path), 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * @param  list<string>|null  $usages  null は数えていない（答えに used_by を入れない）
     * @return array<string, mixed>
     */
    private static function present(ShopMedia $media, ?array $usages): array
    {
        $item = [
            'id' => $media->id,
            'alt' => $media->alt,
            'width' => $media->width,
            'height' => $media->height,
            'created_at' => $media->created_at->toIso8601String(),
        ];

        return $usages === null ? $item : $item + ['used_by' => $usages];
    }
}
