<?php

declare(strict_types=1);

namespace App\Core\Media\Services;

use App\Core\Event\EventStore;
use App\Core\Media\ImageProcessor;
use App\Core\Media\InvalidImageException;
use App\Core\Media\Models\ShopMedia;
use App\Core\Theme\Models\ThemeVersion;
use App\Models\Tenant;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * ショップの写真の置き場（ADR 0028 区切り 1）: 上げる・代替テキストを変える・消す。写真の変更はここを通し、イベントを残す。
 *
 * - 受け取ったファイルはそのまま保存せず、必ず ImageProcessor で作り直す（作り直しはロックの外で先に）
 * - 書き込みは EventStore::lock() → 写真の行のロックの順（ADR 0012）。枚数の上限と「使っている写真は消さない」はこのロックの中で守る
 * - 使っている写真（テーマの区画の値〔カスタム区画の HTML の中の写真も〕・商品の説明・前の見せ方の控え）は消せない。外部キーで守れないので、消すのと同じロックの中で数える（usageMap）
 * - 途中で失敗したら、書いたファイルを消す。消した写真のファイルは、DB の変更が確定してから消す
 */
final class ShopMediaService
{
    /** 1 つの店に置ける写真の数（置き場を使い切らせない） */
    public const int MAX_PER_SHOP = 100;

    public const int MAX_ALT = 200;

