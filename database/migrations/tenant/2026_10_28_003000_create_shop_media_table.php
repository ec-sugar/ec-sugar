<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ショップの写真の置き場（ADR 0028 区切り 1）。商品と関係ない写真（ヒーロー・店の紹介・キャンペーン）を店ごとに置く。
 * ファイルは作り直した WebP（ImageProcessor）を保存場所 'media' の tenants/{店}/shop/media/ に置き、ここには寸法・指紋・代わりの文字だけ。
 * 書くのは ShopMediaService だけ。
 *
 * 今のヒーローの写真（shop_settings.hero_image）はこの置き場へ移し、店の設定は置き場の行を指す（hero_media_id）。
 * 使っている写真は DB でも消せない（外部キーの RESTRICT）。
 *
 * ファイルは「写す」（元の hero-*.webp は消さない）。ファイルの操作は DB のトランザクションに入らないので、
 * 移行が途中で取り消されても元のファイルが残り、やり直せる・down で戻せる。元のファイルは店を消すときに tenants/{店}/ ごと消える。
 * 道筋と大きさの名前は、この時点のものをここに書き写す（後でアプリの定数が変わっても、この移行の意味を変えない）。
 */
return new class extends Migration
{
    private const array VARIANTS = ['large', 'medium'];

    public function up(): void
    {
        Schema::create('shop_media', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('alt', 200)->nullable();          // 代わりの文字（店の人が書く写真の説明）。個人情報は入れない
            $table->integer('width');                         // 大きい方（large）の寸法
            $table->integer('height');
            $table->char('checksum', 64);                     // large の中身の SHA-256（配るときの ETag）
            $table->timestampsTz();

            $table->index('created_at');
        });

        DB::statement('ALTER TABLE shop_media ADD CONSTRAINT shop_media_size_positive CHECK (width > 0 AND height > 0)');
        DB::statement("ALTER TABLE shop_media ADD CONSTRAINT shop_media_checksum_hex CHECK (checksum ~ '^[0-9a-f]{64}$')");
        // 制御文字は DB のロケールに頼らず範囲で書く（C0・DEL・C1。アプリの \p{Cc} と同じ範囲）
        DB::statement("ALTER TABLE shop_media ADD CONSTRAINT shop_media_alt_single_line CHECK (alt IS NULL OR (alt <> '' AND alt !~ '[\\x01-\\x1f\\x7f-\\x9f]'))");

        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->foreignUlid('hero_media_id')->nullable()->constrained('shop_media')->restrictOnDelete();
        });

        $this->moveHeroImages();

        // 公開の前の例外（ADR 0006）: ヒーローの写真を写真の置き場（shop_media）へ移した。前のバージョンへ戻すと列が無いので、公開の版からは足す → 次の版で消す
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn('hero_image'); // 形の検査（shop_settings_hero_image_shape）も一緒に消える
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->jsonb('hero_image')->nullable();
        });
        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_hero_image_shape CHECK (hero_image IS NULL OR (jsonb_typeof(hero_image) = 'object' AND (hero_image->>'id') IS NOT NULL AND (hero_image->>'checksum') ~ '^[0-9a-f]{64}$'))");

        $tenant = null;

        foreach (DB::table('shop_settings')->whereNotNull('hero_media_id')->get() as $settings) {
            $media = DB::table('shop_media')->where('id', $settings->hero_media_id)->first();

            if ($media === null) {
                continue;
            }

            $tenant ??= $this->tenantId();
            $this->copyFiles($tenant, (string) $media->id, from: 'media', to: 'hero');
            DB::table('shop_settings')->where('id', $settings->id)->update([
                'hero_image' => json_encode(['id' => $media->id, 'width' => (int) $media->width, 'height' => (int) $media->height, 'checksum' => $media->checksum]),
            ]);
        }

        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hero_media_id');
        });
        Schema::dropIfExists('shop_media');
    }

    private function moveHeroImages(): void
    {
        $tenant = null;

        foreach (DB::table('shop_settings')->whereNotNull('hero_image')->get() as $settings) {
            $image = json_decode((string) $settings->hero_image, true);

            // 形の崩れた行は黙って捨てずに止める（ヒーローの写真を知らないうちに失わない）
            if (! is_array($image) || ! is_string($image['id'] ?? null) || preg_match('/\A[0-9a-z]{26}\z/', $image['id']) !== 1
                || ! is_int($image['width'] ?? null) || ! is_int($image['height'] ?? null) || ! is_string($image['checksum'] ?? null)) {
                throw new RuntimeException("店の設定 {$settings->id} のヒーローの写真の形が崩れているので、置き場へ移せません。");
            }

            $tenant ??= $this->tenantId();
            // 写真の ID はそのまま使う（ETag と、テーマが知っている ID が変わらない）
            DB::table('shop_media')->insert([
                'id' => $image['id'],
                'alt' => null,
                'width' => $image['width'],
                'height' => $image['height'],
                'checksum' => $image['checksum'],
                'created_at' => $settings->updated_at ?? now(),
                'updated_at' => now(),
            ]);
            DB::table('shop_settings')->where('id', $settings->id)->update(['hero_media_id' => $image['id']]);
            $this->copyFiles($tenant, $image['id'], from: 'hero', to: 'media');
        }
    }

    /**
     * 元のファイルがあれば、新しい道筋へ写す（すでにあれば写さない。やり直しても同じ結果）。元が無いもの（前から欠けていた写真）は飛ばす
     *
     * @param  'hero'|'media'  $from
     * @param  'hero'|'media'  $to
     */
    private function copyFiles(string $tenant, string $id, string $from, string $to): void
    {
        $disk = Storage::disk('media');

        foreach (self::VARIANTS as $variant) {
            $source = self::path($from, $tenant, $id, $variant);
            $target = self::path($to, $tenant, $id, $variant);

            // 写し先があっても上書きで写す（前の実行が途中で落ちて書きかけが残っていても、やり直せば直る）
            if (! $disk->exists($source)) {
                continue;
            }

            if (! $disk->copy($source, $target)) {
                throw new RuntimeException("ヒーローの写真のファイル（{$source}）を写せませんでした。");
            }
        }
    }

    private static function path(string $kind, string $tenant, string $id, string $variant): string
    {
        return $kind === 'hero'
            ? "tenants/{$tenant}/shop/hero-{$id}-{$variant}.webp"
            : "tenants/{$tenant}/shop/media/{$id}-{$variant}.webp";
    }

    private function tenantId(): string
    {
        $tenant = tenant();

        // ファイルの置き場は店の ID で分かれる。店の文脈の外では移せない
        if ($tenant === null) {
            throw new LogicException('ヒーローの写真の移し替えは、店の文脈の中（tenants:migrate）で走らせる');
        }

        $id = (string) $tenant->getTenantKey();

        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $id) !== 1) {
            throw new LogicException('店の ID の形が想定と違うので、写真の置き場の外を指さないように止めた');
        }

        return $id;
    }
};
