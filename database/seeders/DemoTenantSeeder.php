<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Sample\Services\SamplesRefused;
use App\Core\Sample\Services\ShopSamples;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Models\Tenant;
use App\Storefront\Themes\ThemeSelector;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * 開発用のデモテナント（テーマ fashion）。何度実行しても同じ状態になる（入れた物には印が付き、二度入れない）。
 * 店頭: http://demo.localhost:8080/
 *
 * 中身は入れたときのサンプル「布ものと道具のお店」（核の FashionSamples。インストーラーの --set=fashion と同じ物を二重に持たない）:
 * 種類（色・サイズ。ADR 0036）の見本の商品 2 つと、機能を全部使った見本の商品（帆布のトートバッグ）を 1 つ、カテゴリ・テーマの中身・送料・特商法の見本。
 * 写真はリポジトリに入れない。storage/app/demo/samples/<名前>.jpg があれば使い（取りに行かない）、無い写真は無しで作る（テストと CI。説明の写真のブロックも外す）。
 * 前の形（印の無い見本の商品）のまま残っている demo の店には入れない（品番の重なりと二重を避ける。作り直すときは店を消してから）。
 */
final class DemoTenantSeeder extends Seeder
{
    /**
     * @param  string|null  $photoDirectory  写真のフォルダ（テストで差し替える。無ければ storage/app/demo/samples）
     */
    public function __construct(private readonly ?string $photoDirectory = null) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('デモの店は本番では作らない');
        }

        // 店の文脈に入ると storage_path() が店ごとの場所に変わるので、写真のフォルダは先に決める
        $photos = $this->photoDirectory ?? storage_path('app/demo/samples');

        $tenant = Tenant::query()->find('demo');
        $created = $tenant === null;
        $tenant ??= Tenant::create(['id' => 'demo', 'name' => 'Demo アパレル']);
        $tenant->domains()->firstOrCreate(['domain' => 'demo.localhost']);

        $tenant->run(function () use ($photos): void {
            try {
                app(ShopSamples::class)->install('fashion', $photos, notice: fn (string $message, bool $warning) => $warning ? $this->command?->warn($message) : $this->command?->line($message));
            } catch (SamplesRefused $e) {
                $this->command?->warn("demo にはサンプルを入れませんでした: {$e->getMessage()}");
            }

            // 見本の店はすぐ見られるように公開にする（新しい店の既定は準備中）
            app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, ShopSamples::ACTOR);
        });

        // 新しく作った店だけテーマを fashion に（前からある店のテーマは変えない）
        if ($created) {
            app(ThemeSelector::class)->change($tenant, 'fashion', ShopSamples::ACTOR);
        }
    }
}