    /** 代替テキストに入れない文字（制御文字と、文の向きを入れ替える印 U+202A〜202E・U+2066〜2069） */
    public const string ALT_FORBIDDEN = '/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public function __construct(
        private readonly EventStore $events,
        private readonly ImageProcessor $processor,
        private readonly MediaQuota $quota,
    ) {}

    /**
     * 新しい順
     *
     * @return Collection<int, ShopMedia>
     */
    public function all(): Collection
    {
        return ShopMedia::query()->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function find(string $id): ?ShopMedia
    {
        return ShopMedia::query()->whereKey(strtolower($id))->first();
    }

    /**
     * 写真を上げる。$within は同じトランザクションの中（ロックを持ったまま）で、作った写真を受け取って動く
     *
     * @param  (Closure(ShopMedia): void)|null  $within
     *
     * @throws InvalidImageException 写真として受け付けない・数の上限
     */
    public function add(string $path, ?string $alt = null, ?string $actor = null, ?Closure $within = null): ShopMedia
    {
        $alt = self::cleanAlt($alt);
        $tenant = $this->tenantId();
        // 作り直し（重い）の前に、1 分の回数とその日の量。作り直しはロックの外で先に済ませる
        $this->quota->admit();
        $variants = $this->processor->process($path, ImageProcessor::SHOP_VARIANTS);
        $disk = $this->disk();
        $written = [];

        try {
            return DB::transaction(function () use ($alt, $actor, $within, $tenant, $variants, $disk, &$written): ShopMedia {
                $this->events->lock();

                if (ShopMedia::query()->count() >= self::MAX_PER_SHOP) {
                    throw new InvalidImageException('ショップの写真は '.self::MAX_PER_SHOP.' 枚までです。使っていない写真を削除してからアップロードしてください。');
                }

                $bytes = array_sum(array_map(static fn ($variant): int => strlen($variant->bytes), $variants));
                $this->quota->reserve($bytes);
                $large = $variants['large'];
                $media = ShopMedia::create([
                    'alt' => $alt,
                    'width' => $large->width,
                    'height' => $large->height,
                    'checksum' => hash('sha256', $large->bytes),
                    'bytes' => $bytes,
                ]);

                foreach ($variants as $name => $variant) {
                    $file = ShopMedia::path($tenant, $media->id, $name);
                    $disk->put($file, $variant->bytes);
                    $written[] = $file;
                }

                // 代替テキストは店の人が書く文なので記録に入れず、寸法だけ
                $this->events->append('shop.media_added', 'shop_media', $media->id, ['width' => $media->width, 'height' => $media->height], $actor);

                if ($within !== null) {
                    $within($media);
                }

                return $media;
            });
        } catch (Throwable $e) {
            foreach ($written as $file) {
                $disk->delete($file);
            }

            throw $e;
        }
    }

    /**
     * @throws ModelNotFoundException 無い写真
     * @throws ValidationException 代替テキストの形が違う
     */
    public function updateAlt(string $id, ?string $alt, ?string $actor = null): ShopMedia
    {
        $alt = self::cleanAlt($alt);

        return DB::transaction(function () use ($id, $alt, $actor): ShopMedia {
            $this->events->lock();
            $media = ShopMedia::query()->whereKey(strtolower($id))->lockForUpdate()->firstOrFail();
            $media->alt = $alt;

            if ($media->isDirty('alt')) {
                $media->save();
                $this->events->append('shop.media_updated', 'shop_media', $media->id, ['changed' => ['alt']], $actor);
            }

            return $media;
        });
    }

    /**
     * 写真を消す。使っている所があれば断る（ShopMediaInUseException）
     *
     * @throws ModelNotFoundException 無い写真
     * @throws ShopMediaInUseException 使っている所がある写真
     */
    public function remove(string $id, ?string $actor = null): void
    {
        $tenant = $this->tenantId();

        DB::transaction(function () use ($id, $actor, $tenant): void {
            $this->events->lock();
            $media = ShopMedia::query()->whereKey(strtolower($id))->lockForUpdate()->firstOrFail();
            $usages = $this->usages($media->id);

            if ($usages !== []) {
                throw new ShopMediaInUseException($usages);
            }

            $media->delete();
            $this->events->append('shop.media_removed', 'shop_media', $media->id, [], $actor);

            // ファイルは DB の変更が確定してから消す（取り消されたら残す）
            DB::afterCommit(function () use ($media, $tenant): void {
                foreach (array_keys(ImageProcessor::SHOP_VARIANTS) as $name) {
                    $this->disk()->delete(ShopMedia::path($tenant, $media->id, $name));
                }
            });
        });
    }

    /**
     * この写真を使っている所（theme:テーマ:バージョン、listing:商品の ID、legacy:hero）
     *
     * @return list<string>
     */
    public function usages(string $id): array
    {
        $id = strtolower($id);

        return $this->usageMap($id)[$id] ?? [];
    }

    /**
     * 写真の ID => 使っている所。$only を渡すと、商品の説明はその写真を指すものだけを調べる（ほかの写真の分は正しくない）
     *
     * @return array<string, list<string>>
     */
    public function usageMap(?string $only = null): array
    {
        $map = [];

        // テーマの区画の値（すべてのバージョンの下書きと公開。ADR 0028 区切り 2）。外部キーで守れないので、値の中の ID を拾う。
        // 宣言を見ずに ID の形の文字と、HTML の中の写真の道筋をすべて拾う（商品やカテゴリの ID も混ざるが、写真の ID とは重ならないので害は無い。
        // テーマを外していても数える）
        foreach (ThemeVersion::query()->orderBy('theme')->orderBy('name')->get(['id', 'theme', 'name', 'draft', 'published']) as $version) {
            $ids = array_unique([...self::ulids($version->draft), ...self::ulids($version->published ?? [])]);

            foreach ($ids as $id) {
                $map[$id][] = "theme:{$version->theme}:{$version->name}";
            }
        }

        // 商品の説明の写真のブロック（ADR 0032）。消した（ゴミ箱の）商品はショップに出ないので数えない。
        // 写真のブロックのある説明だけを索引（listings_description_blocks_images）で絞り、1 枚分ならその写真を指す説明だけを開く
        $filter = (string) json_encode([$only === null ? ['type' => 'image'] : ['type' => 'image', 'media_id' => $only]]);
        $listings = DB::select(<<<'SQL'
            SELECT l.id, b->>'media_id' AS media_id
            FROM listings l, jsonb_array_elements(l.description_blocks) b
            WHERE l.deleted_at IS NULL AND l.description_blocks @> ?::jsonb AND b->>'type' = 'image'
            ORDER BY l.id
            SQL, [$filter]);

        foreach ($listings as $row) {
            $id = strtolower((string) $row->media_id);
            $usage = "listing:{$row->id}";

            if (! in_array($usage, $map[$id] ?? [], true)) {
                $map[$id][] = $usage;
            }
        }

        // 前の見せ方の控え（区切り 2c で店の設定から移したヒーロー）。区画のテーマへ写すまで消させない（消すと写したときに失われる）
        $legacy = DB::table('legacy_storefront_settings')->value('hero_media_id');

        if (is_string($legacy) && $legacy !== '') {
            $map[strtolower($legacy)][] = 'legacy:hero';
        }

        // 共有の既定の写真（ADR 0061。設定 › SEO）。外部キーでも消させないが、断る文に場所を出すため数える
        $share = DB::table('shop_seo')->value('share_image_id');

        if (is_string($share) && $share !== '') {
            $map[strtolower($share)][] = 'seo:share';
        }

        return $map;
    }

    /**
     * 値の中の写真の ID: 値の文字列まるごとが ID の形のもの（写真の欄）と、文字列の中の写真の置き場の道筋（/media/shop/{ID}。
     * カスタム区画の HTML の欄の <img src="/media/shop/{ID}/large">）。道筋は欄の種類を見ずに拾う（文の欄に道筋を書いただけでも
     * 「使っている」に数えるが、消せないほうに倒れるだけで害は無い）
     *
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private static function ulids(array $value): array
    {
        $ids = [];

        array_walk_recursive($value, static function (mixed $v) use (&$ids): void {
            if (! is_string($v)) {
                return;
            }

            if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $v) === 1) {
                $ids[] = $v;
            } elseif (stripos($v, '/media/shop/') !== false && preg_match_all('#/media/shop/([0-9a-hjkmnp-tv-z]{26})(?![0-9a-z])#i', $v, $matches) > 0) {
                foreach ($matches[1] as $id) {
                    $ids[] = strtolower($id);
                }
            }
        });

        return $ids;
    }

    public function disk(): Filesystem
    {
        return Storage::disk('media');
    }

    private function tenantId(): string
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('ショップの写真はテナント文脈の中でだけ扱う');
        }

        return (string) $tenant->getTenantKey();
    }

    /**
     * 代替テキスト: 前後の空白を除き、空は「無し」。1 行・制御文字なし（C0・DEL・C1。DB の検査と同じ範囲）・200 字まで（形が違えば ValidationException）
     */
    private static function cleanAlt(?string $alt): ?string
    {
        $alt = $alt === null ? null : trim($alt);
        $alt = $alt === '' ? null : $alt;

        Validator::make(['alt' => $alt], [
            'alt' => ['nullable', 'string', 'max:'.self::MAX_ALT, 'not_regex:'.self::ALT_FORBIDDEN],
        ], ['alt.not_regex' => '代替テキストは改行や制御文字を含まない 1 行で書いてください。'], ['alt' => '代替テキスト'])->validate();

        return $alt;
    }
}
