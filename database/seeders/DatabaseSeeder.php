<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 中央 DB のシーダー。開発環境の初期状態を作る。
 * テナント DB のシーダーは tenants:seed で別に流す（CONCEPT 7.1: 中央からテナント DB を直接なめない）。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('production')) {
            $this->call(DemoTenantSeeder::class);
        }
    }
}
