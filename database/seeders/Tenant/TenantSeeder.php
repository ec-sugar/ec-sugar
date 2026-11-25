<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Core\Inventory\Enums\LocationKind;
use App\Core\Inventory\Models\Location;
use App\Core\Listing\Enums\SellerStatus;
use App\Core\Listing\Models\Seller;
use App\Core\Shop\Models\ShopSettings;
use Illuminate\Database\Seeder;

/**
 * テナント DB の初期データ。テナント作成時（SeedDatabase ジョブ）と tenants:seed で実行される。冪等。
 * 単店モードが動くための最低限: 既定の出品者 1 つと、店舗の拠点 1 つと、店の設定の行（ショップは準備中）。
 */
final class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $name = (string) (tenant('name') ?? 'default');

        $seller = Seller::query()->firstOrCreate(
            ['slug' => 'default'],
            ['name' => $name, 'status' => SellerStatus::Active],
        );

        Location::query()->firstOrCreate(
            ['seller_id' => $seller->id, 'kind' => LocationKind::Store->value, 'name' => '本店'],
            ['address' => null],
        );

        // 店の設定の行。新しい店のショップは準備中（列の既定）。既に行があれば触らない（tenants:seed で公開中の店を閉じない）
        if (! ShopSettings::query()->exists()) {
            ShopSettings::create([]);
        }
    }
}
