<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Sample\Services\ShopSamples;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Models\Tenant;
use App\Storefront\Themes\ThemeSelector;
use Illuminate\Database\Seeder;
use LogicException;
use Throwable;

/**
 * 公式テーマ 3（botanica）の見本の店「MIDORI 植物店」。店頭: http://midori.localhost:8080/
 * すでに midori があれば何も変えない。開発・テスト環境でだけ動かす。
 *
 * 中身は入れたときのサンプル「植物のお店」（核の BotanicaSamples。インストーラーの --set=botanica と同じ物を二重に持たない）。
 * 写真はリポジトリに入れない。storage/app/demo/botanica/<名前>.jpg があれば通常の画像処理を通して取り込み（取りに行かない）、無ければ写真なしで作る（テストと CI）。
 * 写真の出どころは BotanicaSamples::PHOTOS（同じフォルダの README.md は前からの記録）。
 */
final class BotanicaShopSeeder extends Seeder
{
    /**
     * @param  string|null  $photoDirectory  写真のフォルダ（テストで差し替える。無ければ storage/app/demo/botanica）
     */
    public function __construct(private readonly ?string $photoDirectory = null) {}

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('植物ショップの見本は開発・テスト環境だけで作成できます。');
        }

        if (Tenant::query()->whereKey('midori')->exists()) {
            $this->command?->info('midori は既に存在するため、変更せず終了します。');

            return;
        }

        // 店の文脈に入ると storage_path() が店ごとの場所に変わるので、写真のフォルダは先に決める
        $photos = $this->photoDirectory ?? storage_path('app/demo/botanica');

        $tenant = Tenant::create(['id' => 'midori', 'name' => 'MIDORI 植物店']);
        try {
            $tenant->domains()->create(['domain' => 'midori.localhost']);
            $tenant->run(function () use ($photos): void {
                app(ShopSamples::class)->install('botanica', $photos, notice: fn (string $message, bool $warning) => $warning ? $this->command?->warn($message) : $this->command?->line($message));
                // 見本の店はすぐ見られるように公開にする（新しい店の既定は準備中）
                app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, ShopSamples::ACTOR);
            });
            app(ThemeSelector::class)->change($tenant, 'botanica', ShopSamples::ACTOR);
        } catch (Throwable $e) {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            // この実行で新規作成した見本だけを片付ける。他の店には触れない。
            $tenant->delete();
            throw $e;
        }

        $this->command?->info('MIDORI 植物店: http://midori.localhost:8080/');
    }
}
