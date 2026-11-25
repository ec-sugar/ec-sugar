<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Sample\Services\ShopSamples;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 既定テーマ（角砂糖）の見本の店「SUGAR SHOP」。店頭: http://sugar.localhost:8080/
 * 何度実行しても同じ状態になる（店を消して作り直す）。開発環境でだけ動かす。`make demo-sugar`
 *
 * 中身は入れたときのサンプル「お菓子のお店」（核の SweetsSamples。インストーラーの --set=sweets と同じ物を二重に持たない）。
 * 写真はリポジトリに入れない。storage/app/demo/sugar/<名前>.jpg があれば使い（取りに行かない）、無い写真は無しで作る（テーマは角砂糖を出す）。
 * 写真の出どころは SweetsSamples::PHOTOS（同じフォルダの credits.json は前からの記録）。
 */
final class SugarShopSeeder extends Seeder
{
    /**
     * @param  string|null  $photoDirectory  写真のフォルダ（テストで差し替える。無ければ storage/app/demo/sugar）
     */
    public function __construct(private readonly ?string $photoDirectory = null) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('見本の店は本番では作らない');
        }

        // 店の文脈に入ると storage_path() が店ごとの場所に変わるので、写真のフォルダは先に決める
        $photos = $this->photoDirectory ?? storage_path('app/demo/sugar');

        Tenant::query()->find('sugar')?->delete();
        $tenant = Tenant::create(['id' => 'sugar', 'name' => 'SUGAR SHOP']);
        $tenant->domains()->create(['domain' => 'sugar.localhost']);

        tenancy()->initialize($tenant);

        try {
            app(ShopSamples::class)->install('sweets', $photos, notice: fn (string $message, bool $warning) => $warning ? $this->command?->warn($message) : $this->command?->line($message));
            // 見本の店はすぐ見られるように公開にする（新しい店の既定は準備中）
            app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, ShopSamples::ACTOR);
        } finally {
            tenancy()->end();
        }
    }
}
